<?php
/**
 * ServerSpan QuickBooks Sync
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/qbosync/qbosync.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Functions.php';

define('QBO_VERSION', '1.1.0');
define('QBO_PER_PAGE', 25);

function qbosync_config()
{
    return [
        'name'        => 'ServerSpan QuickBooks Sync',
        'description' => 'Sync WHMCS clients, invoices, payments and refunds into QuickBooks Online: '
            . 'manual or cron-driven, with tax, gateway and currency mapping and full logs.',
        'author'      => 'ServerSpan',
        'language'    => 'english',
        'version'     => QBO_VERSION,
        'fields'      => [
            'environment' => [
                'FriendlyName' => 'Environment', 'Type' => 'dropdown',
                'Options' => 'sandbox,production', 'Default' => 'sandbox',
            ],
            'client_id' => [
                'FriendlyName' => 'Client ID', 'Type' => 'text', 'Size' => '60',
                'Description' => 'From your Intuit Developer app (Keys & Credentials).',
            ],
            'client_secret' => [
                'FriendlyName' => 'Client Secret', 'Type' => 'password', 'Size' => '60',
            ],
            'auto_clients' => [
                'FriendlyName' => 'Auto-Sync New/Edited Clients', 'Type' => 'yesno',
            ],
            'auto_invoices_paid' => [
                'FriendlyName' => 'Auto-Sync Paid Invoices + Payments', 'Type' => 'yesno',
                'Description' => 'Queues the invoice and its payment when an invoice is marked paid.',
            ],
            'auto_refunds' => [
                'FriendlyName' => 'Auto-Sync Refunds (Credit Memos)', 'Type' => 'yesno',
            ],
            'batch_limit' => [
                'FriendlyName' => 'Queue Batch Limit', 'Type' => 'text', 'Size' => '5', 'Default' => '25',
                'Description' => 'Jobs processed per cron run / manual run.',
            ],
        ],
    ];
}

function qbosync_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_qbo_auth')) {
            Capsule::schema()->create('mod_qbo_auth', function ($table) {
                $table->increments('id');
                $table->string('realm_id', 40);
                $table->text('access_token');
                $table->text('refresh_token');
                $table->dateTime('access_expires_at');
                $table->dateTime('refresh_expires_at');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
        }
        if (!Capsule::schema()->hasTable('mod_qbo_map')) {
            Capsule::schema()->create('mod_qbo_map', function ($table) {
                $table->increments('id');
                $table->string('entity', 20);
                $table->string('whmcs_id', 64);
                $table->string('qbo_id', 40);
                $table->dateTime('synced_at');
                $table->unique(['entity', 'whmcs_id']);
            });
        }
        if (!Capsule::schema()->hasTable('mod_qbo_rel')) {
            Capsule::schema()->create('mod_qbo_rel', function ($table) {
                $table->increments('id');
                $table->string('rel_type', 30);
                $table->string('whmcs_key', 64);
                $table->string('qbo_id', 40)->default('');
                $table->string('qbo_name', 100)->default('');
                $table->unique(['rel_type', 'whmcs_key']);
            });
        }
        if (!Capsule::schema()->hasTable('mod_qbo_queue')) {
            Capsule::schema()->create('mod_qbo_queue', function ($table) {
                $table->increments('id');
                $table->string('entity', 20);
                $table->string('whmcs_id', 64);
                $table->string('action', 20)->default('sync');
                $table->string('status', 15)->default('pending')->index();
                $table->string('message', 250)->default('');
                $table->unsignedInteger('attempts')->default(0);
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
        }
        if (!Capsule::schema()->hasTable('mod_qbo_log')) {
            Capsule::schema()->create('mod_qbo_log', function ($table) {
                $table->increments('id');
                $table->string('action', 20);
                $table->string('entity', 20);
                $table->string('whmcs_id', 64)->default('');
                $table->string('qbo_id', 40)->default('');
                $table->string('status', 15);
                $table->text('message')->nullable();
                $table->dateTime('created_at')->index();
            });
        }
        return ['status' => 'success', 'description' => 'Tables created. Enter your Intuit app credentials, '
            . 'then connect your QuickBooks company from the module Dashboard.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

function qbosync_deactivate()
{
    return ['status' => 'success', 'description' => 'Module deactivated. All mod_qbo_* tables were preserved.'];
}

/* ============================================================ admin area */

