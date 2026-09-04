<?php
/**
 * ServerSpan ownCloud Manager - shared library
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/ocmanager/lib/Functions.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function ocm_settings($fresh = false)
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        foreach (Capsule::table('tbladdonmodules')->where('module', 'ocmanager')->get() as $row) {
            $cache[$row->setting] = $row->value;
        }
    }
    return $cache;
}

function ocm_setting($key, $default = '')
{
    $s = ocm_settings();
    return (isset($s[$key]) && $s[$key] !== '') ? $s[$key] : $default;
}

/**
 * ownCloud servers (module type ocstorage), keyed by id.
 */
function ocm_servers()
{
    static $servers = null;
    if ($servers === null) {
        $servers = [];
        foreach (Capsule::table('tblservers')->where('type', 'ocstorage')->get() as $row) {
            $servers[(int) $row->id] = $row;
        }
    }
    return $servers;
}

/**
 * Server row by id, falling back to the configured default / first one.
 */
function ocm_server($id = 0)
{
    $servers = ocm_servers();
    if ($id && isset($servers[$id])) {
        return $servers[$id];
    }
    $default = (int) ocm_setting('default_server_id', 0);
    if ($default && isset($servers[$default])) {
        return $servers[$default];
    }
    return $servers ? reset($servers) : null;
}

/**
 * Call the OCS Provisioning API on a given server row.
 * Returns [http_code, ocs_statuscode, data, meta_message].
 */
function ocm_api($server, $method, $path, array $fields = null)
{
    $base = rtrim(trim((string) $server->hostname), '/');
    if ($server->secure && strpos($base, 'http') !== 0) {
        $base = 'https://' . $base;
    } elseif (strpos($base, 'http') !== 0) {
        $base = 'http://' . $base;
    }
    $url = $base . '/ocs/v1.php/cloud' . $path . (strpos($path, '?') === false ? '?' : '&') . 'format=json';
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERPWD        => $server->username . ':' . $server->password,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER     => [
            'OCS-APIREQUEST: true',
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($fields !== null) {
        $opts[CURLOPT_POSTFIELDS] = http_build_query($fields);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
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

function ocm_ok($status)
{
    return $status === 100;
}

function ocm_fail($http, $status, $message)
{
    return 'ownCloud error ' . ($status ?: $http) . ($message ? ': ' . $message : '');
}

function ocm_log($action, $serverId, $subject, $detail)
{
    Capsule::table('mod_oc_log')->insert([
        'action'     => $action,
        'server_id'  => (int) $serverId,
        'subject'    => (string) $subject,
        'detail'     => is_string($detail) ? $detail : json_encode($detail),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Normalize quota input to an ownCloud-accepted string.
 */
function ocm_quota($raw)
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
