<?php
/**
 * ServerSpan Identity Verification (Stripe)
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/stripekyc/stripekyc.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Functions.php';

define('SK_VERSION', '1.0.0');
define('SK_PER_PAGE', 20);

function stripekyc_config()
{
    return [
        'name'        => 'ServerSpan Identity Verification (Stripe)',
        'description' => 'KYC identity verification for clients via Stripe Identity hosted sessions: '
            . 'ID document authenticity, optional selfie match, webhook-driven status updates.',
        'author'      => 'ServerSpan',
        'language'    => 'english',
        'version'     => SK_VERSION,
        'fields'      => [
            'secret_key' => [
                'FriendlyName' => 'Stripe Secret Key', 'Type' => 'password', 'Size' => '60',
                'Description' => 'sk_live_... or sk_test_... for testing (test mode simulates the checks).',
            ],
            'webhook_secret' => [
                'FriendlyName' => 'Webhook Signing Secret', 'Type' => 'password', 'Size' => '60',
                'Description' => 'whsec_... of the endpoint pointing at '
                    . 'index.php?m=stripekyc&action=webhook (listen to identity.verification_session.* events).',
            ],
            'require_matching_selfie' => [
                'FriendlyName' => 'Require Matching Selfie', 'Type' => 'yesno',
                'Description' => 'Capture a face image and compare it to the photo ID.',
            ],
            'require_live_capture' => [
                'FriendlyName' => 'Require Live Capture', 'Type' => 'yesno',
                'Description' => 'Disable image uploads; documents must be photographed with the device camera.',
            ],
            'require_before_order' => [
                'FriendlyName' => 'Require Verification Before Ordering', 'Type' => 'yesno',
                'Description' => 'Checkout is blocked until the client has a verified session.',
            ],
            'prompt_clients' => [
                'FriendlyName' => 'Prompt Unverified Clients', 'Type' => 'yesno',
                'Description' => 'Show a banner in the client area until the client verifies.',
            ],
            'verified_group_id' => [
                'FriendlyName' => 'Verified Client Group ID', 'Type' => 'text', 'Size' => '5',
                'Default' => '0', 'Description' => 'Move clients into this group on verification. 0 disables.',
            ],
        ],
    ];
}

function stripekyc_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_stripekyc_sessions')) {
            Capsule::schema()->create('mod_stripekyc_sessions', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('userid')->index();
                $table->unsignedInteger('clientid')->default(0)->index();
                $table->string('session_id', 80)->unique();
                $table->string('session_url', 190)->default('');
                $table->string('status', 30)->default('requires_input')->index();
                $table->string('last_error', 190)->default('');
                $table->boolean('redacted')->default(false);
                $table->dateTime('verified_at')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
        }
        if (!Capsule::schema()->hasTable('mod_stripekyc_log')) {
            Capsule::schema()->create('mod_stripekyc_log', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('userid')->default(0);
                $table->string('session_id', 80)->default('');
                $table->string('event', 60);
                $table->text('detail')->nullable();
                $table->string('ip', 45)->default('');
                $table->dateTime('created_at')->index();
            });
        }
        return ['status' => 'success', 'description' => 'Tables created. Enter your Stripe secret key, '
            . 'then create a webhook endpoint in the Stripe dashboard pointing to '
            . 'index.php?m=stripekyc&action=webhook listening to identity.verification_session.* events.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

function stripekyc_deactivate()
{
    // Tables are preserved so re-activation loses nothing. Drop mod_stripekyc_* manually to reset.
    return ['status' => 'success', 'description' => 'Module deactivated. All mod_stripekyc_* tables were preserved.'];
}

/* ============================================================ admin area */