function qbosync_output($vars)
{
    $modulelink = $vars['modulelink'];
    $msg = '';
    $err = '';

    // OAuth callback (GET, before any POST handling).
    if (isset($_GET['qbo_oauth']) && $_GET['qbo_oauth'] === 'callback') {
        $stateOk = !empty($_GET['state']) && !empty($_SESSION['qbo_oauth_state'])
            && hash_equals($_SESSION['qbo_oauth_state'], (string) $_GET['state']);
        unset($_SESSION['qbo_oauth_state']);
        if (!$stateOk) {
            $err = 'OAuth state mismatch — authorization rejected.';
        } elseif (!empty($_GET['code']) && !empty($_GET['realmId'])) {
            list($ok, $e) = qbo_connect((string) $_GET['code'], (string) $_GET['realmId']);
            if ($ok) {
                $msg = 'Connected to QuickBooks company ' . htmlspecialchars((string) $_GET['realmId']) . '.';
            } else {
                $err = 'Token exchange failed: ' . $e;
            }
        } else {
            $err = 'Missing code or realmId in the Intuit callback.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qbo_do'])) {
        if (function_exists('check_token') && !check_token('WHMCS.admin.default')) {
            $err = 'Invalid CSRF token.';
        } else {
            list($msg2, $err2) = qbo_admin_handle_post($modulelink);
            $msg = $msg ?: $msg2;
            $err = $err ?: $err2;
        }
    }

    $tab = isset($_GET['qbo_tab']) ? preg_replace('/[^a-z]/', '', $_GET['qbo_tab']) : 'dashboard';
    $tabs = [
        'dashboard' => 'Dashboard',
        'sync'      => 'Manual Sync',
        'mapping'   => 'Mapping',
        'queue'     => 'Queue',
        'log'       => 'Log',
    ];

    echo '<h2>ServerSpan QuickBooks Sync <small>v' . QBO_VERSION . '</small></h2>';
    if ($msg) {
        echo '<div class="alert alert-success">' . $msg . '</div>';
    }
    if ($err) {
        echo '<div class="alert alert-danger">' . $err . '</div>';
    }
    echo '<ul class="nav nav-tabs" style="margin-bottom:20px">';
    foreach ($tabs as $key => $label) {
        $active = $tab === $key ? ' class="active"' : '';
        echo '<li' . $active . '><a href="' . $modulelink . '&qbo_tab=' . $key . '">' . $label . '</a></li>';
    }
    echo '</ul>';

    switch ($tab) {
        case 'sync':
            qbo_admin_sync($modulelink);
            break;
        case 'mapping':
            qbo_admin_mapping($modulelink);
            break;
        case 'queue':
            qbo_admin_queue($modulelink);
            break;
        case 'log':
            qbo_admin_log($modulelink);
            break;
        default:
            qbo_admin_dashboard($modulelink);
    }

    echo '<p class="text-muted" style="margin-top:20px">Developed by '
        . '<a href="https://www.serverspan.com/en/tools/index" target="_blank">ServerSpan</a>.</p>';
}

function qbo_admin_handle_post($modulelink)
{
    $do = $_POST['qbo_do'];
    switch ($do) {
        case 'disconnect':
            qbo_disconnect();
            return ['Disconnected from QuickBooks.', ''];

        case 'run_sync':
            $from = trim((string) $_POST['date_from']);
            $to   = trim((string) $_POST['date_to']);
            $types = isset($_POST['types']) ? (array) $_POST['types'] : [];
            if (!$types) {
                return ['', 'Pick at least one data type.'];
            }
            $enqueued = 0;
            if (in_array('customer', $types, true)) {
                $q = Capsule::table('tblclients');
                if ($from) {
                    $q->where('datecreated', '>=', $from . ' 00:00:00');
                }
                if ($to) {
                    $q->where('datecreated', '<=', $to . ' 23:59:59');
                }
                foreach ($q->pluck('id') as $id) {
                    qbo_enqueue('customer', $id, 'sync');
                    $enqueued++;
                }
            }
            if (in_array('invoice', $types, true)) {
                $q = Capsule::table('tblinvoices');
                if ($from) {
                    $q->where('date', '>=', $from);
                }
                if ($to) {
                    $q->where('date', '<=', $to);
                }
                foreach ($q->pluck('id') as $id) {
                    qbo_enqueue('invoice', $id, 'sync');
                    $enqueued++;
                }
            }
            if (in_array('payment', $types, true)) {
                $q = Capsule::table('tblaccounts')->where('invoiceid', '>', 0)->where('amountin', '>', 0);
                if ($from) {
                    $q->where('date', '>=', $from . ' 00:00:00');
                }
                if ($to) {
                    $q->where('date', '<=', $to . ' 23:59:59');
                }
                foreach ($q->pluck('id') as $id) {
                    qbo_enqueue('payment', $id, 'sync');
                    $enqueued++;
                }
            }
            if (in_array('refund', $types, true)) {
                $q = Capsule::table('tblinvoices')->where('status', 'Refunded');
                if ($from) {
                    $q->where('date', '>=', $from);
                }
                if ($to) {
                    $q->where('date', '<=', $to);
                }
                foreach ($q->pluck('id') as $id) {
                    qbo_enqueue('refund', $id, 'sync');
                    $enqueued++;
                }
            }
            $limit = (int) qbo_setting('batch_limit', 25);
            list($done, $failed, $rateLimited) = qbo_process_queue($limit);
            $out = "Enqueued {$enqueued} job(s); processed now: {$done} synced, {$failed} failed "
                . "(failures stay queued and retry on cron).";
            if ($rateLimited) {
                $out .= ' QBO rate limit hit — remaining jobs continue on the next cron run.';
            }
            return [$out, ''];

        case 'retry_queue':
            Capsule::table('mod_qbo_queue')->whereIn('status', ['failed'])->update(['status' => 'pending', 'attempts' => 0]);
            return ['Failed jobs re-queued.', ''];

        case 'clear_done':
            Capsule::table('mod_qbo_queue')->where('status', 'done')->delete();
            return ['Completed jobs cleared.', ''];

        case 'save_mapping':
            foreach ((array) $_POST['map'] as $relType => $rows) {
                foreach ((array) $rows as $whmcsKey => $qboId) {
                    qbo_rel_set($relType, $whmcsKey, trim((string) $qboId));
                }
            }
            return ['Mapping saved.', ''];
    }
    return ['', ''];
}

