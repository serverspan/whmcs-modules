<?php
/**
 * ServerSpan PowerDNS Manager
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/pdnsmanager/pdnsmanager.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Functions.php';

define('PDNS_VERSION', '1.0.0');
define('PDNS_PER_PAGE', 25);

function pdnsmanager_config()
{
    return [
        'name'        => 'ServerSpan PowerDNS Manager',
        'description' => 'Self-service DNS zone management for client domains on PowerDNS: '
            . 'record editor, DNSSEC, zone import/export, zone templates and registration lifecycle automation.',
        'author'      => 'ServerSpan',
        'language'    => 'english',
        'version'     => PDNS_VERSION,
        'fields'      => [
            'api_url' => [
                'FriendlyName' => 'PowerDNS API URL', 'Type' => 'text', 'Size' => '60',
                'Default' => 'http://127.0.0.1:8081',
                'Description' => 'Base URL of the PowerDNS authoritative server API.',
            ],
            'api_key' => [
                'FriendlyName' => 'API Key', 'Type' => 'password', 'Size' => '60',
            ],
            'server_id' => [
                'FriendlyName' => 'Server ID', 'Type' => 'text', 'Size' => '20', 'Default' => 'localhost',
            ],
            'zone_type' => [
                'FriendlyName' => 'Zone Type', 'Type' => 'dropdown',
                'Options' => 'Native,Master', 'Default' => 'Native',
                'Description' => 'Use Master when slaves AXFR from this server.',
            ],
            'create_method' => [
                'FriendlyName' => 'Zone Creation Method', 'Type' => 'dropdown',
                'Options' => 'rrsets,nameservers', 'Default' => 'rrsets',
                'Description' => 'rrsets = PowerDNS 4.3+. nameservers = legacy 4.2 and below.',
            ],
            'rectify_mode' => [
                'FriendlyName' => 'Rectify Mode', 'Type' => 'dropdown',
                'Options' => 'auto,post,put,none', 'Default' => 'auto',
                'Description' => 'How zones are rectified after changes (needed for DNSSEC).',
            ],
            'ns1' => ['FriendlyName' => 'Nameserver 1', 'Type' => 'text', 'Size' => '40'],
            'ns2' => ['FriendlyName' => 'Nameserver 2', 'Type' => 'text', 'Size' => '40'],
            'ns3' => ['FriendlyName' => 'Nameserver 3 (optional)', 'Type' => 'text', 'Size' => '40'],
            'ns4' => ['FriendlyName' => 'Nameserver 4 (optional)', 'Type' => 'text', 'Size' => '40'],
            'ns5' => ['FriendlyName' => 'Nameserver 5 (optional)', 'Type' => 'text', 'Size' => '40'],
            'doh_provider' => [
                'FriendlyName' => 'NS Check Provider', 'Type' => 'dropdown',
                'Options' => 'google,cloudflare', 'Default' => 'google',
                'Description' => 'DNS-over-HTTPS resolver used for nameserver verification.',
            ],
            'protected_domains' => [
                'FriendlyName' => 'Protected Domains', 'Type' => 'textarea', 'Rows' => '3', 'Cols' => '60',
                'Description' => 'Space/comma separated. Clients cannot manage DNS for these domains.',
            ],
            'enable_dnssec' => [
                'FriendlyName' => 'Enable DNSSEC Features', 'Type' => 'yesno',
                'Description' => 'Requires gmysql-dnssec=yes (or equivalent backend flag) in pdns.conf.',
            ],
            'auto_create_on_register' => [
                'FriendlyName' => 'Create Zone on Registration', 'Type' => 'yesno',
                'Description' => 'Create a DNS zone (and apply the matching template) when a domain registers.',
            ],
            'auto_create_on_transfer' => [
                'FriendlyName' => 'Create Zone on Transfer', 'Type' => 'yesno',
            ],
            'delete_on_termination' => [
                'FriendlyName' => 'Delete Zone on Domain Deletion', 'Type' => 'yesno',
            ],
        ],
    ];
}

function pdnsmanager_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_pdns_zones')) {
            Capsule::schema()->create('mod_pdns_zones', function ($table) {
                $table->increments('id');
                $table->string('domain', 190)->unique();
                $table->unsignedInteger('clientid')->default(0)->index();
                $table->boolean('template_applied')->default(false);
                $table->dateTime('created_at');
            });
        }
        if (!Capsule::schema()->hasTable('mod_pdns_templates')) {
            Capsule::schema()->create('mod_pdns_templates', function ($table) {
                $table->increments('id');
                $table->string('name', 100);
                $table->text('records'); // JSON: [{name,type,ttl,content}]
                $table->dateTime('created_at');
            });
        }
        if (!Capsule::schema()->hasTable('mod_pdns_assignments')) {
            Capsule::schema()->create('mod_pdns_assignments', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('template_id')->index();
                $table->enum('match_type', ['tld', 'product']);
                $table->string('match_value', 100);
                $table->dateTime('created_at');
            });
        }
        if (!Capsule::schema()->hasTable('mod_pdns_log')) {
            Capsule::schema()->create('mod_pdns_log', function ($table) {
                $table->increments('id');
                $table->string('action', 40);
                $table->string('domain', 190)->default('')->index();
                $table->text('detail')->nullable();
                $table->string('actor', 20)->default('system');
                $table->string('ip', 45)->default('');
                $table->dateTime('created_at')->index();
            });
        }
        return ['status' => 'success', 'description' => 'Tables created. Configure the PowerDNS API '
            . 'endpoint, key and nameservers below.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

function pdnsmanager_deactivate()
{
    // Zones in PowerDNS and all mod_pdns_* tables are preserved.
    return ['status' => 'success', 'description' => 'Module deactivated. Zones and tables were preserved.'];
}

/* ============================================================ admin area */

