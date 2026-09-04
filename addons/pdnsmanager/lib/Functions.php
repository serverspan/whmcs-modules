<?php
/**
 * ServerSpan PowerDNS Manager - shared library
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/pdnsmanager/lib/Functions.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/* ---------------------------------------------------------------- settings */

function pdns_settings($fresh = false)
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        foreach (Capsule::table('tbladdonmodules')->where('module', 'pdnsmanager')->get() as $row) {
            $cache[$row->setting] = $row->value;
        }
    }
    return $cache;
}

function pdns_setting($key, $default = '')
{
    $s = pdns_settings();
    return (isset($s[$key]) && $s[$key] !== '') ? $s[$key] : $default;
}

function pdns_nameservers()
{
    $ns = [];
    for ($i = 1; $i <= 5; $i++) {
        $v = strtolower(trim(pdns_setting('ns' . $i)));
        if ($v) {
            $ns[] = rtrim($v, '.') . '.';
        }
    }
    return $ns;
}

function pdns_protected_domains()
{
    $raw = strtolower((string) pdns_setting('protected_domains'));
    return array_filter(array_map('trim', preg_split('/[\s,]+/', $raw)));
}

/* --------------------------------------------------------------------- API */

/**
 * Call the PowerDNS API. Returns [http_code, decoded].
 */
function pdns_api($method, $path, array $payload = null)
{
    $base = rtrim(pdns_setting('api_url'), '/');
    $serverId = pdns_setting('server_id', 'localhost');
    $ch = curl_init($base . '/api/v1/servers/' . rawurlencode($serverId) . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . pdns_setting('api_key'),
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return [0, ['error' => $err]];
    }
    $decoded = json_decode((string) $body, true);
    return [$code, $decoded === null ? ['raw' => $body] : $decoded];
}

function pdns_api_error($resp, $code)
{
    if (is_array($resp) && isset($resp['error'])) {
        return is_string($resp['error']) ? $resp['error'] : json_encode($resp['error']);
    }
    return 'HTTP ' . $code;
}

/* ------------------------------------------------------------------- zones */

function pdns_zone_id($domain)
{
    return strtolower(trim($domain)) . '.';
}

function pdns_zone_exists($domain)
{
    list($code) = pdns_api('GET', '/zones/' . pdns_zone_id($domain));
    return $code === 200;
}

/**
 * Full zone object (with rrsets) or null.
 */
function pdns_get_zone($domain)
{
    list($code, $resp) = pdns_api('GET', '/zones/' . pdns_zone_id($domain));
    return $code === 200 && is_array($resp) ? $resp : null;
}

/**
 * Create a zone. Respects the zone-type and creation-method settings.
 * Returns [ok, error].
 */
function pdns_create_zone($domain)
{
    $payload = [
        'name'        => pdns_zone_id($domain),
        'kind'        => pdns_setting('zone_type', 'Native') === 'Master' ? 'Master' : 'Native',
        'nameservers' => [],
    ];
    if (pdns_setting('create_method', 'rrsets') === 'nameservers') {
        // Legacy PowerDNS (<= 4.2): nameservers list is mandatory.
        $payload['nameservers'] = pdns_nameservers();
    } else {
        // Modern: create with rrsets only.
        $ns = [];
        foreach (pdns_nameservers() as $host) {
            $ns[] = ['content' => $host, 'disabled' => false];
        }
        if ($ns) {
            $payload['rrsets'] = [[
                'name' => pdns_zone_id($domain), 'type' => 'NS', 'ttl' => 3600,
                'changetype' => 'REPLACE', 'records' => $ns,
            ]];
        }
    }
    list($code, $resp) = pdns_api('POST', '/zones', $payload);
    if ($code !== 201 && $code !== 200) {
        return [false, pdns_api_error($resp, $code)];
    }
    pdns_rectify($domain);
    return [true, ''];
}

