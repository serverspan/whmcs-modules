<?php
/**
 * ServerSpan ownCloud Storage - OCS API client (server module)
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/servers/ocstorage/lib/OcApi.php
 *
 * Standalone (oca_ prefix) so it never collides with the ocmanager addon,
 * whose files load on every WHMCS page.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Call the ownCloud/Nextcloud OCS Provisioning API.
 * Credentials come from the WHMCS server record: hostname = ownCloud base URL,
 * username/password = ownCloud admin account.
 * Returns [http_code, ocs_statuscode, data, meta_message].
 */
function oca_api($params, $method, $path, array $fields = null)
{
    $base = rtrim(trim((string) $params['serverhostname']), '/');
    if ($base === '') {
        return [0, 0, [], 'Server hostname (ownCloud URL) is not configured'];
    }
    $url = $base . '/ocs/v1.php/cloud' . $path . (strpos($path, '?') === false ? '?' : '&') . 'format=json';
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERPWD        => $params['serverusername'] . ':' . $params['serverpassword'],
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER     => [
            'OCS-APIREQUEST: true',
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($fields !== null) {
        $opts[CURLOPT_POSTFIELDS] = is_array($fields) ? http_build_query($fields) : $fields;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return [0, 0, [], $err];
    }
    $decoded = json_decode((string) $body, true);
    $ocs  = isset($decoded['ocs']) ? $decoded['ocs'] : [];
    $meta = isset($ocs['meta']) ? $ocs['meta'] : [];
    $data = isset($ocs['data']) ? $ocs['data'] : [];
    return [
        $http,
        (int) (isset($meta['statuscode']) ? $meta['statuscode'] : 0),
        is_array($data) ? $data : [],
        (string) (isset($meta['message']) ? $meta['message'] : ''),
    ];
}

function oca_ok($statuscode)
{
    return $statuscode === 100;
}

function oca_fail($http, $statuscode, $message)
{
    return 'ownCloud error ' . ($statuscode ?: $http) . ($message ? ': ' . $message : '');
}

/* --------------------------------------------------------------- user ops */

function oca_create_user($params, $userid, $password, $email, $displayName, $quota, array $groups)
{
    $fields = ['userid' => $userid, 'password' => $password];
    if ($email !== '') {
        $fields['email'] = $email;
    }
    if ($displayName !== '') {
        $fields['displayName'] = $displayName;
    }
    if ($quota !== '') {
        $fields['quota'] = $quota;
    }
    foreach (array_values($groups) as $i => $g) {
        $fields['groups[' . $i . ']'] = $g;
    }
    return oca_api($params, 'POST', '/users', $fields);
}

function oca_get_user($params, $userid)
{
    return oca_api($params, 'GET', '/users/' . rawurlencode($userid));
}

function oca_list_users($params, $search = '', $limit = 50, $offset = 0)
{
    return oca_api($params, 'GET', '/users?search=' . urlencode($search)
        . '&limit=' . (int) $limit . '&offset=' . (int) $offset);
}

/** Exactly one attribute per call, per the OCS contract. */
function oca_edit_user($params, $userid, $key, $value)
{
    return oca_api($params, 'PUT', '/users/' . rawurlencode($userid), ['key' => $key, 'value' => $value]);
}

function oca_set_enabled($params, $userid, $enabled)
{
    return oca_api($params, 'PUT', '/users/' . rawurlencode($userid) . ($enabled ? '/enable' : '/disable'));
}

function oca_delete_user($params, $userid)
{
    return oca_api($params, 'DELETE', '/users/' . rawurlencode($userid));
}

/* -------------------------------------------------------------- group ops */

function oca_list_groups($params, $search = '')
{
    return oca_api($params, 'GET', '/groups?search=' . urlencode($search));
}

function oca_create_group($params, $groupid)
{
    return oca_api($params, 'POST', '/groups', ['groupid' => $groupid]);
}

function oca_delete_group($params, $groupid)
{
    return oca_api($params, 'DELETE', '/groups/' . rawurlencode($groupid));
}

function oca_group_members($params, $groupid)
{
    return oca_api($params, 'GET', '/groups/' . rawurlencode($groupid));
}

function oca_user_groups($params, $userid)
{
    return oca_api($params, 'GET', '/users/' . rawurlencode($userid) . '/groups');
}

function oca_group_add_user($params, $userid, $groupid)
{
    return oca_api($params, 'POST', '/users/' . rawurlencode($userid) . '/groups', ['groupid' => $groupid]);
}

function oca_group_remove_user($params, $userid, $groupid)
{
    // DELETE with a body field.
    return oca_api($params, 'DELETE', '/users/' . rawurlencode($userid) . '/groups?groupid=' . urlencode($groupid));
}

/* ------------------------------------------------------------ subadmins */

function oca_subadmin_add($params, $userid, $groupid)
{
    return oca_api($params, 'POST', '/users/' . rawurlencode($userid) . '/subadmins', ['groupid' => $groupid]);
}

function oca_subadmin_remove($params, $userid, $groupid)
{
    return oca_api($params, 'DELETE', '/users/' . rawurlencode($userid) . '/subadmins?groupid=' . urlencode($groupid));
}

function oca_subadmin_groups($params, $userid)
{
    return oca_api($params, 'GET', '/users/' . rawurlencode($userid) . '/subadmins');
}

/* ------------------------------------------------------------------ misc */

function oca_gen_username($params)
{
    $base = '';
    if (!empty($params['domain'])) {
        $base = strtolower(trim($params['domain']));
    } elseif (!empty($params['clientsdetails']['email'])) {
        $base = strtolower(trim($params['clientsdetails']['email']));
    } else {
        $base = 'user' . (int) $params['serviceid'];
    }
    $base = preg_replace('/[^a-z0-9._@-]/', '', str_replace(' ', '', $base));
    return $base ?: 'user' . (int) $params['serviceid'];
}

function oca_gen_password($length = 16)
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$%';
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

/** Normalize quota to an ownCloud-accepted string ("2 GB", "none", etc.). */
function oca_quota($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '' || $raw === '0') {
        return 'default';
    }
    if (strtolower($raw) === 'unlimited') {
        return 'none';
    }
    if (is_numeric($raw)) {
        return $raw . ' GB';
    }
    return $raw;
}
