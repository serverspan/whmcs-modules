<?php
/**
 * ServerSpan EuPlatesc Manager
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/epmanager/epmanager.php
 *
 * Backoffice for the EuPlătesc gateway: transaction actions (capture, partial
 * capture, reversal, refund, cancel recurring, update invoice id), settlement
 * reporting, saved-card management and the IPN log.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Functions.php';

define('EPM_VERSION', '1.0.0');
define('EPM_PER_PAGE', 25);

function epmanager_config()
{
    return [
        'name'        => 'ServerSpan EuPlatesc Manager',
        'description' => 'Backoffice for the EuPlătesc gateway: captures, refunds, reversals, '
            . 'recurring cancellation, settlements, saved cards and IPN log. '
            . 'Reads credentials from the EuPlătesc gateway configuration.',
        'author'      => 'ServerSpan',
        'language'    => 'english',
        'version'     => EPM_VERSION,
        'fields'      => [],
    ];
}

function epmanager_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_ep_log')) {
            Capsule::schema()->create('mod_ep_log', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('invoice_id')->default(0)->index();
                $table->string('event', 40);
                $table->string('ep_id', 64)->default('')->index();
                $table->text('payload')->nullable();
                $table->string('ip', 45)->default('');
                $table->dateTime('created_at')->index();
            });
        }
        return ['status' => 'success', 'description' => 'Table created. Activate and configure the '
            . 'EuPlătesc gateway first — the addon reads its credentials from there.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

function epmanager_deactivate()
{
    return ['status' => 'success', 'description' => 'Module deactivated. mod_ep_log was preserved.'];
}

/* ============================================================ admin area */

function epmanager_output($vars)
{
    $modulelink = $vars['modulelink'];
    $msg = '';
    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['epm_do'])) {
        if (function_exists('check_token') && !check_token('WHMCS.admin.default')) {
            $err = 'Invalid CSRF token.';
        } else {
            list($ok, $info) = epm_run_action(
                preg_replace('/[^a-z_]/', '', (string) $_POST['epm_do']),
                preg_replace('/[^A-Fa-f0-9]/', '', (string) (isset($_POST['ep_id']) ? $_POST['ep_id'] : '')),
                [
                    'amount'     => isset($_POST['amount']) ? (float) $_POST['amount'] : 0,
                    'reason'     => isset($_POST['reason']) ? trim((string) $_POST['reason']) : '',
                    'invoice_id' => isset($_POST['invoice_id']) ? trim((string) $_POST['invoice_id']) : '',
                ]
            );
            if ($ok) {
                $msg = 'Action completed: ' . $info;
            } else {
                $err = 'Action failed: ' . $info;
            }
        }
    }

    $tab = isset($_GET['epm_tab']) ? preg_replace('/[^a-z]/', '', $_GET['epm_tab']) : 'dashboard';
    $tabs = [
        'dashboard'    => 'Dashboard',
        'transactions' => 'Transactions',
        'settlements'  => 'Settlements',
        'cards'        => 'Saved Cards',
        'ipn'          => 'IPN Log',
    ];

    echo '<h2>ServerSpan EuPlatesc Manager <small>v' . EPM_VERSION . '</small></h2>';
    if ($msg) {
        echo '<div class="alert alert-success">' . htmlspecialchars($msg) . '</div>';
    }
    if ($err) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($err) . '</div>';
    }
    if (!epm_gateway_installed()) {
        echo '<div class="alert alert-warning">The EuPlătesc gateway (modules/gateways/euplatesc.php) '
            . 'is not installed or not configured.</div>';
    } elseif (!epm_gateway_active()) {
        echo '<div class="alert alert-warning">The EuPlătesc gateway is configured but not active.</div>';
    }
    echo '<ul class="nav nav-tabs" style="margin-bottom:20px">';
    foreach ($tabs as $key => $label) {
        $active = $tab === $key ? ' class="active"' : '';
        echo '<li' . $active . '><a href="' . $modulelink . '&epm_tab=' . $key . '">' . $label . '</a></li>';
    }
    echo '</ul>';

    switch ($tab) {
        case 'transactions':
            epm_admin_transactions($modulelink);
            break;
        case 'settlements':
            epm_admin_settlements($modulelink);
            break;
        case 'cards':
            epm_admin_cards($modulelink);
            break;
        case 'ipn':
            epm_admin_ipn($modulelink);
            break;
        default:
            epm_admin_dashboard($modulelink);
    }

    echo '<p class="text-muted" style="margin-top:20px">Developed by '
        . '<a href="https://www.serverspan.com/en/tools/index" target="_blank">ServerSpan</a>.</p>';
}