function pdns_delete_zone($domain)
{
    list($code, $resp) = pdns_api('DELETE', '/zones/' . pdns_zone_id($domain));
    if ($code !== 204 && $code !== 200 && $code !== 404) {
        return [false, pdns_api_error($resp, $code)];
    }
    Capsule::table('mod_pdns_zones')->where('domain', strtolower($domain))->delete();
    return [true, ''];
}

/**
 * Apply a set of rrsets changes in ONE batched PATCH, then rectify when the
 * zone is DNSSEC-signed.
 */
function pdns_patch_rrsets($domain, array $rrsets)
{
    list($code, $resp) = pdns_api('PATCH', '/zones/' . pdns_zone_id($domain), ['rrsets' => $rrsets]);
    if ($code !== 204 && $code !== 200) {
        return [false, pdns_api_error($resp, $code)];
    }
    $zone = pdns_get_zone($domain);
    if ($zone && !empty($zone['dnssec'])) {
        pdns_rectify($domain);
    }
    return [true, ''];
}

/**
 * Rectify a zone. Mode: auto (try POST, fall back to PUT), post, put, none.
 */
function pdns_rectify($domain)
{
    $mode = pdns_setting('rectify_mode', 'auto');
    if ($mode === 'none') {
        return;
    }
    if ($mode === 'auto' || $mode === 'post') {
        list($code) = pdns_api('POST', '/zones/' . pdns_zone_id($domain) . '/rectify');
        if ($code === 200 || $mode === 'post') {
            return;
        }
    }
    if ($mode === 'auto' || $mode === 'put') {
        pdns_api('PUT', '/zones/' . pdns_zone_id($domain), ['rectify' => true]);
    }
}

/* ----------------------------------------------------------------- records */

function pdns_normalize_name($name, $domain)
{
    $name = strtolower(trim($name));
    $zone = pdns_zone_id($domain);
    if ($name === '' || $name === '@' || $name === $zone || $name === rtrim($zone, '.')) {
        return $zone;
    }
    if (substr($name, -1) === '.') {
        return $name;
    }
    return $name . '.' . $zone;
}

/**
 * Flatten zone rrsets into display rows: [name, type, ttl, content, is_soa, is_apex_ns].
 */
function pdns_zone_records($zone)
{
    $rows = [];
    $apex = isset($zone['name']) ? $zone['name'] : '';
    foreach ($zone['rrsets'] as $rrset) {
        foreach ($rrset['records'] as $rec) {
            if (!empty($rec['disabled'])) {
                continue;
            }
            $rows[] = [
                'name'       => $rrset['name'],
                'type'       => $rrset['type'],
                'ttl'        => $rrset['ttl'],
                'content'    => $rec['content'],
                'is_soa'     => $rrset['type'] === 'SOA',
                'is_apex_ns' => $rrset['type'] === 'NS' && $rrset['name'] === $apex,
            ];
        }
    }
    usort($rows, function ($a, $b) {
        return strcmp($a['name'] . $a['type'] . $a['content'], $b['name'] . $b['type'] . $b['content']);
    });
    return $rows;
}

/**
 * Replace the full record set for name+type with the given values
 * (multi-value safe editing). Empty $values deletes the set.
 */
function pdns_save_rrset($domain, $name, $type, $ttl, array $values)
{
    $name = pdns_normalize_name($name, $domain);
    $ttl  = max(60, (int) $ttl);
    if (!$values) {
        return pdns_patch_rrsets($domain, [[
            'name' => $name, 'type' => $type, 'changetype' => 'DELETE', 'records' => [],
        ]]);
    }
    $records = [];
    foreach ($values as $v) {
        $records[] = ['content' => pdns_format_content($type, $v, $domain), 'disabled' => false];
    }
    return pdns_patch_rrsets($domain, [[
        'name' => $name, 'type' => $type, 'ttl' => $ttl,
        'changetype' => 'REPLACE', 'records' => $records,
    ]]);
}

/**
 * Type-specific content formatting (trailing dots, TXT quoting).
 */