function pdnsmanager_output($vars)
{
    $modulelink = $vars['modulelink'];
    $msg = '';
    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pdns_do'])) {
        if (function_exists('check_token') && !check_token('WHMCS.admin.default')) {
            $err = 'Invalid CSRF token.';
        } else {
            list($msg, $err) = pdns_admin_handle_post();
        }
    }

    $tab = isset($_GET['pdns_tab']) ? preg_replace('/[^a-z]/', '', $_GET['pdns_tab']) : 'zones';
    $tabs = ['zones' => 'Zones', 'templates' => 'Templates', 'log' => 'Log'];

    echo '<h2>ServerSpan PowerDNS Manager <small>v' . PDNS_VERSION . '</small></h2>';
    if ($msg) {
        echo '<div class="alert alert-success">' . htmlspecialchars($msg) . '</div>';
    }
    if ($err) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($err) . '</div>';
    }
    echo '<ul class="nav nav-tabs" style="margin-bottom:20px">';
    foreach ($tabs as $key => $label) {
        $active = $tab === $key ? ' class="active"' : '';
        echo '<li' . $active . '><a href="' . $modulelink . '&pdns_tab=' . $key . '">' . $label . '</a></li>';
    }
    echo '</ul>';

    switch ($tab) {
        case 'templates':
            pdns_admin_templates($modulelink);
            break;
        case 'log':
            pdns_admin_log($modulelink);
            break;
        default:
            pdns_admin_zones($modulelink);
    }

    echo '<p class="text-muted" style="margin-top:20px">Developed by '
        . '<a href="https://www.serverspan.com/en/tools/index" target="_blank">ServerSpan</a>.</p>';
}

