<?php
/**
 * ServerSpan Identity Verification (Didit) - shared library
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/diditkyc/lib/Functions.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

define('DIDIT_API_BASE', 'https://verification.didit.me');

/* ---------------------------------------------------------------- settings */

function didit_settings($fresh = false)
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        foreach (Capsule::table('tbladdonmodules')->where('module', 'diditkyc')->get() as $row) {
            $cache[$row->setting] = $row->value;
        }
    }
    return $cache;
}

function didit_setting($key, $default = '')
{
    $s = didit_settings();
    return (isset($s[$key]) && $s[$key] !== '') ? $s[$key] : $default;
}

/* --------------------------------------------------------------------- API */

/**
 * Call the Didit API. Returns [http_code, decoded_json_array].
 */
function didit_api($method, $path, array $payload = null)
{
    $ch = curl_init(DIDIT_API_BASE . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . didit_setting('api_key'),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ];
    if (strtoupper($method) !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
        $opts[CURLOPT_POSTFIELDS]    = json_encode($payload ?: new \stdClass());
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return [0, ['detail' => $err]];
    }
    $decoded = json_decode((string) $body, true);
    return [$code, is_array($decoded) ? $decoded : ['raw' => $body]];
}

/**
 * Create a hosted verification session for a WHMCS user.
 * Returns [true, response] or [false, error_message].
 */
function didit_create_session($userid, $language = null)
{
    $user = Capsule::table('tblusers')->where('id', (int) $userid)->first();
    if (!$user) {
        return [false, 'User not found.'];
    }
    $systemUrl = rtrim((string) Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value'), '/') . '/';

    $payload = [
        'workflow_id'     => didit_setting('workflow_id'),
        'vendor_data'     => 'whmcs-user-' . (int) $userid,
        'callback'        => $systemUrl . 'index.php?m=diditkyc',
        'callback_method' => 'both',
        'metadata'        => ['source' => 'whmcs', 'module' => 'diditkyc'],
        'contact_details' => ['email' => $user->email],
        'expected_details' => array_filter([
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
        ]),
    ];
    if ($language) {
        $payload['language'] = $language;
    }

    list($code, $resp) = didit_api('POST', '/v3/session/', $payload);
    if ($code !== 201 || empty($resp['session_id']) || empty($resp['url'])) {
        $detail = isset($resp['detail']) ? $resp['detail'] : 'HTTP ' . $code;
        if (is_array($detail)) {
            $detail = json_encode($detail);
        }
        return [false, 'Didit session creation failed: ' . $detail];
    }

    $clientid = didit_user_client($userid);
    $now = date('Y-m-d H:i:s');
    Capsule::table('mod_didit_sessions')->updateOrInsert(
        ['session_id' => $resp['session_id']],
        [
            'userid'      => (int) $userid,
            'clientid'    => $clientid,
            'session_url' => $resp['url'],
            'status'      => isset($resp['status']) ? $resp['status'] : 'Not Started',
            'vendor_data' => $payload['vendor_data'],
            'updated_at'  => $now,
            'created_at'  => $now,
        ]
    );
    didit_log($userid, $resp['session_id'], 'session_created', '');
    return [true, $resp];
}

/**
 * Poll the decision endpoint and sync the local record.
 */
function didit_sync_session($sessionId)
{
    $row = Capsule::table('mod_didit_sessions')->where('session_id', $sessionId)->first();
    if (!$row) {
        return false;
    }
    list($code, $resp) = didit_api('GET', '/v3/session/' . $sessionId . '/decision/');
    if ($code !== 200 || empty($resp['status'])) {
        return false;
    }
    didit_store_outcome($row, $resp['status'], isset($resp['decision']) ? $resp['decision'] : $resp);
    didit_log($row->userid, $sessionId, 'synced', $resp['status']);
    return true;
}

/**
 * Persist a status and run the configured side effects.
 */
function didit_store_outcome($row, $status, $decision = null)
{
    $now = date('Y-m-d H:i:s');
    $terminal = in_array($status, ['Approved', 'Declined', 'In Review', 'Abandoned', 'Expired', 'Kyc Expired'], true);
    $update = [
        'status'     => $status,
        'updated_at' => $now,
    ];
    if ($decision !== null) {
        $update['decision_json'] = json_encode($decision);
    }
    if (in_array($status, ['Approved', 'Declined'], true) && !$row->decided_at) {
        $update['decided_at'] = $now;
    }
    Capsule::table('mod_didit_sessions')->where('id', $row->id)->update($update);

    if (!$terminal) {
        return;
    }
    if ($status === 'Approved') {
        $groupId = (int) didit_setting('verified_group_id', 0);
        if ($groupId > 0 && $row->clientid) {
            Capsule::table('tblclients')->where('id', $row->clientid)->update(['groupid' => $groupId]);
        }
    } elseif ($status === 'Declined') {
        $action = didit_setting('declined_action', 'none');
        if ($row->clientid && in_array($action, ['inactive', 'closed'], true)) {
            Capsule::table('tblclients')->where('id', $row->clientid)
                ->update(['status' => $action === 'inactive' ? 'Inactive' : 'Closed']);
        }
    }
}

/* ----------------------------------------------------------------- webhook */

/**
 * Verify an inbound Didit webhook. Tries X-Signature-V2 (canonical JSON),
 * then X-Signature (raw bytes), then X-Signature-Simple (envelope string).
 */
function didit_verify_webhook($rawBody, $secret)
{
    $ts = isset($_SERVER['HTTP_X_TIMESTAMP']) ? (int) $_SERVER['HTTP_X_TIMESTAMP'] : 0;
    if (!$ts || abs(time() - $ts) > 300) {
        return false;
    }
    $data = json_decode($rawBody, true);

    $sigV2 = isset($_SERVER['HTTP_X_SIGNATURE_V2']) ? $_SERVER['HTTP_X_SIGNATURE_V2'] : '';
    if ($sigV2 && is_array($data)) {
        $canonical = didit_canonical_json($data);
        if (hash_equals(hash_hmac('sha256', $canonical, $secret), strtolower($sigV2))) {
            return true;
        }
    }

    $sigRaw = isset($_SERVER['HTTP_X_SIGNATURE']) ? $_SERVER['HTTP_X_SIGNATURE'] : '';
    if ($sigRaw && hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($sigRaw))) {
        return true;
    }

    $sigSimple = isset($_SERVER['HTTP_X_SIGNATURE_SIMPLE']) ? $_SERVER['HTTP_X_SIGNATURE_SIMPLE'] : '';
    if ($sigSimple && is_array($data)) {
        $envelope = $ts . ':'
            . (isset($data['session_id']) ? $data['session_id'] : '') . ':'
            . (isset($data['status']) ? $data['status'] : '') . ':'
            . (isset($data['webhook_type']) ? $data['webhook_type'] : '');
        if (hash_equals(hash_hmac('sha256', $envelope, $secret), strtolower($sigSimple))) {
            return true;
        }
    }
    return false;
}