function epm_token()
{
    return function_exists('generate_token') ? generate_token() : '';
}

function epm_pager($modulelink, $tab, $total, $page)
{
    $pages = max(1, (int) ceil($total / EPM_PER_PAGE));
    if ($pages <= 1) {
        return;
    }
    echo '<ul class="pagination">';
    for ($p = 1; $p <= $pages; $p++) {
        echo '<li' . ($p === $page ? ' class="active"' : '') . '><a href="' . $modulelink
            . '&epm_tab=' . $tab . '&page=' . $p . '">' . $p . '</a></li>';
    }
    echo '</ul>';
}

function epm_admin_dashboard($modulelink)
{
    echo '<div class="row"><div class="col-md-6">';
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>Merchant</strong></div><div class="panel-body">';
    echo '<p>Gateway: ' . (epm_gateway_active()
        ? '<span class="label label-success">Active</span>' : '<span class="label label-default">Inactive</span>')
        . ' · Mode: <strong>' . (ep_test_mode() ? 'TEST' : 'LIVE') . '</strong>'
        . ' · MID: <code>' . htmlspecialchars(ep_merchant_id()) . '</code></p>';
    if (ep_setting('userKey') && ep_setting('userApi')) {
        $mid = ep_check_mid();
        if (isset($mid['name'])) {
            echo '<table class="table table-condensed">';
            foreach (['name' => 'Name', 'url' => 'URL', 'cui' => 'CUI', 'j' => 'J', 'status' => 'Status',
                'recuring' => 'Recurring', 'tpl' => 'Template'] as $k => $label) {
                if (isset($mid[$k])) {
                    echo '<tr><td>' . $label . '</td><td>' . htmlspecialchars((string) $mid[$k]) . '</td></tr>';
                }
            }
            echo '</table>';
        } else {
            echo '<p class="text-muted">Check MID: ' . htmlspecialchars(isset($mid['error']) ? $mid['error'] : 'unavailable') . '</p>';
        }
    } else {
        echo '<p class="text-muted">Backoffice user credentials (User Key / User API) are not set on the '
            . 'gateway — capture, refund, status and reporting need them.</p>';
    }
    echo '</div></div></div>';

    echo '<div class="col-md-6"><div class="panel panel-default"><div class="panel-heading">'
        . '<strong>Captured Totals (last 30 days)</strong></div><div class="panel-body">';
    if (ep_setting('userKey') && ep_setting('userApi')) {
        $totals = ep_captured_total(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
        if (isset($totals['success']) && is_array($totals['success'])) {
            echo '<table class="table table-condensed">';
            foreach ($totals['success'] as $cur => $amt) {
                echo '<tr><td>' . htmlspecialchars($cur) . '</td><td><strong>' . htmlspecialchars((string) $amt) . '</strong></td></tr>';
            }
            echo '</table>';
        } else {
            echo '<p class="text-muted">' . htmlspecialchars(isset($totals['error']) ? $totals['error'] : 'No data') . '</p>';
        }
    } else {
        echo '<p class="text-muted">Requires backoffice credentials.</p>';
    }
    $today = Capsule::table('mod_ep_log')->where('created_at', '>=', date('Y-m-d 00:00:00'))->count();
    echo '<hr><p>IPN events today: <strong>' . $today . '</strong></p>';
    echo '</div></div></div></div>';
}

function epm_admin_transactions($modulelink)
{
    $page  = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
    $query = Capsule::table('tblaccounts')->where('gateway', 'euplatesc')->orderBy('id', 'desc');
    $total = $query->count();
    $rows  = $query->offset(($page - 1) * EPM_PER_PAGE)->limit(EPM_PER_PAGE)->get();

    echo '<p class="text-muted">Backoffice actions act on the EuPlătesc transaction ID (ep_id = WHMCS transid).</p>';
    echo '<table class="table table-striped"><thead><tr>'
        . '<th>Date</th><th>Invoice</th><th>Amount In</th><th>EP ID</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . htmlspecialchars($row->date) . '</td>'
            . '<td><a href="invoices.php?action=edit&id=' . (int) $row->invoiceid . '">#' . (int) $row->invoiceid . '</a></td>'
            . '<td>' . number_format((float) $row->amountin, 2) . '</td>'
            . '<td><code>' . htmlspecialchars(substr($row->transid, 0, 16)) . '&hellip;</code></td><td>';
        if ($row->transid) {
            $ep = htmlspecialchars($row->transid);
            $inv = (int) $row->invoiceid;
            foreach ([
                'capture'          => ['Capture', 'default', false],
                'partial_capture'  => ['Partial Capture', 'default', true],
                'refund'           => ['Refund', 'warning', true],
                'reversal'         => ['Reversal', 'danger', false],
                'cancel_recurring' => ['Cancel Recurring', 'danger', false],
            ] as $act => $cfg) {
                echo '<form method="post" action="' . $modulelink . '" style="display:inline" '
                    . 'onsubmit="return confirm(\'' . $cfg[0] . ' this transaction?\')">' . epm_token();
                echo '<input type="hidden" name="epm_do" value="' . $act . '">';
                echo '<input type="hidden" name="ep_id" value="' . $ep . '">';
                echo '<input type="hidden" name="invoice_id" value="' . $inv . '">';
                if ($cfg[2]) {
                    echo '<input type="text" name="amount" class="form-control input-sm" placeholder="Amount" '
                        . 'style="width:80px;display:inline" required> ';
                }
                echo '<button class="btn btn-xs btn-' . $cfg[1] . '">' . $cfg[0] . '</button></form> ';
            }
        }
        echo '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="5" class="text-center text-muted">No EuPlătesc transactions yet.</td></tr>';
    }
    echo '</tbody></table>';
    epm_pager($modulelink, 'transactions', $total, $page);

    // Status lookup tool.
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>Transaction Status / Card Art</strong></div><div class="panel-body">';
    echo '<form method="get" class="form-inline">';
    echo '<input type="hidden" name="m" value="epmanager"><input type="hidden" name="epm_tab" value="transactions">';
    echo '<input type="text" name="status_epid" class="form-control" placeholder="EP ID" size="46"> ';
    echo '<button class="btn btn-default">Get Status</button> ';
    echo '<button class="btn btn-default" name="cardart" value="1">Card Art</button></form>';

    if (!empty($_GET['status_epid'])) {
        $epid = preg_replace('/[^A-Fa-f0-9]/', '', (string) $_GET['status_epid']);
        if (!empty($_GET['cardart'])) {
            $art = ep_card_art($epid);
            if (isset($art['success']['cardart'])) {
                echo '<p>BIN ' . htmlspecialchars($art['success']['bin']) . ' ····· '
                    . htmlspecialchars($art['success']['last4']) . ' exp ' . htmlspecialchars($art['success']['exp']) . '</p>'
                    . '<img src="data:image/jpeg;base64,' . htmlspecialchars($art['success']['cardart']) . '" alt="card art">';
            } else {
                echo '<div class="alert alert-warning">' . htmlspecialchars(isset($art['error']) ? $art['error'] : 'No card art') . '</div>';
            }
        } else {
            $st = ep_get_status($epid);
            echo '<pre style="margin-top:10px">' . htmlspecialchars(json_encode($st, JSON_PRETTY_PRINT)) . '</pre>';
        }
    }
    echo '</div></div>';
}

