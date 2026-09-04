<?php
/**
 * ServerSpan Identity Verification (Didit)
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/diditkyc/diditkyc.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Functions.php';

define('DIDIT_VERSION', '1.0.0');
define('DIDIT_PER_PAGE', 20);

function diditkyc_config()
{
    return [
        'name'        => 'ServerSpan Identity Verification (Didit)',
        'description' => 'KYC identity verification for clients via Didit hosted sessions: '
            . 'ID document, liveness, face match and AML checks with webhook-driven status updates.',
        'author'      => 'ServerSpan',
        'language'    => 'english',
        'version'     => DIDIT_VERSION,
        'fields'      => [
            'api_key' => [
                'FriendlyName' => 'Didit API Key', 'Type' => 'password', 'Size' => '60',
                'Description' => 'Didit Console > API & Webhooks. Needs read:sessions and write:sessions.',
            ],
            'workflow_id' => [
                'FriendlyName' => 'Workflow ID', 'Type' => 'text', 'Size' => '40',
                'Description' => 'Published KYC workflow UUID from Didit Console > Workflows.',
            ],
            'webhook_secret' => [
                'FriendlyName' => 'Webhook Secret', 'Type' => 'password', 'Size' => '60',
                'Description' => 'secret_shared_key of the webhook destination pointing at '
                    . 'index.php?m=diditkyc&action=webhook (subscribe to status.updated).',
            ],
            'require_before_order' => [
                'FriendlyName' => 'Require Verification Before Ordering', 'Type' => 'yesno',
                'Description' => 'Checkout is blocked until the client has an Approved session.',
            ],
            'prompt_clients' => [
                'FriendlyName' => 'Prompt Unverified Clients', 'Type' => 'yesno',
                'Description' => 'Show a banner in the client area until the client verifies.',
            ],
            'verified_group_id' => [
                'FriendlyName' => 'Verified Client Group ID', 'Type' => 'text', 'Size' => '5',
                'Default' => '0', 'Description' => 'Move clients into this group on approval. 0 disables.',
            ],
            'declined_action' => [
                'FriendlyName' => 'On Declined Verification', 'Type' => 'dropdown',
                'Options' => 'none,inactive,closed', 'Default' => 'none',
                'Description' => 'Optionally set the client status when Didit declines verification.',
            ],
        ],
    ];
}

function diditkyc_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_didit_sessions')) {
            Capsule::schema()->create('mod_didit_sessions', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('userid')->index();
                $table->unsignedInteger('clientid')->default(0)->index();
                $table->string('session_id', 64)->unique();
                $table->string('session_url', 190)->default('');
                $table->string('status', 30)->default('Not Started')->index();
                $table->string('vendor_data', 100)->default('');
                $table->longText('decision_json')->nullable();
                $table->dateTime('decided_at')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
        }
        if (!Capsule::schema()->hasTable('mod_didit_log')) {
            Capsule::schema()->create('mod_didit_log', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('userid')->default(0);
                $table->string('session_id', 64)->default('');
                $table->string('event', 30);
                $table->text('detail')->nullable();
                $table->string('ip', 45)->default('');
                $table->dateTime('created_at')->index();
            });
        }
        return ['status' => 'success', 'description' => 'Tables created. Enter your Didit API key and '
            . 'workflow ID, then add a webhook destination in the Didit console pointing to '
            . 'index.php?m=diditkyc&action=webhook subscribed to status.updated.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

function diditkyc_deactivate()
{
    // Tables are preserved so re-activation loses nothing. Drop mod_didit_* manually to reset.
    return ['status' => 'success', 'description' => 'Module deactivated. All mod_didit_* tables were preserved.'];
}

/* ============================================================ admin area */