function qbo_token()
{
    return function_exists('generate_token') ? generate_token() : '';
}

function qbo_admin_dashboard($modulelink)
{
    $auth = Capsule::table('mod_qbo_auth')->orderBy('id', 'desc')->first();
    echo '<div class="row"><div class="col-md-6">';
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>Connection</strong></div><div class="panel-body">';
    if ($auth) {
        $refreshOk = $auth->refresh_expires_at > date('Y-m-d H:i:s');
        echo '<p>Company (realmId): <code>' . htmlspecialchars($auth->realm_id) . '</code><br>'
            . 'Environment: <strong>' . htmlspecialchars(qbo_setting('environment', 'sandbox')) . '</strong><br>'
            . 'Access token valid until: ' . htmlspecialchars($auth->access_expires_at) . '<br>'
            . 'Refresh token valid until: ' . htmlspecialchars($auth->refresh_expires_at) . ' '
            . ($refreshOk ? '<span class="label label-success">OK</span>' : '<span class="label label-danger">Expired — reconnect</span>')
            . '</p>';
        echo '<form method="post" action="' . $modulelink . '" style="display:inline">' . qbo_token()
            . '<input type="hidden" name="qbo_do" value="disconnect">'
            . '<button class="btn btn-danger" onclick="return confirm(\'Disconnect from QuickBooks?\')">Disconnect</button></form>';
    } else {
        if (!qbo_setting('client_id') || !qbo_setting('client_secret')) {
            echo '<div class="alert alert-warning">Set your Intuit Client ID and Client Secret in the '
                . 'module configuration first.</div>';
        }
        echo '<p>Not connected.</p>';
        echo '<p><a class="btn btn-primary" href="' . htmlspecialchars(qbo_authorize_url()) . '">Connect to QuickBooks</a></p>';
        echo '<p class="text-muted">Register this redirect URI in your Intuit app:<br><code>'
            . htmlspecialchars(qbo_redirect_uri()) . '</code></p>';
    }
    echo '</div></div></div>';

    echo '<div class="col-md-6"><div class="panel panel-default"><div class="panel-heading"><strong>Synced so far</strong></div><div class="panel-body">';
    $counts = Capsule::table('mod_qbo_map')->selectRaw('entity, COUNT(*) as c')->groupBy('entity')->pluck('c', 'entity');
    foreach (['customer' => 'Customers', 'invoice' => 'Invoices', 'payment' => 'Payments', 'creditmemo' => 'Credit Memos'] as $k => $label) {
        echo '<p>' . $label . ': <strong>' . (int) (isset($counts[$k]) ? $counts[$k] : 0) . '</strong></p>';
    }
    $pending = Capsule::table('mod_qbo_queue')->whereIn('status', ['pending', 'processing'])->count();
    $failed  = Capsule::table('mod_qbo_queue')->where('status', 'failed')->count();
    echo '<hr><p>Queue: <strong>' . $pending . '</strong> pending, <strong>' . $failed . '</strong> failed</p>';
    echo '</div></div></div></div>';
}