function epm_admin_settlements($modulelink)
{
    $from = isset($_GET['from']) ? trim((string) $_GET['from']) : date('Y-m-d', strtotime('-3 months'));
    $to   = isset($_GET['to']) ? trim((string) $_GET['to']) : date('Y-m-d');

    echo '<form method="get" class="form-inline" style="margin-bottom:15px">';
    echo '<input type="hidden" name="m" value="epmanager"><input type="hidden" name="epm_tab" value="settlements">';
    echo '<label>From</label> <input type="date" name="from" class="form-control" value="' . htmlspecialchars($from) . '"> ';
    echo '<label>To</label> <input type="date" name="to" class="form-control" value="' . htmlspecialchars($to) . '"> ';
    echo '<button class="btn btn-primary">Load Settlement Invoices</button></form>';

    if (ep_setting('userKey') && ep_setting('userApi')) {
        $list = ep_invoice_list($from, $to);
        $invoices = isset($list['success']) && is_array($list['success']) ? $list['success'] : [];
        echo '<table class="table table-striped"><thead><tr>'
            . '<th>Invoice</th><th>Date</th><th>Net</th><th>VAT</th><th>Currency</th>'
            . '<th>Txns</th><th>Txn Amount</th><th>Transferred</th><th></th></tr></thead><tbody>';
        foreach ($invoices as $inv) {
            $no = isset($inv['invoice_number']) ? $inv['invoice_number'] : '';
            echo '<tr><td><code>' . htmlspecialchars($no) . '</code></td>'
                . '<td>' . htmlspecialchars(isset($inv['invoice_date']) ? $inv['invoice_date'] : '') . '</td>'
                . '<td>' . htmlspecialchars(isset($inv['invoice_amount_novat']) ? $inv['invoice_amount_novat'] : '') . '</td>'
                . '<td>' . htmlspecialchars(isset($inv['invoice_amount_vat']) ? $inv['invoice_amount_vat'] : '') . '</td>'
                . '<td>' . htmlspecialchars(isset($inv['invoice_currency']) ? $inv['invoice_currency'] : '') . '</td>'
                . '<td>' . htmlspecialchars(isset($inv['transactions_number']) ? $inv['transactions_number'] : '') . '</td>'
                . '<td>' . htmlspecialchars(isset($inv['transactions_amount']) ? $inv['transactions_amount'] : '') . '</td>'
                . '<td>' . htmlspecialchars(isset($inv['transferred_amount']) ? $inv['transferred_amount'] : '') . '</td>'
                . '<td><a class="btn btn-xs btn-default" href="' . $modulelink . '&epm_tab=settlements&stlinv='
                . urlencode($no) . '">Transactions</a></td></tr>';
        }
        if (!$invoices) {
            echo '<tr><td colspan="9" class="text-center text-muted">No settlement invoices in range '
                . '(or backoffice credentials missing).</td></tr>';
        }
        echo '</tbody></table>';

        if (!empty($_GET['stlinv'])) {
            $txns = ep_invoice_transactions((string) $_GET['stlinv']);
            $list = isset($txns['success']) && is_array($txns['success']) ? $txns['success'] : [];
            echo '<h4>Transactions in settlement ' . htmlspecialchars((string) $_GET['stlinv']) . '</h4>';
            echo '<table class="table table-striped"><thead><tr>'
                . '<th>EP ID</th><th>RRN</th><th>Amount</th><th>Commission</th><th>Installments</th><th>Type</th>'
                . '</tr></thead><tbody>';
            foreach ($list as $t) {
                echo '<tr><td><code>' . htmlspecialchars(substr((string) $t['epid'], 0, 16)) . '&hellip;</code></td>'
                    . '<td>' . htmlspecialchars((string) $t['rrn']) . '</td>'
                    . '<td>' . htmlspecialchars((string) $t['amount']) . '</td>'
                    . '<td>' . htmlspecialchars((string) $t['commission']) . '</td>'
                    . '<td>' . htmlspecialchars((string) $t['installments']) . '</td>'
                    . '<td><span class="label label-' . ($t['type'] === 'capture' ? 'success' : 'warning') . '">'
                    . htmlspecialchars((string) $t['type']) . '</span></td></tr>';
            }
            if (!$list) {
                echo '<tr><td colspan="6" class="text-center text-muted">No transactions.</td></tr>';
            }
            echo '</tbody></table>';
        }
    } else {
        echo '<div class="alert alert-warning">Set the backoffice user credentials on the gateway first.</div>';
    }
}

