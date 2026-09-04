<?php
/**
 * ServerSpan ownCloud Manager
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/ocmanager/ocmanager.php
 *
 * Admin-side management of ownCloud installations: users, groups, quotas,
 * sub-admins and reseller group limits — across every configured server.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Functions.php';

define('OCM_VERSION', '1.0.0');
define('OCM_PER_PAGE', 25);

function ocmanager_config()
{
    return [
        'name'        => 'ServerSpan ownCloud Manager',
        'description' => 'Administer ownCloud users, groups, quotas and reseller group limits '
            . 'across all configured servers, without logging into ownCloud.',
        'author'      => 'ServerSpan',
        'language'    => 'english',
        'version'     => OCM_VERSION,
        'fields'      => [
            'default_server_id' => [
                'FriendlyName' => 'Default Server ID', 'Type' => 'text', 'Size' => '5', 'Default' => '0',
                'Description' => 'WHMCS server ID (type ocstorage) preselected in the tabs. 0 = first.',
            ],
        ],
    ];
}

function ocmanager_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_oc_grouplimits')) {
            Capsule::schema()->create('mod_oc_grouplimits', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('server_id')->index();
                $table->string('groupname', 100);
                $table->unsignedInteger('limit_gb')->default(0);
                $table->dateTime('created_at');
                $table->unique(['server_id', 'groupname']);
            });
        }
        if (!Capsule::schema()->hasTable('mod_oc_log')) {
            Capsule::schema()->create('mod_oc_log', function ($table) {
                $table->increments('id');
                $table->string('action', 40);
                $table->unsignedInteger('server_id')->default(0);
                $table->string('subject', 100)->default('');
                $table->text('detail')->nullable();
                $table->dateTime('created_at')->index();
            });
        }
        return ['status' => 'success', 'description' => 'Tables created. Add ownCloud servers under '
            . 'System Settings > Servers (type: ServerSpan ownCloud Storage) first.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

function ocmanager_deactivate()
{
    return ['status' => 'success', 'description' => 'Module deactivated. All mod_oc_* tables were preserved.'];
}

/* ============================================================ admin area */

function ocmanager_output($vars)
{
    $modulelink = $vars['modulelink'];
    $msg = '';
    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ocm_do'])) {
        if (function_exists('check_token') && !check_token('WHMCS.admin.default')) {
            $err = 'Invalid CSRF token.';
        } else {
            list($msg, $err) = ocm_handle_post();
        }
    }

    $tab = isset($_GET['ocm_tab']) ? preg_replace('/[^a-z]/', '', $_GET['ocm_tab']) : 'users';
    $tabs = [
        'users'  => 'Users',
        'groups' => 'Groups',
        'limits' => 'Group Limits',
        'log'    => 'Log',
    ];

    echo '<h2>ServerSpan ownCloud Manager <small>v' . OCM_VERSION . '</small></h2>';
    if ($msg) {
        echo '<div class="alert alert-success">' . htmlspecialchars($msg) . '</div>';
    }
    if ($err) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($err) . '</div>';
    }
    $servers = ocm_servers();
    if (!$servers) {
        echo '<div class="alert alert-warning">No ownCloud servers configured. Add one under '
            . '<a href="configservers.php">System Settings &gt; Servers</a> (type: ServerSpan ownCloud Storage).</div>';
    }
    echo '<ul class="nav nav-tabs" style="margin-bottom:20px">';
    foreach ($tabs as $key => $label) {
        $active = $tab === $key ? ' class="active"' : '';
        echo '<li' . $active . '><a href="' . $modulelink . '&ocm_tab=' . $key . '">' . $label . '</a></li>';
    }
    echo '</ul>';

    switch ($tab) {
        case 'groups':
            ocm_admin_groups($modulelink);
            break;
        case 'limits':
            ocm_admin_limits($modulelink);
            break;
        case 'log':
            ocm_admin_log($modulelink);
            break;
        default:
            ocm_admin_users($modulelink);
    }

    echo '<p class="text-muted" style="margin-top:20px">Developed by '
        . '<a href="https://www.serverspan.com/en/tools/index" target="_blank">ServerSpan</a>.</p>';
}