function qbo_admin_sync($modulelink)
{
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>Manual Sync</strong></div><div class="panel-body">';
    echo '<form method="post" action="' . $modulelink . '" class="form-inline">' . qbo_token();
    echo '<input type="hidden" name="qbo_do" value="run_sync">';
    foreach (['customer' => 'Clients', 'invoice' => 'Invoices', 'payment' => 'Payments', 'refund' => 'Refunds'] as $k => $label) {
        echo '<label class="checkbox-inline"><input type="checkbox" name="types[]" value="' . $k . '" checked> ' . $label . '</label> ';
    }
    echo '<br><br><label>From</label> <input type="date" name="date_from" class="form-control"> ';
    echo '<label>To</label> <input type="date" name="date_to" class="form-control"> ';
    echo '<button class="btn btn-primary">Enqueue & Run Now</button></form>';
    echo '<p class="text-muted" style="margin-top:10px">Already-synced records are skipped automatically. '
        . 'The queue also runs on the daily cron. Invoices pull their clients; payments pull their invoice and client.</p>';
    echo '</div></div>';
}

function qbo_admin_mapping($modulelink)
{
    $connected = (bool) Capsule::table('mod_qbo_auth')->first();

    $methods = [];
    $accounts = [];
    $taxCodes = [];
    if ($connected) {
        foreach (qbo_query('SELECT * FROM PaymentMethod MAXRESULTS 100') as $m) {
            $methods[$m['Id']] = $m['Name'];
        }
        foreach (qbo_query("SELECT * FROM Account WHERE AccountType IN ('Bank', 'Other Current Asset') MAXRESULTS 100") as $a) {
            $accounts[$a['Id']] = $a['Name'];
        }
        foreach (qbo_query('SELECT * FROM TaxCode MAXRESULTS 100') as $t) {
            $taxCodes[$t['Id']] = $t['Name'];
        }
    }

    echo '<form method="post" action="' . $modulelink . '">' . qbo_token();
    echo '<input type="hidden" name="qbo_do" value="save_mapping">';

    echo '<h4>Payment Gateways</h4>';
    $gateways = Capsule::table('tblaccounts')->select('gateway')->groupBy('gateway')->pluck('gateway');
    echo '<table class="table table-striped"><thead><tr><th>WHMCS Gateway</th><th>QBO Payment Method ID</th>'
        . '<th>QBO Deposit Account ID</th></tr></thead><tbody>';
    foreach ($gateways as $gw) {
        echo '<tr><td>' . htmlspecialchars($gw) . '</td>'
            . '<td><input type="text" class="form-control" name="map[gateway_method][' . htmlspecialchars($gw) . ']" value="'
            . htmlspecialchars(qbo_rel_get('gateway_method', $gw)) . '"></td>'
            . '<td><input type="text" class="form-control" name="map[gateway_account][' . htmlspecialchars($gw) . ']" value="'
            . htmlspecialchars(qbo_rel_get('gateway_account', $gw)) . '"></td></tr>';
    }
    echo '</tbody></table>';

    echo '<h4>Taxes</h4>';
    echo '<p class="text-muted">Map each WHMCS tax rule to a QBO TaxCode ID. The <code>combined</code> key '
        . 'applies when BOTH tax levels hit an invoice (dual-tax regions); <code>non</code> is the '
        . 'non-taxable code (usually NON).</p>';
    $rules = Capsule::table('tbltax')->orderBy('level')->get();
    echo '<table class="table table-striped"><thead><tr><th>WHMCS Tax Rule</th><th>Level</th><th>Rate</th><th>QBO TaxCode ID</th></tr></thead><tbody>';
    foreach ($rules as $rule) {
        echo '<tr><td>' . htmlspecialchars($rule->name) . ' (' . htmlspecialchars($rule->country . ' ' . $rule->state) . ')</td>'
            . '<td>' . (int) $rule->level . '</td><td>' . $rule->taxrate . '%</td>'
            . '<td><input type="text" class="form-control" name="map[tax][rule_' . (int) $rule->id . ']" value="'
            . htmlspecialchars(qbo_rel_get('tax', 'rule_' . $rule->id)) . '"></td></tr>';
    }
    foreach (['combined' => 'Combined (both levels)', 'non' => 'Non-taxable'] as $key => $label) {
        echo '<tr><td>' . $label . '</td><td>-</td><td>-</td>'
            . '<td><input type="text" class="form-control" name="map[tax][' . $key . ']" value="'
            . htmlspecialchars(qbo_rel_get('tax', $key)) . '"></td></tr>';
    }
    echo '</tbody></table>';

    if ($connected && ($methods || $accounts || $taxCodes)) {
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>QBO reference (live)</strong></div>'
            . '<div class="panel-body row">'
            . '<div class="col-md-4"><strong>Payment Methods</strong><br>' . nl2br(htmlspecialchars(implode("\n", array_map(function ($id, $n) { return "$id = $n"; }, array_keys($methods), $methods)))) . '</div>'
            . '<div class="col-md-4"><strong>Deposit Accounts</strong><br>' . nl2br(htmlspecialchars(implode("\n", array_map(function ($id, $n) { return "$id = $n"; }, array_keys($accounts), $accounts)))) . '</div>'
            . '<div class="col-md-4"><strong>Tax Codes</strong><br>' . nl2br(htmlspecialchars(implode("\n", array_map(function ($id, $n) { return "$id = $n"; }, array_keys($taxCodes), $taxCodes)))) . '</div>'
            . '</div></div>';
    }
    echo '<button class="btn btn-primary">Save Mapping</button></form>';
}

