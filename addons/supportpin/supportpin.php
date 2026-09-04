<?php
/**
 * ServerSpan Support PIN (independent recreation)
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/supportpin/supportpin.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Functions.php';

define('PIN_VERSION', '1.0.0');
define('PIN_PER_PAGE', 20);

function supportpin_config()
{
    return [
        'name'        => 'ServerSpan Support PIN',
        'description' => 'Lets clients generate a security PIN so staff can verify the account holder '
            . 'over phone, live chat or any other channel before giving support.',
        'author'      => 'ServerSpan',
        'language'    => 'english',
        'version'     => PIN_VERSION,
        'fields'      => [
            'pin_length' => [
                'FriendlyName' => 'PIN Length', 'Type' => 'dropdown',
                'Options' => '4,5,6,7,8', 'Default' => '6',
            ],
            'expiry_hours' => [
                'FriendlyName' => 'PIN Expiry (hours)', 'Type' => 'text', 'Size' => '5',
                'Default' => '0', 'Description' => '0 = the PIN never expires on its own.',
            ],
            'one_time' => [
                'FriendlyName' => 'One-Time PIN', 'Type' => 'yesno',
                'Description' => 'A PIN stops working after one successful verification; '
                    . 'the client must generate a new one.',
            ],
            'restrict_staff' => [
                'FriendlyName' => 'Restrict Staff Access', 'Type' => 'yesno',
                'Description' => 'Staff can only open client profile pages after verifying '
                    . 'that client\'s PIN (grants temporary access).',
            ],
            'exempt_roles' => [
                'FriendlyName' => 'Exempt Admin Role IDs', 'Type' => 'text', 'Size' => '20',
                'Default' => '1', 'Description' => 'Comma separated admin role IDs that bypass the restriction.',
            ],
            'grant_minutes' => [
                'FriendlyName' => 'Access Grant (minutes)', 'Type' => 'text', 'Size' => '5',
                'Default' => '30', 'Description' => 'How long staff access lasts after a successful verification.',
            ],
            'verify_rate_limit' => [
                'FriendlyName' => 'Max Failed Verifications / 10 min', 'Type' => 'text', 'Size' => '5',
                'Default' => '10', 'Description' => 'Per admin. 0 disables.',
            ],
        ],
    ];
}

function supportpin_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_pin_pins')) {
            Capsule::schema()->create('mod_pin_pins', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('userid')->unique();
                $table->string('pin_hash', 64)->index();
                $table->text('pin_encrypted');
                $table->dateTime('created_at');
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('used_at')->nullable();
            });
        }
        if (!Capsule::schema()->hasTable('mod_pin_grants')) {
            Capsule::schema()->create('mod_pin_grants', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('adminid')->index();
                $table->unsignedInteger('userid');
                $table->unsignedInteger('clientid')->index();
                $table->dateTime('expires_at');
                $table->dateTime('created_at');
            });
        }
        if (!Capsule::schema()->hasTable('mod_pin_log')) {
            Capsule::schema()->create('mod_pin_log', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('userid')->default(0);
                $table->unsignedInteger('adminid')->default(0);
                $table->string('action', 20);
                $table->string('pin_tail', 4)->default('');
                $table->string('ip', 45)->default('');
                $table->dateTime('created_at')->index();
            });
        }
        pin_salt(); // make sure the per-install salt exists
        return ['status' => 'success', 'description' => 'Tables created. Clients can now generate their '
            . 'support PIN from the client area; staff verify it here or from the dashboard widget.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

function supportpin_deactivate()
{
    // Tables are preserved so re-activation loses nothing. Drop mod_pin_* manually to reset.
    return ['status' => 'success', 'description' => 'Module deactivated. All mod_pin_* tables were preserved.'];
}

/* ============================================================ admin area */