function stripekyc_output($vars)
{
    $modulelink = $vars['modulelink'];
    $msg = '';
    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sk_do'])) {
        if (function_exists('check_token') && !check_token('WHMCS.admin.default')) {
            $err = 'Invalid CSRF token.';
        } else {
            $sessionId = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $_POST['session_id']);
            if ($_POST['sk_do'] === 'sync') {
                if (sk_sync_session($sessionId)) {
                    $msg = 'Session status refreshed from Stripe.';
                } else {
                    $err = 'Could not refresh the session. Check the secret key and session ID.';
                }
            } elseif ($_POST['sk_do'] === 'redact') {
                list($ok, $error) = sk_redact_session($sessionId);
                if ($ok) {
                    $msg = 'Session redacted at Stripe (PII deletion in progress).';
                } else {
                    $err = 'Redaction failed: ' . $error;
                }
            }
        }
    }

    $tab = isset($_GET['sk_tab']) ? preg_replace('/[^a-z]/', '', $_GET['sk_tab']) : 'sessions';
    $tabs = ['sessions' => 'Sessions', 'log' => 'Log'];

    echo '<h2>ServerSpan Identity Verification (Stripe) <small>v' . SK_VERSION . '</small></h2>';
    if ($msg) {
        echo '<div class="alert alert-success">' . htmlspecialchars($msg) . '</div>';
    }
    if ($err) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($err) . '</div>';
    }

    $counts = Capsule::table('mod_stripekyc_sessions')
        ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
    $verified  = isset($counts['verified']) ? (int) $counts['verified'] : 0;
    $needsIn   = isset($counts['requires_input']) ? (int) $counts['requires_input'] : 0;
    $processing = isset($counts['processing']) ? (int) $counts['processing'] : 0;
    $canceled  = isset($counts['canceled']) ? (int) $counts['canceled'] : 0;
    echo '<p>'
        . '<span class="label label-success">Verified: ' . $verified . '</span> '
        . '<span class="label label-info">Processing: ' . $processing . '</span> '
        . '<span class="label label-warning">Requires Input: ' . $needsIn . '</span> '
        . '<span class="label label-default">Canceled: ' . $canceled . '</span></p>';

    echo '<ul class="nav nav-tabs" style="margin-bottom:20px">';
    foreach ($tabs as $key => $label) {
        $active = $tab === $key ? ' class="active"' : '';
        echo '<li' . $active . '><a href="' . $modulelink . '&sk_tab=' . $key . '">' . $label . '</a></li>';
    }
    echo '</ul>';

    if ($tab === 'log') {
        sk_admin_log($modulelink);
    } else {
        sk_admin_sessions($modulelink);
    }

    echo '<p class="text-muted" style="margin-top:20px">Developed by '
        . '<a href="https://www.serverspan.com/en/tools/index" target="_blank">ServerSpan</a>.</p>';
}

function sk_status_badge($status)
{
    $map = [
        'verified'       => 'success',
        'processing'     => 'info',
        'requires_input' => 'warning',
        'canceled'       => 'default',
    ];
    $cls = isset($map[$status]) ? $map[$status] : 'default';
    return '<span class="label label-' . $cls . '">' . htmlspecialchars($status) . '</span>';
}

function sk_pager($modulelink, $tab, $total, $page)
{
    $pages = max(1, (int) ceil($total / SK_PER_PAGE));
    if ($pages <= 1) {
        return;
    }
    echo '<ul class="pagination">';
    for ($p = 1; $p <= $pages; $p++) {
        $active = $p === $page ? ' class="active"' : '';
        echo '<li' . $active . '><a href="' . $modulelink . '&sk_tab=' . $tab . '&page=' . $p . '">'
            . $p . '</a></li>';
    }
    echo '</ul>';
}

function sk_admin_sessions($modulelink)
{
    $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
    $page   = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));

    $query = Capsule::table('mod_stripekyc_sessions')->orderBy('id', 'desc');
    if ($search !== '') {
        $ids = Capsule::table('tblusers')->where('email', 'like', '%' . $search . '%')->pluck('id');
        $query->whereIn('userid', $ids->count() ? $ids : [0]);
    }
    $total = $query->count();
    $rows  = $query->offset(($page - 1) * SK_PER_PAGE)->limit(SK_PER_PAGE)->get();

    echo '<form method="get" class="form-inline" style="margin-bottom:15px">';
    echo '<input type="hidden" name="m" value="stripekyc">';
    echo '<input type="hidden" name="sk_tab" value="sessions">';
    echo '<input type="text" name="search" class="form-control" placeholder="Search by user email" value="'
        . htmlspecialchars($search) . '"> ';
    echo '<button class="btn btn-default">Search</button></form>';

    echo '<table class="table table-striped table-hover"><thead><tr>'
        . '<th>User</th><th>Client</th><th>Status</th><th>Session</th><th>Last Error</th>'
        . '<th>Created</th><th>Verified</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $u = Capsule::table('tblusers')->where('id', $row->userid)->first();
        $email = $u ? $u->email : '#' . $row->userid;
        $client = $row->clientid
            ? '<a href="clientssummary.php?userid=' . (int) $row->clientid . '">#' . (int) $row->clientid . '</a>'
            : '-';
        $status = sk_status_badge($row->status);
        if ($row->redacted) {
            $status .= ' <span class="label label-default">Redacted</span>';
        }
        echo '<tr><td>' . htmlspecialchars($email) . '</td><td>' . $client . '</td>'
            . '<td>' . $status . '</td>'
            . '<td><code>' . htmlspecialchars(substr($row->session_id, 0, 14)) . '&hellip;</code></td>'
            . '<td>' . htmlspecialchars($row->last_error) . '</td>'
            . '<td>' . htmlspecialchars($row->created_at) . '</td>'
            . '<td>' . ($row->verified_at ? htmlspecialchars($row->verified_at) : '-') . '</td><td>';
        echo '<form method="post" action="' . $modulelink . '" style="display:inline">';
        if (function_exists('generate_token')) {
            echo generate_token();
        }
        echo '<input type="hidden" name="sk_do" value="sync">';
        echo '<input type="hidden" name="session_id" value="' . htmlspecialchars($row->session_id) . '">';
        echo '<button class="btn btn-xs btn-default">Refresh</button></form> ';
        if ($row->status === 'verified' && !$row->redacted) {
            echo '<form method="post" action="' . $modulelink . '" style="display:inline" '
                . 'onsubmit="return confirm(\'Redact this session at Stripe? PII is permanently deleted.\')">';
            if (function_exists('generate_token')) {
                echo generate_token();
            }
            echo '<input type="hidden" name="sk_do" value="redact">';
            echo '<input type="hidden" name="session_id" value="' . htmlspecialchars($row->session_id) . '">';
            echo '<button class="btn btn-xs btn-danger">Redact</button></form>';
        }
        echo '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="8" class="text-center text-muted">No verification sessions yet.</td></tr>';
    }
    echo '</tbody></table>';
    sk_pager($modulelink, 'sessions', $total, $page);
}