function pdns_admin_handle_post()
{
    $do = $_POST['pdns_do'];
    $now = date('Y-m-d H:i:s');
    switch ($do) {
        case 'create_zone':
            $domain = strtolower(trim((string) $_POST['domain']));
            if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
                return ['', 'Invalid domain name.'];
            }
            list($ok, $e) = pdns_create_zone($domain);
            if (!$ok) {
                return ['', $e];
            }
            Capsule::table('mod_pdns_zones')->updateOrInsert(
                ['domain' => $domain],
                ['clientid' => pdns_domain_owner($domain), 'created_at' => $now]
            );
            pdns_log('zone_created', $domain, 'manual', 'admin');
            return ['Zone created for ' . $domain . '.', ''];

        case 'delete_zone':
            $domain = strtolower(trim((string) $_POST['domain']));
            list($ok, $e) = pdns_delete_zone($domain);
            if (!$ok) {
                return ['', $e];
            }
            pdns_log('zone_deleted', $domain, 'manual', 'admin');
            return ['Zone deleted for ' . $domain . '.', ''];

        case 'save_template':
            // Parse pipe-delimited lines: name|type|ttl|content
            $records = [];
            foreach (preg_split('/\r?\n/', (string) (isset($_POST['records_text']) ? $_POST['records_text'] : '')) as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '#') === 0) {
                    continue;
                }
                $parts = array_map('trim', explode('|', $line, 4));
                if (count($parts) !== 4) {
                    continue;
                }
                list($rName, $rType, $rTtl, $rContent) = $parts;
                $rType = strtoupper($rType);
                if ($rContent === '' || !in_array($rType, pdns_allowed_record_types(), true)) {
                    continue;
                }
                $records[] = [
                    'name'    => $rName,
                    'type'    => $rType,
                    'ttl'     => max(60, (int) $rTtl),
                    'content' => $rContent,
                ];
            }
            $name = trim((string) $_POST['tpl_name']);
            if (!$name || !$records) {
                return ['', 'Template name and at least one valid record are required.'];
            }
            if (!empty($_POST['tpl_id'])) {
                Capsule::table('mod_pdns_templates')->where('id', (int) $_POST['tpl_id'])
                    ->update(['name' => $name, 'records' => json_encode($records)]);
                return ['Template updated.', ''];
            }
            Capsule::table('mod_pdns_templates')->insert([
                'name' => $name, 'records' => json_encode($records), 'created_at' => $now,
            ]);
            return ['Template created.', ''];

        case 'delete_template':
            Capsule::table('mod_pdns_templates')->where('id', (int) $_POST['id'])->delete();
            Capsule::table('mod_pdns_assignments')->where('template_id', (int) $_POST['id'])->delete();
            return ['Template deleted.', ''];

        case 'assign_template':
            $matchType = $_POST['match_type'] === 'product' ? 'product' : 'tld';
            $matchValue = strtolower(trim((string) $_POST['match_value']));
            if (!$matchValue) {
                return ['', 'Match value is required.'];
            }
            Capsule::table('mod_pdns_assignments')->insert([
                'template_id' => (int) $_POST['template_id'],
                'match_type'  => $matchType,
                'match_value' => $matchValue,
                'created_at'  => $now,
            ]);
            return ['Assignment added.', ''];

        case 'delete_assignment':
            Capsule::table('mod_pdns_assignments')->where('id', (int) $_POST['id'])->delete();
            return ['Assignment removed.', ''];
    }
    return ['', ''];
}

function pdns_token()
{
    return function_exists('generate_token') ? generate_token() : '';
}

function pdns_pager($modulelink, $tab, $total, $page)
{
    $pages = max(1, (int) ceil($total / PDNS_PER_PAGE));
    if ($pages <= 1) {
        return;
    }
    echo '<ul class="pagination">';
    for ($p = 1; $p <= $pages; $p++) {
        $active = $p === $page ? ' class="active"' : '';
        echo '<li' . $active . '><a href="' . $modulelink . '&pdns_tab=' . $tab . '&page=' . $p . '">'
            . $p . '</a></li>';
    }
    echo '</ul>';
}