function supportpin_output($vars)
{
    $modulelink = $vars['modulelink'];
    $msg = '';
    $err = '';
    $result = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin_action']) && $_POST['pin_action'] === 'verify') {
        if (function_exists('check_token') && !check_token('WHMCS.admin.default')) {
            $err = 'Invalid CSRF token.';
        } else {
            $adminid = (int) (isset($_SESSION['adminid']) ? $_SESSION['adminid'] : 0);
            list($status, $row) = pin_verify($_POST['pin'], $adminid);
            switch ($status) {
                case 'ok':
                    $clientid = pin_grant($adminid, $row->userid);
                    $client = $clientid ? Capsule::table('tblclients')->where('id', $clientid)->first() : null;
                    $result = [
                        'clientid' => $clientid,
                        'name'     => $client ? trim($client->firstname . ' ' . $client->lastname) : '',
                        'email'    => $client ? $client->email : pin_user_email($row->userid),
                        'minutes'  => max(5, (int) pin_setting('grant_minutes', 30)),
                    ];
                    $msg = 'PIN verified.';
                    break;
                case 'used':
                    $err = 'This PIN has already been used. The client must generate a new one.';
                    break;
                case 'expired':
                    $err = 'This PIN has expired. The client must generate a new one.';
                    break;
                case 'ratelimited':
                    $err = 'Too many failed attempts. Wait 10 minutes and try again.';
                    break;
                default:
                    $err = 'Invalid PIN. No client matches this code.';
            }
        }
    }

    $tab = isset($_GET['pin_tab']) ? preg_replace('/[^a-z]/', '', $_GET['pin_tab']) : 'verify';
    $tabs = ['verify' => 'Verify PIN', 'pins' => 'Active PINs', 'log' => 'Log'];

    echo '<h2>ServerSpan Support PIN <small>v' . PIN_VERSION . '</small></h2>';
    if ($msg) {
        echo '<div class="alert alert-success">' . htmlspecialchars($msg) . '</div>';
    }
    if ($err) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($err) . '</div>';
    }
    echo '<ul class="nav nav-tabs" style="margin-bottom:20px">';
    foreach ($tabs as $key => $label) {
        $active = $tab === $key ? ' class="active"' : '';
        echo '<li' . $active . '><a href="' . $modulelink . '&pin_tab=' . $key . '">' . $label . '</a></li>';
    }
    echo '</ul>';

    switch ($tab) {
        case 'pins':
            pin_admin_pins($modulelink);
            break;
        case 'log':
            pin_admin_log($modulelink);
            break;
        default:
            pin_admin_verify($modulelink, $result);
    }

    echo '<p class="text-muted" style="margin-top:20px">Developed by '
        . '<a href="https://www.serverspan.com/en/tools/index" target="_blank">ServerSpan</a>.</p>';
}

function pin_admin_verify($modulelink, $result)
{
    $verifyFor = (int) (isset($_GET['verify_for']) ? $_GET['verify_for'] : 0);
    if ($verifyFor) {
        echo '<div class="alert alert-warning">Access to client #' . $verifyFor
            . ' requires PIN verification. Ask the client for their support PIN.</div>';
    }

    echo '<div class="row"><div class="col-md-5">';
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>Verify a Client</strong></div>'
        . '<div class="panel-body">';
    echo '<form method="post" action="' . $modulelink . '" class="form-inline">';
    if (function_exists('generate_token')) {
        echo generate_token();
    }
    echo '<input type="hidden" name="pin_action" value="verify">';
    echo '<input type="text" name="pin" class="form-control input-lg" placeholder="Support PIN" '
        . 'autocomplete="off" required style="font-size:22px;letter-spacing:6px;text-align:center;max-width:220px"> ';
    echo '<button class="btn btn-primary btn-lg">Verify</button></form>';
    echo '<p class="text-muted" style="margin-top:10px">The client finds the PIN in their client area '
        . 'under Support PIN. One-time PINs stop working after use; expired PINs are rejected.</p>';
    echo '</div></div></div>';

    if ($result) {
        echo '<div class="col-md-7"><div class="panel panel-success"><div class="panel-heading">'
            . '<strong>Verification Successful</strong></div><div class="panel-body">';
        if ($result['clientid']) {
            echo '<p><strong>' . htmlspecialchars($result['name']) . '</strong><br>'
                . htmlspecialchars($result['email']) . '</p>'
                . '<p><a class="btn btn-success" href="clientssummary.php?userid=' . (int) $result['clientid'] . '">'
                . 'Open Client Profile</a></p>'
                . '<p class="text-muted">Staff access to this client\'s profile is granted for '
                . (int) $result['minutes'] . ' minutes.</p>';
        } else {
            echo '<p>PIN belongs to user <strong>' . htmlspecialchars($result['email']) . '</strong>, '
                . 'who is not linked to a client account.</p>';
        }
        echo '</div></div></div>';
    }
    echo '</div>';
}

function pin_pager($modulelink, $tab, $total, $page)
{
    $pages = max(1, (int) ceil($total / PIN_PER_PAGE));
    if ($pages <= 1) {
        return;
    }
    echo '<ul class="pagination">';
    for ($p = 1; $p <= $pages; $p++) {
        $active = $p === $page ? ' class="active"' : '';
        echo '<li' . $active . '><a href="' . $modulelink . '&pin_tab=' . $tab . '&page=' . $p . '">'
            . $p . '</a></li>';
    }
    echo '</ul>';
}