function pdns_format_content($type, $content, $domain)
{
    $content = trim($content);
    switch ($type) {
        case 'CNAME':
        case 'NS':
        case 'PTR':
            return rtrim($content, '.') === '@' ? pdns_zone_id($domain) : rtrim($content, '.') . '.';
        case 'MX':
            $p = preg_split('/\s+/', $content, 2);
            if (count($p) === 2) {
                return (int) $p[0] . ' ' . rtrim(trim($p[1]), '.') . '.';
            }
            return $content;
        case 'SRV':
            $p = preg_split('/\s+/', $content);
            if (count($p) === 4) {
                return (int) $p[0] . ' ' . (int) $p[1] . ' ' . (int) $p[2] . ' ' . rtrim($p[3], '.') . '.';
            }
            return $content;
        case 'TXT':
            $content = trim($content, '"');
            return '"' . str_replace('"', '\\"', $content) . '"';
        default:
            return $content;
    }
}

function pdns_allowed_record_types()
{
    return ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS', 'TLSA', 'PTR'];
}

/* ------------------------------------------------------------------ DNSSEC */

function pdns_dnssec_enable($domain)
{
    list($code, $resp) = pdns_api('POST', '/zones/' . pdns_zone_id($domain) . '/cryptokeys', [
        'keytype' => 'csk', 'active' => true,
    ]);
    if ($code !== 201 && $code !== 200) {
        return [false, pdns_api_error($resp, $code)];
    }
    pdns_rectify($domain);
    return [true, ''];
}

function pdns_dnssec_disable($domain)
{
    list($code, $keys) = pdns_api('GET', '/zones/' . pdns_zone_id($domain) . '/cryptokeys');
    if ($code !== 200 || !is_array($keys)) {
        return [false, pdns_api_error($keys, $code)];
    }
    foreach ($keys as $key) {
        if (empty($key['active'])) {
            continue;
        }
        pdns_api('PUT', '/zones/' . pdns_zone_id($domain) . '/cryptokeys/' . (int) $key['id'], ['active' => false]);
        pdns_api('DELETE', '/zones/' . pdns_zone_id($domain) . '/cryptokeys/' . (int) $key['id']);
    }
    return [true, ''];
}

/**
 * DS records for registrar setup (from the active CSK).
 */
function pdns_ds_records($domain)
{
    list($code, $keys) = pdns_api('GET', '/zones/' . pdns_zone_id($domain) . '/cryptokeys');
    $ds = [];
    if ($code === 200 && is_array($keys)) {
        foreach ($keys as $key) {
            if (!empty($key['active']) && !empty($key['ds']) && is_array($key['ds'])) {
                foreach ($key['ds'] as $line) {
                    $ds[] = $line;
                }
            }
        }
    }
    return $ds;
}

/* ------------------------------------------------------------- import/export */

/**
 * Parse BIND/RFC1035 zone text into rrsets. Handles $ORIGIN, @, comments,
 * and parenthesised multi-line values (long DKIM TXT). SOA and apex NS are
 * skipped (protected). Returns [rrsets, skipped_count].
 */