function diditkyc_output($vars)
{
    $modulelink = $vars['modulelink'];
    $msg = '';
    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['didit_do']) && $_POST['didit_do'] === 'sync') {
        if (function_exists('check_token') && !check_token('WHMCS.admin.default')) {
            $err = 'Invalid CSRF token.';
        } else {
            $sessionId = preg_replace('/[^a-f0-9-]/i', '', (string) $_POST['session_id']);
            if (didit_sync_session($sessionId)) {
                $msg = 'Session status refreshed from Didit.';
            } else {
                $err = 'Could not refresh the session. Check the API key and session ID.';
            }
        }
    }

    $tab = isset($_GET['didit_tab']) ? preg_replace('/[^a-z]/', '', $_GET['didit_tab']) : 'sessions';
    $tabs = ['sessions' => 'Sessions', 'log' => 'Log'];

    echo '<h2>ServerSpan Identity Verification <small>v' . DIDIT_VERSION . '</small></h2>';
    if ($msg) {
        echo '<div class="alert alert-success">' . htmlspecialchars($msg) . '</div>';
    }
    if ($err) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($err) . '</div>';
    }

    $counts = Capsule::table('mod_didit_sessions')
        ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
    $approved  = isset($counts['Approved']) ? (int) $counts['Approved'] : 0;
    $pending   = (int) array_sum(array_intersect_key($counts, array_flip(['Not Started', 'In Progress', 'Resubmitted', 'Awaiting User'])));
    $review    = isset($counts['In Review']) ? (int) $counts['In Review'] : 0;
    $declined  = isset($counts['Declined']) ? (int) $counts['Declined'] : 0;
    echo '<p>'
        . '<span class="label label-success">Approved: ' . $approved . '</span> '
        . '<span class="label label-info">Pending: ' . $pending . '</span> '
        . '<span class="label label-warning">In Review: ' . $review . '</span> '
        . '<span class="label label-danger">Declined: ' . $declined . '</span></p>';

    echo '<ul class="nav nav-tabs" style="margin-bottom:20px">';
    foreach ($tabs as $key => $label) {
        $active = $tab === $key ? ' class="active"' : '';
        echo '<li' . $active . '><a href="' . $modulelink . '&didit_tab=' . $key . '">' . $label . '</a></li>';
    }
    echo '</ul>';

    if ($tab === 'log') {
        didit_admin_log($modulelink);
    } else {
        didit_admin_sessions($modulelink);
    }

    echo '<p class="text-muted" style="margin-top:20px">Developed by '
        . '<a href="https://www.serverspan.com/en/tools/index" target="_blank">ServerSpan</a>.</p>';
}

function didit_status_badge($status)
{
    $map = [
        'Approved'      => 'success',
        'Declined'      => 'danger',
        'In Review'     => 'warning',
        'In Progress'   => 'info',
        'Not Started'   => 'default',
        'Resubmitted'   => 'info',
        'Awaiting User' => 'info',
        'Abandoned'     => 'default',
        'Expired'       => 'default',
        'Kyc Expired'   => 'default',
    ];
    $cls = isset($map[$status]) ? $map[$status] : 'default';
    return '<span class="label label-' . $cls . '">' . htmlspecialchars($status) . '</span>';
}

function didit_pager($modulelink, $tab, $total, $page)
{
    $pages = max(1, (int) ceil($total / DIDIT_PER_PAGE));
    if ($pages <= 1) {
        return;
    }
    echo '<ul class="pagination">';
    for ($p = 1; $p <= $pages; $p++) {
        $active = $p === $page ? ' class="active"' : '';
        echo '<li' . $active . '><a href="' . $modulelink . '&didit_tab=' . $tab . '&page=' . $p . '">'
            . $p . '</a></li>';
    }
    echo '</ul>';
}

function didit_admin_sessions($modulelink)
{
    $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
    $page   = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));

    $query = Capsule::table('mod_didit_sessions')->orderBy('id', 'desc');
    if ($search !== '') {
        $ids = Capsule::table('tblusers')->where('email', 'like', '%' . $search . '%')->pluck('id');
        $query->whereIn('userid', $ids->count() ? $ids : [0]);
    }
    $total = $query->count();
    $rows  = $query->offset(($page - 1) * DIDIT_PER_PAGE)->limit(DIDIT_PER_PAGE)->get();

    echo '<form method="get" class="form-inline" style="margin-bottom:15px">';
    echo '<input type="hidden" name="m" value="diditkyc">';
    echo '<input type="hidden" name="didit_tab" value="sessions">';
    echo '<input type="text" name="search" class="form-control" placeholder="Search by user email" value="'
        . htmlspecialchars($search) . '"> ';
    echo '<button class="btn btn-default">Search</button></form>';

    echo '<table class="table table-striped table-hover"><thead><tr>'
        . '<th>User</th><th>Client</th><th>Status</th><th>Session</th><th>Created</th>'
        . '<th>Decided</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $u = Capsule::table('tblusers')->where('id', $row->userid)->first();
        $email = $u ? $u->email : '#' . $row->userid;
        $client = $row->clientid
            ? '<a href="clientssummary.php?userid=' . (int) $row->clientid . '">#' . (int) $row->clientid . '</a>'
            : '-';
        echo '<tr><td>' . htmlspecialchars($email) . '</td><td>' . $client . '</td>'
            . '<td>' . didit_status_badge($row->status) . '</td>'
            . '<td><code>' . htmlspecialchars(substr($row->session_id, 0, 8)) . '&hellip;</code></td>'
            . '<td>' . htmlspecialchars($row->created_at) . '</td>'
            . '<td>' . ($row->decided_at ? htmlspecialchars($row->decided_at) : '-') . '</td><td>';
        echo '<form method="post" action="' . $modulelink . '" style="display:inline">';
        if (function_exists('generate_token')) {
            echo generate_token();
        }
        echo '<input type="hidden" name="didit_do" value="sync">';
        echo '<input type="hidden" name="session_id" value="' . htmlspecialchars($row->session_id) . '">';
        echo '<button class="btn btn-xs btn-default">Refresh</button></form> ';
        if ($row->session_url) {
            echo '<a class="btn btn-xs btn-info" href="' . htmlspecialchars($row->session_url)
                . '" target="_blank">Open</a>';
        }
        echo '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="7" class="text-center text-muted">No verification sessions yet.</td></tr>';
    }
    echo '</tbody></table>';
    didit_pager($modulelink, 'sessions', $total, $page);
}