function pin_admin_pins($modulelink)
{
    $page  = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
    $total = Capsule::table('mod_pin_pins')->count();
    $rows  = Capsule::table('mod_pin_pins')->orderBy('id', 'desc')
        ->offset(($page - 1) * PIN_PER_PAGE)->limit(PIN_PER_PAGE)->get();

    $now = date('Y-m-d H:i:s');
    echo '<table class="table table-striped"><thead><tr>'
        . '<th>User</th><th>Client</th><th>Created</th><th>Expires</th><th>Status</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $email    = pin_user_email($row->userid);
        $clientid = pin_user_client($row->userid);
        $client   = $clientid
            ? '<a href="clientssummary.php?userid=' . $clientid . '">#' . $clientid . '</a>'
            : '-';
        if ($row->used_at) {
            $status = '<span class="label label-default">Used</span>';
        } elseif ($row->expires_at && $row->expires_at <= $now) {
            $status = '<span class="label label-danger">Expired</span>';
        } else {
            $status = '<span class="label label-success">Active</span>';
        }
        echo '<tr><td>' . htmlspecialchars($email) . '</td><td>' . $client . '</td>'
            . '<td>' . htmlspecialchars($row->created_at) . '</td>'
            . '<td>' . ($row->expires_at ? htmlspecialchars($row->expires_at) : 'Never') . '</td>'
            . '<td>' . $status . '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="5" class="text-center text-muted">No PINs generated yet.</td></tr>';
    }
    echo '</tbody></table>';
    pin_pager($modulelink, 'pins', $total, $page);
}

function pin_admin_log($modulelink)
{
    $page  = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
    $total = Capsule::table('mod_pin_log')->count();
    $rows  = Capsule::table('mod_pin_log')->orderBy('id', 'desc')
        ->offset(($page - 1) * PIN_PER_PAGE)->limit(PIN_PER_PAGE)->get();

    echo '<table class="table table-striped"><thead><tr>'
        . '<th>Time</th><th>Action</th><th>User</th><th>Admin</th><th>PIN (tail)</th><th>IP</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $user  = $row->userid ? pin_user_email($row->userid) : '-';
        $admin = '-';
        if ($row->adminid) {
            $a = Capsule::table('tbladmins')->where('id', $row->adminid)->first();
            $admin = $a ? $a->username : '#' . $row->adminid;
        }
        $badge = $row->action === 'verify_success' ? 'success'
            : ($row->action === 'verify_fail' ? 'danger' : 'info');
        echo '<tr><td>' . htmlspecialchars($row->created_at) . '</td>'
            . '<td><span class="label label-' . $badge . '">' . htmlspecialchars($row->action) . '</span></td>'
            . '<td>' . htmlspecialchars($user) . '</td><td>' . htmlspecialchars($admin) . '</td>'
            . '<td>' . ($row->pin_tail !== '' ? '...' . htmlspecialchars($row->pin_tail) : '-') . '</td>'
            . '<td>' . htmlspecialchars($row->ip) . '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="6" class="text-center text-muted">No log entries.</td></tr>';
    }
    echo '</tbody></table>';
    pin_pager($modulelink, 'log', $total, $page);
}

/* ========================================================== client area */

function supportpin_clientarea($vars)
{
    $userid = (int) (isset($vars['clientsdetails']['userid']) ? $vars['clientsdetails']['userid'] : 0);
    if (!$userid && !empty($vars['clientsdetails']['email'])) {
        $u = Capsule::table('tblusers')->where('email', $vars['clientsdetails']['email'])->first();
        $userid = $u ? (int) $u->id : 0;
    }

    // AJAX: generate a new PIN without a page reload.
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pin_action']) && $_POST['pin_action'] === 'generate'
        && !empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        if (!$userid) {
            echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
        } else {
            $pin = pin_issue($userid);
            $row = pin_current($userid);
            echo json_encode([
                'ok'        => true,
                'pin'       => $pin,
                'expiresAt' => $row && $row->expires_at ? $row->expires_at : '',
            ]);
        }
        exit;
    }

    $generated = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pin_action']) && $_POST['pin_action'] === 'generate' && $userid) {
        pin_issue($userid); // non-JS fallback
        $generated = true;
    }

    $current = $userid ? pin_current($userid) : null;
    return [
        'pagetitle'    => 'Support PIN',
        'breadcrumb'   => ['index.php?m=supportpin' => 'Support PIN'],
        'templatefile' => 'pin',
        'requirelogin' => true,
        'vars'         => [
            'pin'         => $current ? $current->pin : '',
            'hasPin'      => (bool) $current,
            'isExpired'   => $current ? $current->is_expired : false,
            'isUsed'      => $current ? $current->is_used : false,
            'expiresAt'   => $current && $current->expires_at ? $current->expires_at : '',
            'oneTime'     => pin_setting('one_time', '') === 'on',
            'expiryHours' => (int) pin_setting('expiry_hours', 0),
            'generated'   => $generated,
            'addonLang'   => isset($vars['_lang']) ? $vars['_lang'] : [],
        ],
    ];
}