function pdns_admin_zones($modulelink)
{
    $page  = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
    $total = Capsule::table('mod_pdns_zones')->count();
    $rows  = Capsule::table('mod_pdns_zones')->orderBy('domain')
        ->offset(($page - 1) * PDNS_PER_PAGE)->limit(PDNS_PER_PAGE)->get();

    echo '<div class="row"><div class="col-md-8">';
    echo '<form method="post" action="' . $modulelink . '" class="form-inline well">';
    echo pdns_token();
    echo '<input type="hidden" name="pdns_do" value="create_zone">';
    echo '<input type="text" name="domain" class="form-control" placeholder="example.com" required> ';
    echo '<button class="btn btn-primary">Create Zone</button></form></div>';
    echo '<div class="col-md-4"><p class="text-muted pull-right" style="margin-top:10px">'
        . 'SOA and default NS records are protected from client modification.</p></div></div>';

    echo '<table class="table table-striped"><thead><tr>'
        . '<th>Domain</th><th>Client</th><th>Template</th><th>Created</th><th>Actions</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $client = $row->clientid
            ? '<a href="clientssummary.php?userid=' . (int) $row->clientid . '">#' . (int) $row->clientid . '</a>'
            : '-';
        echo '<tr><td><a href="' . $modulelink . '&pdns_tab=zones&manage=' . urlencode($row->domain) . '">'
            . htmlspecialchars($row->domain) . '</a></td><td>' . $client . '</td>'
            . '<td>' . ($row->template_applied ? '<span class="label label-success">Applied</span>'
                : '<span class="label label-default">-</span>') . '</td>'
            . '<td>' . htmlspecialchars($row->created_at) . '</td><td>';
        echo '<a class="btn btn-xs btn-default" href="' . $modulelink . '&pdns_tab=zones&manage='
            . urlencode($row->domain) . '">Records</a> ';
        echo '<form method="post" action="' . $modulelink . '" style="display:inline" '
            . 'onsubmit="return confirm(\'Delete the zone for ' . htmlspecialchars($row->domain)
            . ' from PowerDNS?\')">';
        echo pdns_token();
        echo '<input type="hidden" name="pdns_do" value="delete_zone">';
        echo '<input type="hidden" name="domain" value="' . htmlspecialchars($row->domain) . '">';
        echo '<button class="btn btn-xs btn-danger">Delete</button></form>';
        echo '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="5" class="text-center text-muted">No zones registered. '
            . 'Zones appear here when created above or via domain registration hooks.</td></tr>';
    }
    echo '</tbody></table>';
    pdns_pager($modulelink, 'zones', $total, $page);

    // Inline record management for a selected zone.
    if (!empty($_GET['manage'])) {
        $domain = strtolower(trim((string) $_GET['manage']));
        $zone = pdns_get_zone($domain);
        if (!$zone) {
            echo '<div class="alert alert-danger">Zone ' . htmlspecialchars($domain)
                . ' not found on the PowerDNS server.</div>';
            return;
        }
        pdns_render_record_editor($modulelink, $domain, $zone, true);
    }
}