function pdns_parse_zonefile($text, $domain)
{
    $zone = pdns_zone_id($domain);
    $origin = $zone;
    $lastName = $zone;
    $sets = [];
    $skipped = 0;

    // Join parenthesised continuations and strip comments.
    $logical = [];
    $buffer = '';
    foreach (preg_split('/\r?\n/', (string) $text) as $line) {
        $line = preg_replace('/;.*/', '', $line);
        $buffer .= ($buffer === '' ? $line : ' ' . trim($line));
        $open  = substr_count($buffer, '(');
        $close = substr_count($buffer, ')');
        if ($open <= $close) {
            $buffer = str_replace(['(', ')'], ' ', $buffer);
            $logical[] = $buffer;
            $buffer = '';
        }
    }

    foreach ($logical as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^\$ORIGIN\s+(\S+)/i', $line, $m)) {
            $origin = rtrim(strtolower($m[1]), '.') . '.';
            continue;
        }
        if (preg_match('/^\$TTL\s+\d+/i', $line)) {
            continue;
        }
        // name [ttl] [IN] TYPE data — name may be absent (same as previous).
        if (!preg_match('/^(\S+)?\s*(?:(\d+)\s+)?(?:IN\s+)?(A|AAAA|CNAME|MX|TXT|SRV|CAA|NS|TLSA|PTR|SOA)\s+(.+)$/i', $line, $m)) {
            $skipped++;
            continue;
        }
        $type = strtoupper($m[3]);
        $rawName = $m[1] !== '' ? strtolower($m[1]) : $lastName;
        $ttl  = $m[2] !== '' ? (int) $m[2] : 3600;
        $data = trim($m[4]);

        if ($rawName === '@') {
            $name = $origin;
        } elseif (substr($rawName, -1) === '.') {
            $name = $rawName;
        } else {
            $name = $rawName . '.' . $origin;
        }
        $lastName = $name;

        if ($type === 'SOA' || ($type === 'NS' && $name === $zone)) {
            $skipped++;
            continue;
        }
        $key = $name . '|' . $type;
        if (!isset($sets[$key])) {
            $sets[$key] = ['name' => $name, 'type' => $type, 'ttl' => $ttl, 'records' => []];
        }
        $sets[$key]['records'][] = ['content' => pdns_format_content($type, $data, $domain), 'disabled' => false];
    }

    $rrsets = [];
    foreach ($sets as $s) {
        $s['changetype'] = 'REPLACE';
        $rrsets[] = $s;
    }
    return [$rrsets, $skipped];
}

/**
 * Render a zone as BIND-format text.
 */
function pdns_export_zonefile($zone)
{
    $out = '$ORIGIN ' . $zone['name'] . "\n\$TTL 3600\n\n";
    foreach ($zone['rrsets'] as $rrset) {
        foreach ($rrset['records'] as $rec) {
            if (!empty($rec['disabled'])) {
                continue;
            }
            $out .= str_pad($rrset['name'], 30) . ' ' . $rrset['ttl'] . ' IN '
                . $rrset['type'] . ' ' . $rec['content'] . "\n";
        }
    }
    return $out;
}

/* --------------------------------------------------------- nameserver check */

/**
 * Compare live NS records against the module configuration via DNS-over-HTTPS.
 * Returns [status, live_ns]: match | mismatch | error.
 */