function didit_admin_log($modulelink)
{
    $page  = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
    $total = Capsule::table('mod_didit_log')->count();
    $rows  = Capsule::table('mod_didit_log')->orderBy('id', 'desc')
        ->offset(($page - 1) * DIDIT_PER_PAGE)->limit(DIDIT_PER_PAGE)->get();

    echo '<table class="table table-striped"><thead><tr>'
        . '<th>Time</th><th>Event</th><th>User</th><th>Session</th><th>Detail</th><th>IP</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $u = $row->userid ? Capsule::table('tblusers')->where('id', $row->userid)->first() : null;
        echo '<tr><td>' . htmlspecialchars($row->created_at) . '</td>'
            . '<td><span class="label label-info">' . htmlspecialchars($row->event) . '</span></td>'
            . '<td>' . ($u ? htmlspecialchars($u->email) : '-') . '</td>'
            . '<td><code>' . htmlspecialchars(substr($row->session_id, 0, 8)) . '</code></td>'
            . '<td>' . htmlspecialchars((string) $row->detail) . '</td>'
            . '<td>' . htmlspecialchars($row->ip) . '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="6" class="text-center text-muted">No log entries.</td></tr>';
    }
    echo '</tbody></table>';
    didit_pager($modulelink, 'log', $total, $page);
}

/* ========================================================== client area */

function diditkyc_clientarea($vars)
{
    // Public webhook endpoint (no login required).
    if (isset($_GET['action']) && $_GET['action'] === 'webhook') {
        didit_handle_webhook();
        exit;
    }

    $LANG = isset($vars['_lang']) ? $vars['_lang'] : [];
    $userid = (int) \WHMCS\Session::get('uid');
    if (!$userid && !empty($_SESSION['uid'])) {
        $userid = (int) $_SESSION['uid'];
    }

    $error = '';

    // Start a new session.
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['didit_do']) && $_POST['didit_do'] === 'start' && $userid) {
        $language = null;
        $pref = didit_setting('session_language', 'auto');
        if ($pref !== 'auto' && $pref !== '') {
            $language = $pref;
        } elseif (!empty($vars['language'])) {
            $language = didit_language_code($vars['language']);
        }
        list($ok, $resp) = didit_create_session($userid, $language);
        if ($ok) {
            header('Location: ' . $resp['url']);
            exit;
        }
        $error = $resp;
    }

    // Returning from the hosted flow: trust the API over the query string.
    if (!empty($_GET['verificationSessionId']) && $userid) {
        $sessionId = preg_replace('/[^a-f0-9-]/i', '', (string) $_GET['verificationSessionId']);
        if ($sessionId) {
            didit_sync_session($sessionId);
        }
    }

    $session = $userid ? didit_latest_session($userid) : null;
    $approved = $userid ? didit_is_approved($userid) : false;

    return [
        'pagetitle'    => $LANG['page_title'] ?? 'Identity Verification',
        'breadcrumb'   => ['index.php?m=diditkyc' => $LANG['page_title'] ?? 'Identity Verification'],
        'templatefile' => 'kyc',
        'requirelogin' => true,
        'vars'         => [
            'approved'     => $approved,
            'session'      => $session,
            'hasSession'   => (bool) $session,
            'status'       => $session ? $session->status : '',
            'sessionUrl'   => $session ? $session->session_url : '',
            'canRestart'   => $session && in_array($session->status,
                ['Declined', 'Abandoned', 'Expired', 'Kyc Expired'], true),
            'isPending'    => $session && in_array($session->status,
                ['Not Started', 'In Progress', 'Resubmitted', 'Awaiting User'], true),
            'isReview'     => $session && $session->status === 'In Review',
            'error'        => $error,
            'addonLang'    => $LANG,
        ],
    ];
}