function pdns_admin_templates($modulelink)
{
    $templates = Capsule::table('mod_pdns_templates')->orderBy('name')->get();
    $assignments = Capsule::table('mod_pdns_assignments')->orderBy('id', 'desc')->get();

    echo '<h4>Zone Templates</h4>';
    echo '<table class="table table-striped"><thead><tr><th>Name</th><th>Records</th><th>Actions</th></tr></thead><tbody>';
    foreach ($templates as $tpl) {
        $count = count((array) json_decode($tpl->records, true));
        echo '<tr><td>' . htmlspecialchars($tpl->name) . '</td><td>' . $count . '</td><td>';
        echo '<form method="post" action="' . $modulelink . '" style="display:inline" '
            . 'onsubmit="return confirm(\'Delete this template?\')">';
        echo pdns_token();
        echo '<input type="hidden" name="pdns_do" value="delete_template">';
        echo '<input type="hidden" name="id" value="' . (int) $tpl->id . '">';
        echo '<button class="btn btn-xs btn-danger">Delete</button></form></td></tr>';
    }
    if (!$templates->count()) {
        echo '<tr><td colspan="3" class="text-center text-muted">No templates yet.</td></tr>';
    }
    echo '</tbody></table>';

    echo '<div class="panel panel-default"><div class="panel-heading"><strong>New Template</strong></div>'
        . '<div class="panel-body">';
    echo '<form method="post" action="' . $modulelink . '">' . pdns_token();
    echo '<input type="hidden" name="pdns_do" value="save_template">';
    echo '<div class="form-group"><label>Template Name</label>'
        . '<input type="text" name="tpl_name" class="form-control" required></div>';
    echo '<p class="text-muted">One record per line: <code>name|type|ttl|content</code>. '
        . 'Variables: {domain}, {client.id}, {server.ip}, {server.hostname}, '
        . '{service.dedicated_ip}, {service.assigned_ip}. Apex SOA/NS entries are ignored.</p>';
    echo '<div class="form-group"><label>Records</label>'
        . '<textarea name="records_text" class="form-control" rows="8" '
        . 'placeholder="@|A|3600|{server.ip}&#10;www|CNAME|3600|{domain}&#10;@|MX|3600|10 mail.{domain}"></textarea></div>';
    echo '<button class="btn btn-primary">Create Template</button></form></div></div>';

    echo '<h4>Assignments</h4>';
    echo '<p class="text-muted">Product matches win over TLD; among TLD matches the longest suffix wins.</p>';
    echo '<table class="table table-striped"><thead><tr>'
        . '<th>Template</th><th>Match Type</th><th>Match Value</th><th>Actions</th></tr></thead><tbody>';
    foreach ($assignments as $a) {
        $tpl = Capsule::table('mod_pdns_templates')->where('id', $a->template_id)->first();
        echo '<tr><td>' . htmlspecialchars($tpl ? $tpl->name : '#' . $a->template_id) . '</td>'
            . '<td>' . htmlspecialchars($a->match_type) . '</td>'
            . '<td>' . htmlspecialchars($a->match_value) . '</td><td>';
        echo '<form method="post" action="' . $modulelink . '" style="display:inline">';
        echo pdns_token();
        echo '<input type="hidden" name="pdns_do" value="delete_assignment">';
        echo '<input type="hidden" name="id" value="' . (int) $a->id . '">';
        echo '<button class="btn btn-xs btn-danger">Remove</button></form></td></tr>';
    }
    if (!$assignments->count()) {
        echo '<tr><td colspan="4" class="text-center text-muted">No assignments.</td></tr>';
    }
    echo '</tbody></table>';

    if ($templates->count()) {
        echo '<form method="post" action="' . $modulelink . '" class="form-inline well">' . pdns_token();
        echo '<input type="hidden" name="pdns_do" value="assign_template">';
        echo '<select name="template_id" class="form-control">';
        foreach ($templates as $tpl) {
            echo '<option value="' . (int) $tpl->id . '">' . htmlspecialchars($tpl->name) . '</option>';
        }
        echo '</select> ';
        echo '<select name="match_type" class="form-control">'
            . '<option value="tld">TLD</option><option value="product">Product ID</option></select> ';
        echo '<input type="text" name="match_value" class="form-control" placeholder="com or 12" required> ';
        echo '<button class="btn btn-default">Assign</button></form>';
    }
}

function pdns_admin_log($modulelink)
{
    $page  = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
    $total = Capsule::table('mod_pdns_log')->count();
    $rows  = Capsule::table('mod_pdns_log')->orderBy('id', 'desc')
        ->offset(($page - 1) * PDNS_PER_PAGE)->limit(PDNS_PER_PAGE)->get();

    echo '<table class="table table-striped"><thead><tr>'
        . '<th>Time</th><th>Action</th><th>Domain</th><th>Detail</th><th>Actor</th><th>IP</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . htmlspecialchars($row->created_at) . '</td>'
            . '<td><span class="label label-info">' . htmlspecialchars($row->action) . '</span></td>'
            . '<td>' . htmlspecialchars($row->domain) . '</td>'
            . '<td>' . htmlspecialchars((string) $row->detail) . '</td>'
            . '<td>' . htmlspecialchars($row->actor) . '</td>'
            . '<td>' . htmlspecialchars($row->ip) . '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="6" class="text-center text-muted">No log entries.</td></tr>';
    }
    echo '</tbody></table>';
    pdns_pager($modulelink, 'log', $total, $page);
}

/**
 * Shared record viewer used by the admin inline manage view.
 * $isAdmin unlocks apex-NS visibility; SOA stays read-only everywhere.
 */