function ocm_token()
{
    return function_exists('generate_token') ? generate_token() : '';
}

function ocm_current_server()
{
    $id = (int) (isset($_REQUEST['server_id']) ? $_REQUEST['server_id'] : 0);
    return ocm_server($id);
}

function ocm_server_picker($modulelink, $tab)
{
    $current = ocm_current_server();
    echo '<form method="get" class="form-inline" style="margin-bottom:15px">';
    echo '<input type="hidden" name="m" value="ocmanager">';
    echo '<input type="hidden" name="ocm_tab" value="' . $tab . '">';
    echo '<label>Server</label> <select name="server_id" class="form-control" onchange="this.form.submit()">';
    foreach (ocm_servers() as $id => $srv) {
        echo '<option value="' . (int) $id . '"' . ($current && $current->id == $id ? ' selected' : '') . '>'
            . htmlspecialchars($srv->name ?: $srv->hostname) . '</option>';
    }
    echo '</select></form>';
}

function ocm_handle_post()
{
    $server = ocm_current_server();
    if (!$server) {
        return ['', 'No ownCloud server configured.'];
    }
    $do = $_POST['ocm_do'];
    $uid = isset($_POST['userid']) ? trim((string) $_POST['userid']) : '';
    $gid = isset($_POST['groupid']) ? trim((string) $_POST['groupid']) : '';

    switch ($do) {
        case 'create_user':
            $username = trim((string) $_POST['new_username']);
            $password = trim((string) $_POST['new_password']);
            $email    = trim((string) $_POST['new_email']);
            $quota    = ocm_quota(isset($_POST['new_quota']) ? $_POST['new_quota'] : '');
            if (!$username || !$password) {
                return ['', 'Username and password are required.'];
            }
            $groups = array_filter(array_map('trim', explode(',', (string) $_POST['new_groups'])));
            foreach ($groups as $g) {
                ocm_api($server, 'POST', '/groups', ['groupid' => $g]); // idempotent
            }
            $fields = ['userid' => $username, 'password' => $password];
            if ($email) {
                $fields['email'] = $email;
            }
            if ($quota !== 'default') {
                $fields['quota'] = $quota;
            }
            foreach (array_values($groups) as $i => $g) {
                $fields['groups[' . $i . ']'] = $g;
            }
            list($http, $status, , $message) = ocm_api($server, 'POST', '/users', $fields);
            if (!ocm_ok($status)) {
                return ['', ocm_fail($http, $status, $message)];
            }
            ocm_log('user_created', $server->id, $username, '');
            // Optionally place a WHMCS order for the account (listing feature).
            if (!empty($_POST['create_order']) && (int) $_POST['order_client'] && (int) $_POST['order_product']) {
                $r = localAPI('AddOrder', [
                    'clientid'        => (int) $_POST['order_client'],
                    'pid'             => (int) $_POST['order_product'],
                    'paymentmethod'   => isset($_POST['order_gateway']) ? $_POST['order_gateway'] : 'banktransfer',
                    'noinvoice'       => true,
                    'noinvoiceemail'  => true,
                ]);
                if (!empty($r['result']) && $r['result'] === 'success') {
                    if (!empty($_POST['order_accept']) && !empty($r['orderid'])) {
                        localAPI('AcceptOrder', ['orderid' => $r['orderid'], 'autosetup' => true]);
                    }
                    ocm_log('order_created', $server->id, $username, 'order #' . $r['orderid']);
                    return ['User created and order #' . $r['orderid'] . ' placed.', ''];
                }
                return ['', 'User created, but the order failed: ' . (isset($r['message']) ? $r['message'] : 'unknown')];
            }
            return ['User "' . $username . '" created.', ''];

        case 'edit_user':
            $fields = ['quota' => 'new_quota', 'email' => 'new_email', 'display' => 'new_display', 'password' => 'new_password'];
            $done = [];
            foreach ($fields as $key => $postKey) {
                $value = trim((string) (isset($_POST[$postKey]) ? $_POST[$postKey] : ''));
                if ($value === '') {
                    continue;
                }
                if ($key === 'quota') {
                    $value = ocm_quota($value);
                }
                list($http, $status, , $message) = ocm_api($server, 'PUT', '/users/' . rawurlencode($uid), ['key' => $key, 'value' => $value]);
                if (!ocm_ok($status)) {
                    return ['', 'Setting ' . $key . ' failed — ' . ocm_fail($http, $status, $message)];
                }
                $done[] = $key;
            }
            if (!$done) {
                return ['', 'Nothing to update.'];
            }
            ocm_log('user_updated', $server->id, $uid, implode(',', $done));
            return ['User "' . $uid . '" updated (' . implode(', ', $done) . ').', ''];

        case 'disable_user':
        case 'enable_user':
            list($http, $status, , $message) = ocm_api($server, 'PUT',
                '/users/' . rawurlencode($uid) . ($do === 'enable_user' ? '/enable' : '/disable'));
            if (!ocm_ok($status)) {
                return ['', ocm_fail($http, $status, $message)];
            }
            ocm_log($do === 'enable_user' ? 'user_enabled' : 'user_disabled', $server->id, $uid, '');
            return ['User "' . $uid . '" ' . ($do === 'enable_user' ? 'enabled' : 'disabled') . '.', ''];

        case 'delete_user':
            list($http, $status, , $message) = ocm_api($server, 'DELETE', '/users/' . rawurlencode($uid));
            if (!ocm_ok($status) && $status !== 101) {
                return ['', ocm_fail($http, $status, $message)];
            }
            ocm_log('user_deleted', $server->id, $uid, '');
            return ['User "' . $uid . '" deleted.', ''];

        case 'group_add_user':
            ocm_api($server, 'POST', '/groups', ['groupid' => $gid]);
            list($http, $status, , $message) = ocm_api($server, 'POST', '/users/' . rawurlencode($uid) . '/groups', ['groupid' => $gid]);
            if (!ocm_ok($status)) {
                return ['', ocm_fail($http, $status, $message)];
            }
            return ['Added "' . $uid . '" to group "' . $gid . '".', ''];

        case 'group_remove_user':
            list($http, $status, , $message) = ocm_api($server, 'DELETE',
                '/users/' . rawurlencode($uid) . '/groups?groupid=' . urlencode($gid));
            if (!ocm_ok($status)) {
                return ['', ocm_fail($http, $status, $message)];
            }
            return ['Removed "' . $uid . '" from group "' . $gid . '".', ''];

        case 'subadmin_add':
        case 'subadmin_remove':
            list($http, $status, , $message) = ocm_api($server,
                $do === 'subadmin_add' ? 'POST' : 'DELETE',
                '/users/' . rawurlencode($uid) . '/subadmins' . ($do === 'subadmin_add' ? '' : '?groupid=' . urlencode($gid)),
                $do === 'subadmin_add' ? ['groupid' => $gid] : null);
            if (!ocm_ok($status)) {
                return ['', ocm_fail($http, $status, $message)];
            }
            ocm_log($do, $server->id, $uid, $gid);
            return ['Sub-admin updated.', ''];

        case 'create_group':
            list($http, $status, , $message) = ocm_api($server, 'POST', '/groups', ['groupid' => $gid]);
            if (!ocm_ok($status)) {
                return ['', ocm_fail($http, $status, $message)];
            }
            ocm_log('group_created', $server->id, $gid, '');
            return ['Group "' . $gid . '" created.', ''];

        case 'delete_group':
            list($http, $status, , $message) = ocm_api($server, 'DELETE', '/groups/' . rawurlencode($gid));
            if (!ocm_ok($status)) {
                return ['', ocm_fail($http, $status, $message)];
            }
            ocm_log('group_deleted', $server->id, $gid, '');
            return ['Group "' . $gid . '" deleted.', ''];

        case 'set_limit':
            $limit = (int) $_POST['limit_gb'];
            Capsule::table('mod_oc_grouplimits')->updateOrInsert(
                ['server_id' => (int) $_POST['limit_server'], 'groupname' => $gid],
                ['limit_gb' => $limit, 'created_at' => date('Y-m-d H:i:s')]
            );
            return ['Group limit saved.', ''];

        case 'delete_limit':
            Capsule::table('mod_oc_grouplimits')->where('id', (int) $_POST['id'])->delete();
            return ['Group limit removed.', ''];
    }
    return ['', ''];
}