function epm_admin_cards($modulelink)
{
    $clientId = (int) (isset($_GET['client_id']) ? $_GET['client_id'] : 0);
    echo '<form method="get" class="form-inline" style="margin-bottom:15px">';
    echo '<input type="hidden" name="m" value="epmanager"><input type="hidden" name="epm_tab" value="cards">';
    echo '<input type="text" name="client_id" class="form-control" placeholder="WHMCS Client ID" value="'
        . $clientId . '"> <button class="btn btn-primary">Load Saved Cards</button></form>';

    if (isset($_GET['remove_card']) && $clientId) {
        $resp = ep_remove_card($clientId, preg_replace('/[^0-9]/', '', (string) $_GET['remove_card']));
        echo ep_ws_ok($resp)
            ? '<div class="alert alert-success">Card removed.</div>'
            : '<div class="alert alert-danger">' . htmlspecialchars(isset($resp['error']) ? $resp['error'] : 'Failed') . '</div>';
    }

    if ($clientId) {
        $resp = ep_saved_cards($clientId);
        $cards = isset($resp['cards']) && is_array($resp['cards']) ? $resp['cards'] : [];
        echo '<table class="table table-striped"><thead><tr>'
            . '<th>Card</th><th>Mask</th><th>Expires</th><th>Actions</th></tr></thead><tbody>';
        foreach ($cards as $card) {
            echo '<tr><td><code>' . htmlspecialchars($card['mask']) . '</code></td>'
                . '<td>' . htmlspecialchars($card['mask']) . '</td>'
                . '<td>' . htmlspecialchars($card['exp']) . '</td><td>'
                . '<a class="btn btn-xs btn-danger" href="' . $modulelink . '&epm_tab=cards&client_id='
                . $clientId . '&remove_card=' . htmlspecialchars($card['id']) . '" '
                . 'onclick="return confirm(\'Remove this saved card?\')">Remove</a></td></tr>';
        }
        if (!$cards) {
            echo '<tr><td colspan="4" class="text-center text-muted">No saved cards for this client.</td></tr>';
        }
        echo '</tbody></table>';
    }
}