function pdns_render_record_editor($modulelink, $domain, $zone, $isAdmin)
{
    $records = pdns_zone_records($zone);
    echo '<h4>Records for ' . htmlspecialchars($domain)
        . ' <small>serial ' . (int) (isset($zone['serial']) ? $zone['serial'] : 0) . '</small></h4>';
    echo '<table class="table table-striped table-condensed"><thead><tr>'
        . '<th>Name</th><th>Type</th><th>TTL</th><th>Content</th><th>Flags</th></tr></thead><tbody>';
    foreach ($records as $r) {
        echo '<tr><td><code>' . htmlspecialchars($r['name']) . '</code></td>'
            . '<td><span class="label label-' . ($r['is_soa'] ? 'danger' : ($r['is_apex_ns'] ? 'warning' : 'primary'))
            . '">' . htmlspecialchars($r['type']) . '</span></td>'
            . '<td>' . (int) $r['ttl'] . '</td>'
            . '<td style="word-break:break-all">' . htmlspecialchars($r['content']) . '</td><td>';
        if ($r['is_soa'] || $r['is_apex_ns']) {
            echo '<span class="text-muted">Protected</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}

/* ========================================================== client area */

function pdnsmanager_clientarea($vars)
{
    $LANG = isset($vars['_lang']) ? $vars['_lang'] : [];
    $clientId = pdns_current_client_id();

    // JSON endpoints (DNSSEC click-to-check, NS check).
    if (isset($_GET['pdns_ajax']) && $clientId) {
        header('Content-Type: application/json');
        $domain = strtolower(trim((string) (isset($_GET['domain']) ? $_GET['domain'] : '')));
        if (!pdns_client_owns($clientId, $domain)) {
            echo json_encode(['ok' => false]);
            exit;
        }
        if ($_GET['pdns_ajax'] === 'dnssec') {
            $zone = pdns_get_zone($domain);
            $signed = $zone && !empty($zone['dnssec']);
            echo json_encode([
                'ok' => true, 'signed' => $signed,
                'ds' => $signed ? pdns_ds_records($domain) : [],
            ]);
        } elseif ($_GET['pdns_ajax'] === 'nscheck') {
            list($status, $live) = pdns_ns_check($domain);
            echo json_encode(['ok' => true, 'status' => $status, 'live' => $live]);
        }
        exit;
    }

    // Zone export download.
    if (isset($_GET['pdns_action']) && $_GET['pdns_action'] === 'export' && $clientId) {
        $domain = strtolower(trim((string) $_GET['domain']));
        if (pdns_client_owns($clientId, $domain)) {
            $zone = pdns_get_zone($domain);
            if ($zone) {
                header('Content-Type: text/plain; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $domain . '.zone"');
                echo pdns_export_zonefile($zone);
                exit;
            }
        }
        header('Location: index.php?m=pdnsmanager');
        exit;
    }

    $error = '';
    $success = '';
    $domain = isset($_REQUEST['domain']) ? strtolower(trim((string) $_REQUEST['domain'])) : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $clientId && isset($_POST['pdns_do'])) {
        list($error, $success, $domain) = pdns_client_handle_post($clientId, $LANG);
    }

    $domains = $clientId ? pdns_client_domains($clientId) : [];
    $protected = pdns_protected_domains();
    $manageable = array_filter(array_keys($domains), function ($d) use ($protected) {
        return !in_array($d, $protected, true);
    });

    $manageZone = null;
    if ($domain && pdns_client_owns($clientId, $domain) && in_array($domain, $manageable, true)) {
        $manageZone = pdns_get_zone($domain);
        if (!$manageZone) {
            $error = $LANG['zone_missing'] ?? 'No DNS zone exists for this domain yet.';
        }
    }

    return [
        'pagetitle'    => $LANG['page_title'] ?? 'DNS Manager',
        'breadcrumb'   => ['index.php?m=pdnsmanager' => $LANG['page_title'] ?? 'DNS Manager'],
        'templatefile' => $manageZone ? 'records' : 'zones',
        'requirelogin' => true,
        'vars'         => [
            'domains'     => array_values($manageable),
            'domain'      => $domain,
            'zone'        => $manageZone,
            'records'     => $manageZone ? pdns_zone_records($manageZone) : [],
            'types'       => pdns_allowed_record_types(),
            'dnssec'      => $manageZone && pdns_setting('enable_dnssec', '') === 'on',
            'signed'      => $manageZone && !empty($manageZone['dnssec']),
            'error'       => $error,
            'success'     => $success,
            'addonLang'   => $LANG,
        ],
    ];
}

function pdns_client_owns($clientId, $domain)
{
    if (!$clientId || !$domain) {
        return false;
    }
    return Capsule::table('tbldomains')
        ->where('userid', $clientId)->where('domain', $domain)->exists();
}

function pdns_client_handle_post($clientId, $LANG)
{
    $error = '';
    $success = '';
    $domain = strtolower(trim((string) (isset($_POST['domain']) ? $_POST['domain'] : '')));
    $do = $_POST['pdns_do'];

    if (!pdns_client_owns($clientId, $domain) || in_array($domain, pdns_protected_domains(), true)) {
        return [$LANG['not_owner'] ?? 'You do not own this domain.', '', $domain];
    }

    switch ($do) {
        case 'create_zone':
            list($ok, $e) = pdns_create_zone($domain);
            if (!$ok) {
                $error = $e;
                break;
            }
            Capsule::table('mod_pdns_zones')->updateOrInsert(
                ['domain' => $domain],
                ['clientid' => $clientId, 'created_at' => date('Y-m-d H:i:s')]
            );
            pdns_log('zone_created', $domain, 'client', 'client');
            $success = $LANG['zone_created'] ?? 'DNS zone created.';
            break;

        case 'save_record':
            $name  = trim((string) $_POST['name']);
            $type  = strtoupper(trim((string) $_POST['type']));
            $ttl   = (int) $_POST['ttl'];
            $values = array_filter(array_map('trim', preg_split('/\r?\n/', (string) $_POST['content'])));
            if (!in_array($type, pdns_allowed_record_types(), true)) {
                $error = $LANG['bad_type'] ?? 'Unsupported record type.';
                break;
            }
            $fqdn = pdns_normalize_name($name, $domain);
            if ($fqdn === pdns_zone_id($domain) && in_array($type, ['NS', 'CNAME'], true)) {
                $error = $LANG['apex_guard'] ?? 'Apex NS/CNAME records cannot be changed here.';
                break;
            }
            if (!$values) {
                $error = $LANG['no_content'] ?? 'Record content is required.';
                break;
            }
            list($ok, $e) = pdns_save_rrset($domain, $name, $type, $ttl, array_values($values));
            if (!$ok) {
                $error = $e;
                break;
            }
            pdns_log('record_saved', $domain, $fqdn . ' ' . $type, 'client');
            $success = $LANG['record_saved'] ?? 'Record saved.';
            break;

        case 'delete_record':
            $name = trim((string) $_POST['name']);
            $type = strtoupper(trim((string) $_POST['type']));
            $fqdn = pdns_normalize_name($name, $domain);
            if ($type === 'SOA' || ($type === 'NS' && $fqdn === pdns_zone_id($domain))) {
                $error = $LANG['apex_guard'] ?? 'Apex NS/CNAME records cannot be changed here.';
                break;
            }
            list($ok, $e) = pdns_patch_rrsets($domain, [[
                'name' => $fqdn, 'type' => $type, 'changetype' => 'DELETE', 'records' => [],
            ]]);
            if (!$ok) {
                $error = $e;
                break;
            }
            pdns_log('record_deleted', $domain, $fqdn . ' ' . $type, 'client');
            $success = $LANG['record_deleted'] ?? 'Record set deleted.';
            break;

        case 'import_zone':
            list($rrsets, $skipped) = pdns_parse_zonefile((string) $_POST['zonefile'], $domain);
            if (!$rrsets) {
                $error = $LANG['import_empty'] ?? 'No importable records found (SOA and apex NS are skipped).';
                break;
            }
            list($ok, $e) = pdns_patch_rrsets($domain, $rrsets);
            if (!$ok) {
                $error = $e;
                break;
            }
            pdns_log('zone_imported', $domain, count($rrsets) . ' sets', 'client');
            $success = str_replace(':count', count($rrsets),
                $LANG['import_done'] ?? 'Imported :count record sets.');
            break;

        case 'dnssec_on':
            list($ok, $e) = pdns_dnssec_enable($domain);
            if (!$ok) {
                $error = $e;
                break;
            }
            pdns_log('dnssec_on', $domain, '', 'client');
            $success = $LANG['dnssec_on'] ?? 'DNSSEC enabled.';
            break;

        case 'dnssec_off':
            list($ok, $e) = pdns_dnssec_disable($domain);
            if (!$ok) {
                $error = $e;
                break;
            }
            pdns_log('dnssec_off', $domain, '', 'client');
            $success = $LANG['dnssec_off'] ?? 'DNSSEC disabled.';
            break;
    }
    return [$error, $success, $domain];
}