/* --------------------------------------------------------------- users tab */

function ocm_admin_users($modulelink)
{
    ocm_server_picker($modulelink, 'users');
    $server = ocm_current_server();
    if (!$server) {
        return;
    }
    $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
    $page   = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));

    list($http, $status, $data, $message) = ocm_api($server, 'GET',
        '/users?search=' . urlencode($search) . '&limit=' . OCM_PER_PAGE . '&offset=' . (($page - 1) * OCM_PER_PAGE));
    $users = ($http === 200 && ocm_ok($status) && isset($data['users'])) ? (array) $data['users'] : [];

    // Create-user form (with optional WHMCS order).
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>Add ownCloud User</strong></div><div class="panel-body">';
    echo '<form method="post" action="' . $modulelink . '" class="form-inline">' . ocm_token();
    echo '<input type="hidden" name="ocm_do" value="create_user">';
    echo '<input type="hidden" name="server_id" value="' . (int) $server->id . '">';
    echo '<input type="text" name="new_username" class="form-control" placeholder="Username" required> ';
    echo '<input type="password" name="new_password" class="form-control" placeholder="Password" required> ';
    echo '<input type="text" name="new_email" class="form-control" placeholder="Email"> ';
    echo '<input type="text" name="new_quota" class="form-control" placeholder="Quota (e.g. 2GB)" size="10"> ';
    echo '<input type="text" name="new_groups" class="form-control" placeholder="Groups, comma separated"> ';
    echo '<button class="btn btn-primary">Create User</button>';
    echo '<div style="margin-top:10px"><label><input type="checkbox" name="create_order" value="1"> '
        . 'Also place a WHMCS order</label> — client ID '
        . '<input type="text" name="order_client" class="form-control" size="6"> product ID '
        . '<input type="text" name="order_product" class="form-control" size="6"> '
        . '<label><input type="checkbox" name="order_accept" value="1"> accept + autosetup</label></div>';
    echo '</form></div></div>';

    echo '<form method="get" class="form-inline" style="margin-bottom:15px">';
    echo '<input type="hidden" name="m" value="ocmanager"><input type="hidden" name="ocm_tab" value="users">';
    echo '<input type="hidden" name="server_id" value="' . (int) $server->id . '">';
    echo '<input type="text" name="search" class="form-control" placeholder="Search users" value="' . htmlspecialchars($search) . '"> ';
    echo '<button class="btn btn-default">Search</button></form>';

    echo '<table class="table table-striped"><thead><tr><th>Username</th><th>Actions</th></tr></thead><tbody>';
    foreach ($users as $u) {
        echo '<tr><td><a href="' . $modulelink . '&ocm_tab=users&server_id=' . (int) $server->id
            . '&manage=' . urlencode($u) . '"><code>' . htmlspecialchars($u) . '</code></a></td><td>'
            . '<a class="btn btn-xs btn-default" href="' . $modulelink . '&ocm_tab=users&server_id='
            . (int) $server->id . '&manage=' . urlencode($u) . '">Manage</a></td></tr>';
    }
    if (!$users) {
        echo '<tr><td colspan="2" class="text-center text-muted">No users found.</td></tr>';
    }
    echo '</tbody></table>';

    echo '<ul class="pagination">';
    if ($page > 1) {
        echo '<li><a href="' . $modulelink . '&ocm_tab=users&server_id=' . (int) $server->id . '&page='
            . ($page - 1) . '&search=' . urlencode($search) . '">&laquo; Prev</a></li>';
    }
    if (count($users) === OCM_PER_PAGE) {
        echo '<li><a href="' . $modulelink . '&ocm_tab=users&server_id=' . (int) $server->id . '&page='
            . ($page + 1) . '&search=' . urlencode($search) . '">Next &raquo;</a></li>';
    }
    echo '</ul>';

    if (!empty($_GET['manage'])) {
        ocm_user_panel($modulelink, $server, trim((string) $_GET['manage']));
    }
}

