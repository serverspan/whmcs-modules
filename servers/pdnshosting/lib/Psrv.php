<?php
/**
 * ServerSpan PowerDNS DNS Hosting - server module library
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/servers/pdnshosting/lib/Psrv.php
 *
 * Standalone (psrv_ prefix) so it never collides with the pdnsmanager addon,
 * whose files load on every WHMCS page.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Credentials come from the WHMCS server record:
 * hostname = API base URL, accesshash = API key, secure = https toggle.
 */
function psrv_api($params, $method, $path, array $payload = null)
{
    $base = rtrim(trim((string) $params['serverhostname']), '/');
    if ($base === '') {
        return [0, ['error' => 'Server hostname (API URL) is not configured']];
    }
    $serverId = isset($params['configoption5']) && $params['configoption5'] !== ''
        ? $params['configoption5'] : 'localhost';
    $ch = curl_init($base . '/api/v1/servers/' . rawurlencode($serverId) . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . (string) $params['serveraccesshash'],
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

function psrv_error($resp, $code)
{
    if (is_array($resp) && isset($resp['error'])) {
        return is_string($resp['error']) ? $resp['error'] : json_encode($resp['error']);
    }
    return 'HTTP ' . $code;
}

function psrv_zone_id($domain)
{
    return strtolower(trim($domain)) . '.';
}

function psrv_nameservers($params)
{
    $ns = [];
    for ($i = 1; $i <= 4; $i++) {
        $v = isset($params['nameserver' . $i]) ? strtolower(trim($params['nameserver' . $i])) : '';
        if ($v) {
            $ns[] = rtrim($v, '.') . '.';
        }
    }
    return $ns;
}

function psrv_create_zone($params, $domain)
{
    $kind = isset($params['configoption2']) && $params['configoption2'] === 'Master' ? 'Master' : 'Native';
    $method = isset($params['configoption3']) ? $params['configoption3'] : 'rrsets';
    $payload = [
        'name'        => psrv_zone_id($domain),
        'kind'        => $kind,
        'nameservers' => [],
    ];
    if ($method === 'nameservers') {
        $payload['nameservers'] = psrv_nameservers($params);
    } else {
        $ns = [];
        foreach (psrv_nameservers($params) as $host) {
            $ns[] = ['content' => $host, 'disabled' => false];
        }
        if ($ns) {
            $payload['rrsets'] = [[
                'name' => psrv_zone_id($domain), 'type' => 'NS', 'ttl' => 3600,
                'changetype' => 'REPLACE', 'records' => $ns,
            ]];
        }
    }
    list($code, $resp) = psrv_api($params, 'POST', '/zones', $payload);
    if ($code !== 201 && $code !== 200) {
        return [false, psrv_error($resp, $code)];
    }
    psrv_rectify($params, $domain);
    return [true, ''];
}

function psrv_delete_zone($params, $domain)
{
    list($code, $resp) = psrv_api($params, 'DELETE', '/zones/' . psrv_zone_id($domain));
    if ($code !== 204 && $code !== 200 && $code !== 404) {
        return [false, psrv_error($resp, $code)];
    }
    return [true, ''];
}

function psrv_rectify($params, $domain)
{
    $mode = isset($params['configoption4']) ? $params['configoption4'] : 'auto';
    if ($mode === 'none') {
        return;
    }
    if ($mode === 'auto' || $mode === 'post') {
        list($code) = psrv_api($params, 'POST', '/zones/' . psrv_zone_id($domain) . '/rectify');
        if ($code === 200 || $mode === 'post') {
            return;
        }
    }
    if ($mode === 'auto' || $mode === 'put') {
        psrv_api($params, 'PUT', '/zones/' . psrv_zone_id($domain), ['rectify' => true]);
    }
}

/**
 * Apply a zone template created in the pdnsmanager addon (shared tables).
 * No-op when the addon is not installed or no template id is configured.
 */
function psrv_apply_template($params, $domain)
{
    $templateId = (int) (isset($params['configoption1']) ? $params['configoption1'] : 0);
    if (!$templateId) {
        return [true, ''];
    }
    try {
        if (!Capsule::schema()->hasTable('mod_pdns_templates')) {
            return [true, ''];
        }
        $tpl = Capsule::table('mod_pdns_templates')->where('id', $templateId)->first();
    } catch (\Exception $e) {
        return [true, ''];
    }
    if (!$tpl) {
        return [false, 'Template #' . $templateId . ' not found.'];
    }

    $vars = [
        '{domain}'               => strtolower($domain),
        '{client.id}'            => isset($params['userid']) ? (int) $params['userid'] : '',
        '{server.ip}'            => isset($params['serverip']) ? trim($params['serverip']) : '',
        '{server.hostname}'      => isset($params['serverhostname']) ? trim($params['serverhostname']) : '',
        '{service.dedicated_ip}' => isset($params['dedicatedip']) ? trim($params['dedicatedip']) : '',
        '{service.assigned_ip}'  => '',
    ];

    $records = json_decode($tpl->records, true);
    if (!is_array($records) || !$records) {
        return [false, 'Template has no records.'];
    }
    $rrsets = [];
    foreach ($records as $r) {
        $type = strtoupper((string) $r['type']);
        if ($type === 'SOA' || ($type === 'NS' && (trim($r['name']) === '' || trim($r['name']) === '@'))) {
            continue;
        }
        $name = strtr((string) $r['name'], $vars);
        $zone = psrv_zone_id($domain);
        $name = strtolower(trim($name));
        if ($name === '' || $name === '@') {
            $name = $zone;
        } elseif (substr($name, -1) !== '.') {
            $name .= '.' . $zone;
        }
        $content = strtr((string) $r['content'], $vars);
        $content = trim($content);
        if (in_array($type, ['CNAME', 'NS', 'PTR'], true)) {
            $content = rtrim($content, '.') . '.';
        } elseif ($type === 'TXT') {
            $content = '"' . str_replace('"', '\\"', trim($content, '"')) . '"';
        }
        $rrsets[] = [
            'name' => $name, 'type' => $type,
            'ttl'  => max(60, (int) (isset($r['ttl']) ? $r['ttl'] : 3600)),
            'changetype' => 'REPLACE',
            'records' => [['content' => $content, 'disabled' => false]],
        ];
    }
    if (!$rrsets) {
        return [true, ''];
    }
    list($code, $resp) = psrv_api($params, 'PATCH', '/zones/' . psrv_zone_id($domain), ['rrsets' => $rrsets]);
    if ($code !== 204 && $code !== 200) {
        return [false, psrv_error($resp, $code)];
    }
    return [true, ''];
}