function sk_admin_log($modulelink)
{
    $page  = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
    $total = Capsule::table('mod_stripekyc_log')->count();
    $rows  = Capsule::table('mod_stripekyc_log')->orderBy('id', 'desc')
        ->offset(($page - 1) * SK_PER_PAGE)->limit(SK_PER_PAGE)->get();

    echo '<table class="table table-striped"><thead><tr>'
        . '<th>Time</th><th>Event</th><th>User</th><th>Session</th><th>Detail</th><th>IP</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $u = $row->userid ? Capsule::table('tblusers')->where('id', $row->userid)->first() : null;
        echo '<tr><td>' . htmlspecialchars($row->created_at) . '</td>'
            . '<td><span class="label label-info">' . htmlspecialchars($row->event) . '</span></td>'
            . '<td>' . ($u ? htmlspecialchars($u->email) : '-') . '</td>'
            . '<td><code>' . htmlspecialchars(substr($row->session_id, 0, 14)) . '</code></td>'
            . '<td>' . htmlspecialchars((string) $row->detail) . '</td>'
            . '<td>' . htmlspecialchars($row->ip) . '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="6" class="text-center text-muted">No log entries.</td></tr>';
    }
    echo '</tbody></table>';
    sk_pager($modulelink, 'log', $total, $page);
}

/* ========================================================== client area */

function stripekyc_clientarea($vars)
{
    // Public webhook endpoint (no login required).
    if (isset($_GET['action']) && $_GET['action'] === 'webhook') {
        sk_handle_webhook();
        exit;
    }

    $LANG = isset($vars['_lang']) ? $vars['_lang'] : [];
    $userid = (int) \WHMCS\Session::get('uid');
    if (!$userid && !empty($_SESSION['uid'])) {
        $userid = (int) $_SESSION['uid'];
    }

    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['sk_do']) && $_POST['sk_do'] === 'start' && $userid) {
        list($ok, $resp) = sk_start_session($userid);
        if ($ok) {
            header('Location: ' . $resp);
            exit;
        }
        if ($resp !== 'session_finished') {
            $error = $resp;
        }
    }

    // Returning from the hosted flow: sync the open session from the API.
    if ($userid) {
        $open = Capsule::table('mod_stripekyc_sessions')
            ->where('userid', $userid)->whereIn('status', ['requires_input', 'processing'])
            ->orderBy('id', 'desc')->first();
        if ($open && (!isset($_GET['synced']))) {
            sk_sync_session($open->session_id);
        }
    }

    $session  = $userid ? sk_latest_session($userid) : null;
    $verified = $userid ? sk_is_verified($userid) : false;

    return [
        'pagetitle'    => $LANG['page_title'] ?? 'Identity Verification',
        'breadcrumb'   => ['index.php?m=stripekyc' => $LANG['page_title'] ?? 'Identity Verification'],
        'templatefile' => 'kyc',
        'requirelogin' => true,
        'vars'         => [
            'verified'    => $verified,
            'session'     => $session,
            'hasSession'  => (bool) $session,
            'status'      => $session ? $session->status : '',
            'lastError'   => $session ? $session->last_error : '',
            'isProcessing' => $session && $session->status === 'processing',
            'needsInput'  => $session && $session->status === 'requires_input',
            'isCanceled'  => $session && $session->status === 'canceled',
            'error'       => $error,
            'addonLang'   => $LANG,
        ],
    ];
}