function pdns_ns_check($domain)
{
    $provider = pdns_setting('doh_provider', 'google');
    if ($provider === 'cloudflare') {
        $url = 'https://cloudflare-dns.com/dns-query?name=' . urlencode($domain) . '&type=NS';
        $headers = ['Accept: application/dns-json'];
    } else {
        $url = 'https://dns.google/resolve?name=' . urlencode($domain) . '&type=NS';
        $headers = ['Accept: application/json'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode((string) $body, true);
    if ($code !== 200 || !is_array($data) || !isset($data['Answer'])) {
        return ['error', []];
    }
    $live = [];
    foreach ($data['Answer'] as $ans) {
        if ((int) $ans['type'] === 2) {
            $live[] = strtolower(rtrim($ans['data'], '.'));
        }
    }
    $expected = array_map(function ($n) { return strtolower(rtrim($n, '.')); }, pdns_nameservers());
    sort($live);
    sort($expected);
    return [$live === $expected ? 'match' : 'mismatch', $live];
}

/* --------------------------------------------------------------- templates */

/**
 * Apply a zone template to a domain in one batched PATCH (idempotent via
 * the template_applied flag). $context may carry service/server values for
 * variable substitution.
 */
function pdns_apply_template($templateId, $domain, array $context = [])
{
    $tpl = Capsule::table('mod_pdns_templates')->where('id', (int) $templateId)->first();
    if (!$tpl) {
        return [false, 'Template not found.'];
    }
    $zoneRow = Capsule::table('mod_pdns_zones')->where('domain', strtolower($domain))->first();
    if ($zoneRow && $zoneRow->template_applied) {
        return [true, ''];
    }

    $vars = array_merge([
        '{domain}'                 => strtolower($domain),
        '{client.id}'              => '',
        '{server.ip}'              => '',
        '{server.hostname}'        => '',
        '{service.dedicated_ip}'   => '',
        '{service.assigned_ip}'    => '',
    ], $context);

    $records = json_decode($tpl->records, true);
    if (!is_array($records) || !$records) {
        return [false, 'Template has no records.'];
    }
    $rrsets = [];
    foreach ($records as $r) {
        $type = strtoupper((string) $r['type']);
        if ($type === 'SOA' || ($type === 'NS' && (trim($r['name']) === '' || trim($r['name']) === '@'))) {
            continue; // apex SOA/NS guard at apply time
        }
        if (!in_array($type, pdns_allowed_record_types(), true)) {
            continue;
        }
        $name = strtr((string) $r['name'], $vars);
        $content = strtr((string) $r['content'], $vars);
        $rrsets[] = [
            'name' => pdns_normalize_name($name, $domain),
            'type' => $type,
            'ttl'  => max(60, (int) (isset($r['ttl']) ? $r['ttl'] : 3600)),
            'changetype' => 'REPLACE',
            'records' => [['content' => pdns_format_content($type, $content, $domain), 'disabled' => false]],
        ];
    }
    if (!$rrsets) {
        return [false, 'Template produced no applicable records.'];
    }
    list($ok, $err) = pdns_patch_rrsets($domain, $rrsets);
    if (!$ok) {
        return [false, $err];
    }
    Capsule::table('mod_pdns_zones')->updateOrInsert(
        ['domain' => strtolower($domain)],
        ['template_applied' => 1, 'clientid' => isset($context['{client.id}']) ? (int) $context['{client.id}'] : 0,
         'created_at' => date('Y-m-d H:i:s')]
    );
    return [true, ''];
}

/**
 * Pick the most specific template for a domain/service: product match wins
 * over TLD; longest TLD suffix wins among TLD matches.
 */
function pdns_template_for($domain, $productId = 0)
{
    if ($productId) {
        $row = Capsule::table('mod_pdns_assignments')
            ->where('match_type', 'product')->where('match_value', (string) $productId)
            ->orderBy('id', 'desc')->first();
        if ($row) {
            return (int) $row->template_id;
        }
    }
    $domain = strtolower($domain);
    $best = null;
    foreach (Capsule::table('mod_pdns_assignments')->where('match_type', 'tld')->get() as $row) {
        $tld = strtolower(trim($row->match_value));
        if ($tld !== '' && substr($domain, -strlen('.' . $tld)) === '.' . $tld) {
            if ($best === null || strlen($tld) > strlen($best->match_value)) {
                $best = $row;
            }
        }
    }
    return $best ? (int) $best->template_id : 0;
}

/* ------------------------------------------------------------------- misc */

function pdns_current_client_id()
{
    $uid = (int) \WHMCS\Session::get('uid');
    if (!$uid && !empty($_SESSION['uid'])) {
        $uid = (int) $_SESSION['uid'];
    }
    if (!$uid) {
        return 0;
    }
    $link = Capsule::table('tblusers_clients')->where('auth_user_id', $uid)->where('owner', 1)->first();
    if (!$link) {
        $link = Capsule::table('tblusers_clients')->where('auth_user_id', $uid)->first();
    }
    return $link ? (int) $link->client_id : 0;
}

/**
 * Domains owned by a client (with their status), for zone ownership checks.
 */
function pdns_client_domains($clientid)
{
    return Capsule::table('tbldomains')->where('userid', (int) $clientid)
        ->orderBy('domain')->pluck('status', 'domain')->all();
}

function pdns_domain_owner($domain)
{
    $row = Capsule::table('tbldomains')->where('domain', strtolower($domain))->first();
    return $row ? (int) $row->userid : 0;
}

function pdns_log($action, $domain, $detail, $actor)
{
    Capsule::table('mod_pdns_log')->insert([
        'action'     => $action,
        'domain'     => strtolower((string) $domain),
        'detail'     => is_string($detail) ? $detail : json_encode($detail),
        'actor'      => $actor,
        'ip'         => isset($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}
