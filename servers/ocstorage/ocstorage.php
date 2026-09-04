<?php
/**
 * ServerSpan ownCloud Storage - provisioning module
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/servers/ocstorage/ocstorage.php
 *
 * Sells ownCloud storage as a product: each service gets an ownCloud user with
 * a quota and optional group. Reseller mode makes the user a group sub-admin
 * with a local group limit. Pairs with the ServerSpan ownCloud Manager addon.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/lib/OcApi.php';

function ocstorage_MetaData()
{
    return [
        'DisplayName'    => 'ServerSpan ownCloud Storage',
        'APIVersion'     => '1.1',
        'RequiresServer' => true,
        'ListAccountsUniqueIdentifierDisplayName' => 'Username',
        'ListAccountsUniqueIdentifierField'       => 'username',
    ];
}

function ocstorage_ConfigOptions()
{
    return [
        'Quota' => [
            'Type' => 'text', 'Size' => '10', 'Default' => '1GB',
            'Description' => 'e.g. 500MB, 2GB, 10 GB, or "unlimited".',
        ],
        'Group' => [
            'Type' => 'text', 'Size' => '25', 'Default' => '',
            'Description' => 'ownCloud group for created users (optional; created if missing).',
        ],
        'Reseller Mode' => [
            'Type' => 'yesno',
            'Description' => 'User becomes sub-admin of their own group and can create sub-accounts.',
        ],
        'Reseller Group Limit (GB)' => [
            'Type' => 'text', 'Size' => '10', 'Default' => '',
            'Description' => 'Recorded as the group limit (ownCloud Manager addon).',
        ],
    ];
}

function ocstorage_TestConnection(array $params)
{
    list($http, $status, $data, $message) = oca_get_user($params, $params['serverusername']);
    if ($http === 200 && oca_ok($status)) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => oca_fail($http, $status, $message)];
}

function ocstorage_CreateAccount(array $params)
{
    $username = trim((string) $params['username']) ?: oca_gen_username($params);
    $password = trim((string) $params['password']) ?: oca_gen_password();
    $email    = isset($params['clientsdetails']['email']) ? $params['clientsdetails']['email'] : '';
    $display  = trim((isset($params['clientsdetails']['firstname']) ? $params['clientsdetails']['firstname'] : '')
        . ' ' . (isset($params['clientsdetails']['lastname']) ? $params['clientsdetails']['lastname'] : ''));
    $quota    = oca_quota($params['configoption1']);
    $group    = trim((string) $params['configoption2']);
    $reseller = isset($params['configoption3']) && $params['configoption3'] === 'on';

    if ($group !== '') {
        oca_create_group($params, $group); // ignore "exists" — idempotent
    }
    $groups = $group !== '' ? [$group] : [];

    list($http, $status, , $message) = oca_create_user($params, $username, $password, $email, $display, $quota, $groups);
    if (!oca_ok($status)) {
        return 'User creation failed — ' . oca_fail($http, $status, $message);
    }

    // Persist the generated credentials back onto the service.
    if ($username !== $params['username'] || $password !== $params['password']) {
        \WHMCS\Database\Capsule::table('tblhosting')->where('id', (int) $params['serviceid'])
            ->update(['username' => $username, 'password' => encrypt($password)]);
    }

    if ($reseller) {
        // Reseller: own group, sub-admin rights, recorded group limit.
        $rGroup = 'reseller-' . $username;
        oca_create_group($params, $rGroup);
        oca_group_add_user($params, $username, $rGroup);
        oca_subadmin_add($params, $username, $rGroup);
        $limitGb = (int) (isset($params['configoption4']) ? $params['configoption4'] : 0);
        if ($limitGb > 0) {
            try {
                if (\WHMCS\Database\Capsule::schema()->hasTable('mod_oc_grouplimits')) {
                    \WHMCS\Database\Capsule::table('mod_oc_grouplimits')->updateOrInsert(
                        ['server_id' => (int) $params['serverid'], 'groupname' => $rGroup],
                        ['limit_gb' => $limitGb, 'created_at' => date('Y-m-d H:i:s')]
                    );
                }
            } catch (\Exception $e) {
            }
        }
        logModuleCall('ocstorage', 'reseller_setup', $username, 'group ' . $rGroup);
    }
    return 'success';
}

function ocstorage_SuspendAccount(array $params)
{
    list($http, $status, , $message) = oca_set_enabled($params, $params['username'], false);
    return oca_ok($status) ? 'success' : oca_fail($http, $status, $message);
}

function ocstorage_UnsuspendAccount(array $params)
{
    list($http, $status, , $message) = oca_set_enabled($params, $params['username'], true);
    return oca_ok($status) ? 'success' : oca_fail($http, $status, $message);
}

function ocstorage_TerminateAccount(array $params)
{
    list($http, $status, , $message) = oca_delete_user($params, $params['username']);
    // 101 (not found) counts as terminated.
    return (oca_ok($status) || $status === 101) ? 'success' : oca_fail($http, $status, $message);
}

function ocstorage_ChangePassword(array $params)
{
    $new = trim((string) $params['password']);
    if ($new === '') {
        return 'Password cannot be empty.';
    }
    list($http, $status, , $message) = oca_edit_user($params, $params['username'], 'password', $new);
    return oca_ok($status) ? 'success' : oca_fail($http, $status, $message);
}

function ocstorage_ChangePackage(array $params)
{
    // One attribute per PUT per the OCS contract.
    list($http, $status, , $message) = oca_edit_user($params, $params['username'], 'quota', oca_quota($params['configoption1']));
    if (!oca_ok($status)) {
        return 'Quota change failed — ' . oca_fail($http, $status, $message);
    }
    $newGroup = trim((string) $params['configoption2']);
    if ($newGroup !== '') {
        list(, , $data) = oca_user_groups($params, $params['username']);
        $current = isset($data['groups']) ? (array) $data['groups'] : [];
        foreach ($current as $g) {
            if ($g !== $newGroup) {
                oca_group_remove_user($params, $params['username'], $g);
            }
        }
        if (!in_array($newGroup, $current, true)) {
            oca_create_group($params, $newGroup);
            oca_group_add_user($params, $params['username'], $newGroup);
        }
    }
    return 'success';
}

function ocstorage_ClientArea(array $params)
{
    $used = '';
    $total = '';
    $enabled = true;
    list($http, $status, $data) = oca_get_user($params, $params['username']);
    if ($http === 200 && oca_ok($status)) {
        $used    = isset($data['quota']['used']) ? $data['quota']['used'] : '';
        $total   = isset($data['quota']['total']) ? $data['quota']['total'] : '';
        $enabled = !isset($data['enabled']) || $data['enabled'] === true || $data['enabled'] === 'true';
    }
    $base = rtrim(trim((string) $params['serverhostname']), '/');
    return [
        'templatefile' => 'overview',
        'vars' => [
            'username' => $params['username'],
            'used'     => $used,
            'total'    => $total,
            'enabled'  => $enabled,
            'loginUrl' => $base,
        ],
    ];
}