function ocm_user_panel($modulelink, $server, $userid)
{
    list($http, $status, $u) = ocm_api($server, 'GET', '/users/' . rawurlencode($userid));
    if (!($http === 200 && ocm_ok($status))) {
        echo '<div class="alert alert-danger">User not found on this server.</div>';
        return;
    }
    list(, , $gdata) = ocm_api($server, 'GET', '/users/' . rawurlencode($userid) . '/groups');
    $groups = isset($gdata['groups']) ? (array) $gdata['groups'] : [];
    list(, , $sdata) = ocm_api($server, 'GET', '/users/' . rawurlencode($userid) . '/subadmins');
    $subadmin = isset($sdata['groups']) ? (array) $sdata['groups'] : (isset($sdata) && is_array($sdata) ? $sdata : []);

    $quotaTotal = isset($u['quota']['total']) ? $u['quota']['total'] : '';
    $quotaUsed  = isset($u['quota']['used']) ? $u['quota']['used'] : '';
    $email      = isset($u['email']) ? $u['email'] : '';
    $display    = isset($u['displayname']) ? $u['displayname'] : (isset($u['display-name']) ? $u['display-name'] : '');
    $enabled    = !isset($u['enabled']) || $u['enabled'] === true || $u['enabled'] === 'true';

    echo '<div class="panel panel-info"><div class="panel-heading"><strong>Manage: '
        . htmlspecialchars($userid) . '</strong></div><div class="panel-body">';
    echo '<p>Status: ' . ($enabled ? '<span class="label label-success">Enabled</span>' : '<span class="label label-warning">Disabled</span>')
        . ' · Email: ' . htmlspecialchars((string) $email)
        . ' · Quota: ' . htmlspecialchars((string) $quotaUsed) . ' / ' . htmlspecialchars((string) $quotaTotal) . '</p>';

    echo '<form method="post" action="' . $modulelink . '" class="form-inline well">' . ocm_token();
    echo '<input type="hidden" name="ocm_do" value="edit_user">';
    echo '<input type="hidden" name="server_id" value="' . (int) $server->id . '">';
    echo '<input type="hidden" name="userid" value="' . htmlspecialchars($userid) . '">';
    echo '<input type="text" name="new_quota" class="form-control" placeholder="New quota" size="8"> ';
    echo '<input type="text" name="new_email" class="form-control" placeholder="New email"> ';
    echo '<input type="text" name="new_display" class="form-control" placeholder="New display name"> ';
    echo '<input type="password" name="new_password" class="form-control" placeholder="New password"> ';
    echo '<button class="btn btn-primary">Update</button> <span class="text-muted">(only filled fields change)</span></form>';

    echo '<p style="white-space:nowrap">';
    foreach (['enable_user' => ['Enable', 'success', $enabled], 'disable_user' => ['Disable', 'warning', !$enabled]] as $act => $cfg) {
        if ($cfg[2]) {
            continue;
        }
        echo '<form method="post" action="' . $modulelink . '" style="display:inline">' . ocm_token()
            . '<input type="hidden" name="ocm_do" value="' . $act . '">'
            . '<input type="hidden" name="server_id" value="' . (int) $server->id . '">'
            . '<input type="hidden" name="userid" value="' . htmlspecialchars($userid) . '">'
            . '<button class="btn btn-' . $cfg[1] . ' btn-sm">' . $cfg[0] . '</button></form> ';
    }
    echo '<form method="post" action="' . $modulelink . '" style="display:inline" '
        . 'onsubmit="return confirm(\'Delete user ' . htmlspecialchars($userid) . '? This removes their files.\')">' . ocm_token()
        . '<input type="hidden" name="ocm_do" value="delete_user">'
        . '<input type="hidden" name="server_id" value="' . (int) $server->id . '">'
        . '<input type="hidden" name="userid" value="' . htmlspecialchars($userid) . '">'
        . '<button class="btn btn-danger btn-sm">Delete</button></form></p>';

    echo '<p>Groups: ';
    foreach ($groups as $g) {
        echo '<form method="post" action="' . $modulelink . '" style="display:inline">' . ocm_token()
            . '<input type="hidden" name="ocm_do" value="group_remove_user">'
            . '<input type="hidden" name="server_id" value="' . (int) $server->id . '">'
            . '<input type="hidden" name="userid" value="' . htmlspecialchars($userid) . '">'
            . '<input type="hidden" name="groupid" value="' . htmlspecialchars($g) . '">'
            . '<button class="btn btn-xs btn-default" title="Remove from group">' . htmlspecialchars($g)
            . ' &times;</button></form> ';
    }
    echo '</p><form method="post" action="' . $modulelink . '" class="form-inline">' . ocm_token()
        . '<input type="hidden" name="ocm_do" value="group_add_user">'
        . '<input type="hidden" name="server_id" value="' . (int) $server->id . '">'
        . '<input type="hidden" name="userid" value="' . htmlspecialchars($userid) . '">'
        . '<input type="text" name="groupid" class="form-control" placeholder="Add to group"> '
        . '<button class="btn btn-default btn-sm">Add</button></form>';

    echo '<p>Sub-admin of: ' . ($subadmin ? htmlspecialchars(implode(', ', $subadmin)) : '—') . '</p>';
    echo '<form method="post" action="' . $modulelink . '" class="form-inline">' . ocm_token()
        . '<input type="hidden" name="server_id" value="' . (int) $server->id . '">'
        . '<input type="hidden" name="userid" value="' . htmlspecialchars($userid) . '">'
        . '<input type="text" name="groupid" class="form-control" placeholder="Group"> '
        . '<button class="btn btn-default btn-sm" name="ocm_do" value="subadmin_add">Make Sub-admin</button> '
        . '<button class="btn btn-default btn-sm" name="ocm_do" value="subadmin_remove">Remove Sub-admin</button></form>';

    echo '</div></div>';
}