function didit_canonical_json($data)
{
    return json_encode(didit_sort_keys($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function didit_sort_keys($data)
{
    if (is_array($data)) {
        // Distinguish assoc arrays (sort keys) from lists (preserve order).
        if ($data === [] || array_keys($data) === range(0, count($data) - 1)) {
            return array_map('didit_sort_keys', $data);
        }
        ksort($data);
        return array_map('didit_sort_keys', $data);
    }
    return $data;
}

/**
 * Handle an inbound webhook POST. Echoes a short response; caller exits.
 */
function didit_handle_webhook()
{
    $raw = file_get_contents('php://input');
    $secret = didit_setting('webhook_secret');
    if (!$secret || !didit_verify_webhook($raw, $secret)) {
        http_response_code(401);
        echo 'unauthorized';
        return;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)
        || (isset($data['webhook_type']) && $data['webhook_type'] !== 'status.updated')
        || empty($data['session_id'])
        || empty($data['status'])) {
        http_response_code(200);
        echo 'ignored';
        return;
    }
    $row = Capsule::table('mod_didit_sessions')->where('session_id', $data['session_id'])->first();
    if (!$row) {
        http_response_code(200); // acknowledge to stop retries for sessions we never stored
        echo 'unknown session';
        return;
    }
    didit_store_outcome($row, $data['status'], isset($data['decision']) ? $data['decision'] : null);
    didit_log($row->userid, $data['session_id'], 'webhook', $data['status']);
    http_response_code(200);
    echo 'ok';
}

/* ------------------------------------------------------------------- misc */

function didit_user_client($userid)
{
    $link = Capsule::table('tblusers_clients')
        ->where('auth_user_id', (int) $userid)->where('owner', 1)->first();
    if ($link) {
        return (int) $link->client_id;
    }
    $link = Capsule::table('tblusers_clients')->where('auth_user_id', (int) $userid)->first();
    return $link ? (int) $link->client_id : 0;
}

/**
 * Latest session for a user, plus convenience flags.
 */
function didit_latest_session($userid)
{
    $row = Capsule::table('mod_didit_sessions')
        ->where('userid', (int) $userid)->orderBy('id', 'desc')->first();
    return $row ?: null;
}

function didit_is_approved($userid)
{
    return Capsule::table('mod_didit_sessions')
        ->where('userid', (int) $userid)->where('status', 'Approved')->exists();
}

/**
 * Map a WHMCS language name to a Didit ISO 639-1 code (null = auto-detect).
 */
function didit_language_code($whmcsLanguage)
{
    $map = [
        'english' => 'en', 'romanian' => 'ro', 'spanish' => 'es', 'french' => 'fr',
        'german' => 'de', 'portuguese' => 'pt', 'italian' => 'it', 'dutch' => 'nl',
        'russian' => 'ru', 'ukrainian' => 'uk', 'polish' => 'pl', 'hungarian' => 'hu',
        'bulgarian' => 'bg', 'czech' => 'cs', 'slovak' => 'sk', 'turkish' => 'tr',
    ];
    $key = strtolower((string) $whmcsLanguage);
    return isset($map[$key]) ? $map[$key] : null;
}

function didit_log($userid, $sessionId, $event, $detail)
{
    Capsule::table('mod_didit_log')->insert([
        'userid'     => (int) $userid,
        'session_id' => (string) $sessionId,
        'event'      => $event,
        'detail'     => is_string($detail) ? $detail : json_encode($detail),
        'ip'         => isset($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}