function epm_admin_ipn($modulelink)
{
    $page  = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
    $total = Capsule::table('mod_ep_log')->count();
    $rows  = Capsule::table('mod_ep_log')->orderBy('id', 'desc')
        ->offset(($page - 1) * EPM_PER_PAGE)->limit(EPM_PER_PAGE)->get();

    echo '<table class="table table-striped"><thead><tr>'
        . '<th>Time</th><th>Invoice</th><th>Event</th><th>EP ID</th><th>IP</th><th>Payload</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . htmlspecialchars($row->created_at) . '</td>'
            . '<td>' . ($row->invoice_id ? '<a href="invoices.php?action=edit&id=' . (int) $row->invoice_id . '">#' . (int) $row->invoice_id . '</a>' : '-') . '</td>'
            . '<td><span class="label label-' . ($row->event === 'payment_recorded' ? 'success' : 'info') . '">'
            . htmlspecialchars($row->event) . '</span></td>'
            . '<td><code>' . htmlspecialchars(substr($row->ep_id, 0, 16)) . '</code></td>'
            . '<td>' . htmlspecialchars($row->ip) . '</td>'
            . '<td style="word-break:break-all;max-width:350px"><code style="font-size:10px">'
            . htmlspecialchars(substr((string) $row->payload, 0, 300)) . '</code></td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="6" class="text-center text-muted">No IPN events yet.</td></tr>';
    }
    echo '</tbody></table>';
    epm_pager($modulelink, 'ipn', $total, $page);
}