/* -------------------------------------------------------------- groups tab */

function ocm_admin_groups($modulelink)
{
    ocm_server_picker($modulelink, 'groups');
    $server = ocm_current_server();
    if (!$server) {
        return;
    }
    list($http, $status, $data) = ocm_api($server, 'GET', '/groups');
    $groups = ($http === 200 && ocm_ok($status) && isset($data['groups'])) ? (array) $data['groups'] : [];

    echo '<form method="post" action="' . $modulelink . '" class="form-inline well">' . ocm_token();
    echo '<input type="hidden" name="ocm_do" value="create_group">';
    echo '<input type="hidden" name="server_id" value="' . (int) $server->id . '">';
    echo '<input type="text" name="groupid" class="form-control" placeholder="New group name" required> ';
    echo '<button class="btn btn-primary">Create Group</button></form>';

    echo '<table class="table table-striped"><thead><tr><th>Group</th><th>Members</th><th>Sub-admins</th><th>Actions</th></tr></thead><tbody>';
    foreach ($groups as $g) {
        list(, , $members) = ocm_api($server, 'GET', '/groups/' . rawurlencode($g));
        $memberList = isset($members['users']) ? (array) $members['users'] : [];
        list(, , $subs) = ocm_api($server, 'GET', '/groups/' . rawurlencode($g) . '/subadmins');
        $subList = isset($subs['subadmins']) ? (array) $subs['subadmins'] : [];
        echo '<tr><td><code>' . htmlspecialchars($g) . '</code></td>'
            . '<td>' . count($memberList) . ($memberList ? ' (' . htmlspecialchars(implode(', ', array_slice($memberList, 0, 5))) . (count($memberList) > 5 ? '…' : '') . ')' : '') . '</td>'
            . '<td>' . ($subList ? htmlspecialchars(implode(', ', $subList)) : '—') . '</td><td>';
        echo '<form method="post" action="' . $modulelink . '" style="display:inline" '
            . 'onsubmit="return confirm(\'Delete group ' . htmlspecialchars($g) . '?\')">' . ocm_token()
            . '<input type="hidden" name="ocm_do" value="delete_group">'
            . '<input type="hidden" name="server_id" value="' . (int) $server->id . '">'
            . '<input type="hidden" name="groupid" value="' . htmlspecialchars($g) . '">'
            . '<button class="btn btn-xs btn-danger">Delete</button></form></td></tr>';
    }
    if (!$groups) {
        echo '<tr><td colspan="4" class="text-center text-muted">No groups.</td></tr>';
    }
    echo '</tbody></table>';
}

