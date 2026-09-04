<?php
/**
 * ServerSpan Identity Verification (Stripe) - shared library
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/stripekyc/lib/Functions.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

define('SK_API_BASE', 'https://api.stripe.com');

/* ---------------------------------------------------------------- settings */

function sk_settings($fresh = false)
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        foreach (Capsule::table('tbladdonmodules')->where('module', 'stripekyc')->get() as $row) {
            $cache[$row->setting] = $row->value;
        }
    }
    return $cache;
}

function sk_setting($key, $default = '')
{
    $s = sk_settings();
    return (isset($s[$key]) && $s[$key] !== '') ? $s[$key] : $default;
}

/* --------------------------------------------------------------------- API */

/**
 * Call the Stripe API. Returns [http_code, decoded_json_array].
 */
function sk_api($method, $path, array $payload = null, $idempotencyKey = null)
{
    $headers = [
        'Authorization: Bearer ' . sk_setting('secret_key'),
    ];
    $ch = curl_init(SK_API_BASE . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $payload ? http_build_query($payload) : '';
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        if ($idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return [0, ['error' => ['message' => $err]]];
    }
    $decoded = json_decode((string) $body, true);
    return [$code, is_array($decoded) ? $decoded : ['raw' => $body]];
}

/**
 * Get (or create) the hosted verification URL for a user. Reuses an open
 * session when one exists (Stripe best practice); only creates a new
 * VerificationSession when the previous one is canceled or there is none.
 * Returns [true, url] or [false, error_message].
 */
function sk_start_session($userid)
{
    $user = Capsule::table('tblusers')->where('id', (int) $userid)->first();
    if (!$user) {
        return [false, 'User not found.'];
    }

    $open = Capsule::table('mod_stripekyc_sessions')
        ->where('userid', (int) $userid)
        ->whereIn('status', ['requires_input', 'processing'])
        ->orderBy('id', 'desc')->first();
    if ($open) {
        list($code, $resp) = sk_api('GET', '/v1/identity/verification_sessions/' . $open->session_id);
        if ($code === 200 && !empty($resp['url'])) {
            Capsule::table('mod_stripekyc_sessions')->where('id', $open->id)->update([
                'session_url' => $resp['url'],
                'status'      => $resp['status'],
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
            return [true, $resp['url']];
        }
        if ($code === 200) {
            // Session finished in the meantime; fall through to local status sync.
            sk_store_session($open, $resp);
            return [false, 'session_finished'];
        }
        return [false, 'Stripe error: ' . sk_error_message($resp, $code)];
    }

    $systemUrl = rtrim((string) Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value'), '/') . '/';
    $payload = [
        'type' => 'document',
        'client_reference_id' => 'whmcs-user-' . (int) $userid,
        'metadata' => ['user_id' => (int) $userid, 'source' => 'whmcs-stripekyc'],
        'provided_details' => ['email' => $user->email],
        'return_url' => $systemUrl . 'index.php?m=stripekyc',
    ];
    $docOptions = [];
    if (sk_setting('require_matching_selfie', '') === 'on') {
        $docOptions['require_matching_selfie'] = 'true';
    }
    if (sk_setting('require_live_capture', '') === 'on') {
        $docOptions['require_live_capture'] = 'true';
    }
    if ($docOptions) {
        $payload['options'] = ['document' => $docOptions];
    }

    list($code, $resp) = sk_api('POST', '/v1/identity/verification_sessions', $payload,
        'whmcs-' . (int) $userid . '-' . bin2hex(random_bytes(8)));
    if ($code !== 200 || empty($resp['id']) || empty($resp['url'])) {
        return [false, 'Stripe error: ' . sk_error_message($resp, $code)];
    }

    $now = date('Y-m-d H:i:s');
    Capsule::table('mod_stripekyc_sessions')->insert([
        'userid'      => (int) $userid,
        'clientid'    => sk_user_client($userid),
        'session_id'  => $resp['id'],
        'session_url' => $resp['url'],
        'status'      => $resp['status'],
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);
    sk_log($userid, $resp['id'], 'session_created', '');
    return [true, $resp['url']];
}

/**
 * Retrieve a session from Stripe and sync the local record.
 */
function sk_sync_session($sessionId)
{
    $row = Capsule::table('mod_stripekyc_sessions')->where('session_id', $sessionId)->first();
    if (!$row) {
        return false;
    }
    list($code, $resp) = sk_api('GET', '/v1/identity/verification_sessions/' . $sessionId);
    if ($code !== 200 || empty($resp['status'])) {
        return false;
    }
    sk_store_session($row, $resp);
    sk_log($row->userid, $sessionId, 'synced', $resp['status']);
    return true;
}

/**
 * Persist a Stripe session state locally and run side effects.
 */
function sk_store_session($row, $session)
{
    $now = date('Y-m-d H:i:s');
    $update = [
        'status'     => $session['status'],
        'updated_at' => $now,
        'last_error' => !empty($session['last_error']['reason']) ? (string) $session['last_error']['reason'] : '',
    ];
    if (isset($session['redaction']['status']) && $session['redaction']['status'] === 'redacted') {
        $update['redacted'] = 1;
    }
    if ($session['status'] === 'verified' && !$row->verified_at) {
        $update['verified_at'] = $now;
    }
    Capsule::table('mod_stripekyc_sessions')->where('id', $row->id)->update($update);

    if ($session['status'] === 'verified') {
        $groupId = (int) sk_setting('verified_group_id', 0);
        if ($groupId > 0 && $row->clientid) {
            Capsule::table('tblclients')->where('id', $row->clientid)->update(['groupid' => $groupId]);
        }
    }
}

/**
 * Redact a session (GDPR deletion of PII at Stripe). Returns [ok, error].
 */
function sk_redact_session($sessionId)
{
    $row = Capsule::table('mod_stripekyc_sessions')->where('session_id', $sessionId)->first();
    if (!$row) {
        return [false, 'Session not found.'];
    }
    list($code, $resp) = sk_api('POST', '/v1/identity/verification_sessions/' . $sessionId . '/redact');
    if ($code !== 200) {
        return [false, sk_error_message($resp, $code)];
    }
    Capsule::table('mod_stripekyc_sessions')->where('id', $row->id)
        ->update(['redacted' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
    sk_log($row->userid, $sessionId, 'redacted', '');
    return [true, ''];
}

function sk_error_message($resp, $code)
{
    if (isset($resp['error']['message'])) {
        return $resp['error']['message'];
    }
    return 'HTTP ' . $code;
}

/* ----------------------------------------------------------------- webhook */

/**
 * Verify the Stripe-Signature header against the raw body.
 */
function sk_verify_webhook($rawBody, $secret)
{
    $header = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
    if (!$header) {
        return false;
    }
    $timestamp = 0;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) === 2) {
            if ($kv[0] === 't') {
                $timestamp = (int) $kv[1];
            } elseif ($kv[0] === 'v1') {
                $signatures[] = $kv[1];
            }
        }
    }
    if (!$timestamp || !$signatures || abs(time() - $timestamp) > 300) {
        return false;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }
    return false;
}

/**
 * Handle an inbound Stripe webhook POST. Echoes a short response; caller exits.
 */
function sk_handle_webhook()
{
    $raw = file_get_contents('php://input');
    $secret = sk_setting('webhook_secret');
    if (!$secret || !sk_verify_webhook($raw, $secret)) {
        http_response_code(401);
        echo 'unauthorized';
        return;
    }
    $event = json_decode($raw, true);
    $type = isset($event['type']) ? $event['type'] : '';
    $prefix = 'identity.verification_session.';
    if (!is_array($event) || strpos($type, $prefix) !== 0 || empty($event['data']['object']['id'])) {
        http_response_code(200);
        echo 'ignored';
        return;
    }
    $session = $event['data']['object'];
    $row = Capsule::table('mod_stripekyc_sessions')->where('session_id', $session['id'])->first();
    if (!$row && !empty($session['metadata']['user_id'])) {
        // Session we never stored locally (e.g. created in the Dashboard): record it.
        $userid = (int) $session['metadata']['user_id'];
        $now = date('Y-m-d H:i:s');
        Capsule::table('mod_stripekyc_sessions')->insert([
            'userid'      => $userid,
            'clientid'    => sk_user_client($userid),
            'session_id'  => $session['id'],
            'session_url' => isset($session['url']) ? (string) $session['url'] : '',
            'status'      => $session['status'],
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        $row = Capsule::table('mod_stripekyc_sessions')->where('session_id', $session['id'])->first();
    }
    if (!$row) {
        http_response_code(200);
        echo 'unknown session';
        return;
    }
    sk_store_session($row, $session);
    sk_log($row->userid, $session['id'], 'webhook', $type);
    http_response_code(200);
    echo 'ok';
}

/* ------------------------------------------------------------------- misc */

function sk_user_client($userid)
{
    $link = Capsule::table('tblusers_clients')
        ->where('auth_user_id', (int) $userid)->where('owner', 1)->first();
    if ($link) {
        return (int) $link->client_id;
    }
    $link = Capsule::table('tblusers_clients')->where('auth_user_id', (int) $userid)->first();
    return $link ? (int) $link->client_id : 0;
}

function sk_latest_session($userid)
{
    $row = Capsule::table('mod_stripekyc_sessions')
        ->where('userid', (int) $userid)->orderBy('id', 'desc')->first();
    return $row ?: null;
}

function sk_is_verified($userid)
{
    return Capsule::table('mod_stripekyc_sessions')
        ->where('userid', (int) $userid)->where('status', 'verified')->exists();
}

function sk_log($userid, $sessionId, $event, $detail)
{
    Capsule::table('mod_stripekyc_log')->insert([
        'userid'     => (int) $userid,
        'session_id' => (string) $sessionId,
        'event'      => $event,
        'detail'     => is_string($detail) ? $detail : json_encode($detail),
        'ip'         => isset($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}