function qbo_admin_queue($modulelink)
{
    $page  = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
    $total = Capsule::table('mod_qbo_queue')->count();
    $rows  = Capsule::table('mod_qbo_queue')->orderBy('id', 'desc')
        ->offset(($page - 1) * QBO_PER_PAGE)->limit(QBO_PER_PAGE)->get();

    echo '<form method="post" action="' . $modulelink . '" style="margin-bottom:15px">' . qbo_token()
        . '<button class="btn btn-default" name="qbo_do" value="retry_queue">Retry Failed</button> '
        . '<button class="btn btn-default" name="qbo_do" value="clear_done">Clear Completed</button></form>';

    echo '<table class="table table-striped"><thead><tr>'
        . '<th>Entity</th><th>WHMCS ID</th><th>Status</th><th>Attempts</th><th>Message</th><th>Updated</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $badge = $row->status === 'done' ? 'success' : ($row->status === 'failed' ? 'danger' : 'info');
        echo '<tr><td>' . htmlspecialchars($row->entity) . '</td><td>' . htmlspecialchars($row->whmcs_id) . '</td>'
            . '<td><span class="label label-' . $badge . '">' . htmlspecialchars($row->status) . '</span></td>'
            . '<td>' . (int) $row->attempts . '</td>'
            . '<td style="word-break:break-all">' . htmlspecialchars($row->message) . '</td>'
            . '<td>' . htmlspecialchars($row->updated_at) . '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="6" class="text-center text-muted">Queue is empty.</td></tr>';
    }
    echo '</tbody></table>';

    $pages = max(1, (int) ceil($total / QBO_PER_PAGE));
    if ($pages > 1) {
        echo '<ul class="pagination">';
        for ($p = 1; $p <= $pages; $p++) {
            echo '<li' . ($p === $page ? ' class="active"' : '') . '><a href="' . $modulelink
                . '&qbo_tab=queue&page=' . $p . '">' . $p . '</a></li>';
        }
        echo '</ul>';
    }
}

function qbo_admin_log($modulelink)
{
    $page  = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
    $total = Capsule::table('mod_qbo_log')->count();
    $rows  = Capsule::table('mod_qbo_log')->orderBy('id', 'desc')
        ->offset(($page - 1) * QBO_PER_PAGE)->limit(QBO_PER_PAGE)->get();

    echo '<table class="table table-striped"><thead><tr>'
        . '<th>Time</th><th>Action</th><th>Entity</th><th>WHMCS ID</th><th>QBO ID</th><th>Status</th><th>Message</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $badge = $row->status === 'success' ? 'success' : 'danger';
        echo '<tr><td>' . htmlspecialchars($row->created_at) . '</td>'
            . '<td>' . htmlspecialchars($row->action) . '</td>'
            . '<td>' . htmlspecialchars($row->entity) . '</td>'
            . '<td>' . htmlspecialchars($row->whmcs_id) . '</td>'
            . '<td>' . htmlspecialchars($row->qbo_id) . '</td>'
            . '<td><span class="label label-' . $badge . '">' . htmlspecialchars($row->status) . '</span></td>'
            . '<td style="word-break:break-all">' . htmlspecialchars((string) $row->message) . '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="7" class="text-center text-muted">No log entries.</td></tr>';
    }
    echo '</tbody></table>';

    $pages = max(1, (int) ceil($total / QBO_PER_PAGE));
    if ($pages > 1) {
        echo '<ul class="pagination">';
        for ($p = 1; $p <= $pages; $p++) {
            echo '<li' . ($p === $page ? ' class="active"' : '') . '><a href="' . $modulelink
                . '&qbo_tab=log&page=' . $p . '">' . $p . '</a></li>';
        }
        echo '</ul>';
    }
}