/* -------------------------------------------------------------- limits tab */

function ocm_admin_limits($modulelink)
{
    echo '<p class="text-muted">ownCloud has no native per-group quota; limits recorded here are applied '
        . 'by the provisioning module when reseller sub-accounts are created. The original module '
        . 'enforced them with a custom ownCloud app — see the README.</p>';

    echo '<form method="post" action="' . $modulelink . '" class="form-inline well">' . ocm_token();
    echo '<input type="hidden" name="ocm_do" value="set_limit">';
    echo '<select name="limit_server" class="form-control">';
    foreach (ocm_servers() as $id => $srv) {
        echo '<option value="' . (int) $id . '">' . htmlspecialchars($srv->name ?: $srv->hostname) . '</option>';
    }
    echo '</select> ';
    echo '<input type="text" name="groupid" class="form-control" placeholder="Group name" required> ';
    echo '<input type="number" name="limit_gb" class="form-control" placeholder="Limit (GB)" min="0" required> ';
    echo '<button class="btn btn-primary">Save Limit</button></form>';

    $rows = Capsule::table('mod_oc_grouplimits')->orderBy('server_id')->orderBy('groupname')->get();
    $servers = ocm_servers();
    echo '<table class="table table-striped"><thead><tr><th>Server</th><th>Group</th><th>Limit (GB)</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $srv = isset($servers[$row->server_id]) ? $servers[$row->server_id] : null;
        echo '<tr><td>' . htmlspecialchars($srv ? ($srv->name ?: $srv->hostname) : '#' . $row->server_id) . '</td>'
            . '<td><code>' . htmlspecialchars($row->groupname) . '</code></td>'
            . '<td>' . (int) $row->limit_gb . '</td><td>' . htmlspecialchars($row->created_at) . '</td><td>';
        echo '<form method="post" action="' . $modulelink . '" style="display:inline">' . ocm_token()
            . '<input type="hidden" name="ocm_do" value="delete_limit">'
            . '<input type="hidden" name="id" value="' . (int) $row->id . '">'
            . '<button class="btn btn-xs btn-danger">Delete</button></form></td></tr>';
    }
    if (!$rows->count()) {
        echo '<tr><td colspan="5" class="text-center text-muted">No limits defined.</td></tr>';
    }
    echo '</tbody></table>';
}

/* ----------------------------------------------------------------- log tab */

function ocm_admin_log($modulelink)
{
    $page  = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
    $total = Capsule::table('mod_oc_log')->count();
    $rows  = Capsule::table('mod_oc_log')->orderBy('id', 'desc')
        ->offset(($page - 1) * OCM_PER_PAGE)->limit(OCM_PER_PAGE)->get();

    echo '<table class="table table-striped"><thead><tr>'
        . '<th>Time</th><th>Action</th><th>Server</th><th>Subject</th><th>Detail</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . htmlspecialchars($row->created_at) . '</td>'
            . '<td><span class="label label-info">' . htmlspecialchars($row->action) . '</span></td>'
            . '<td>' . (int) $row->server_id . '</td>'
            . '<td>' . htmlspecialchars($row->subject) . '</td>'
            . '<td>' . htmlspecialchars((string) $row->detail) . '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="5" class="text-center text-muted">No log entries.</td></tr>';
    }
    echo '</tbody></table>';
}
