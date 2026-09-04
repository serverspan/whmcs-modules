<?php
/**
 * ServerSpan Support PIN - shared library
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/supportpin/lib/Functions.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/* ---------------------------------------------------------------- settings */

function pin_settings($fresh = false)
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        foreach (Capsule::table('tbladdonmodules')->where('module', 'supportpin')->get() as $row) {
            $cache[$row->setting] = $row->value;
        }
    }
    return $cache;
}

function pin_setting($key, $default = '')
{
    $s = pin_settings();
    return (isset($s[$key]) && $s[$key] !== '') ? $s[$key] : $default;
}

function pin_salt()
{
    $salt = pin_setting('pin_salt');
    if (!$salt) {
        $salt = bin2hex(random_bytes(16));
        Capsule::table('tbladdonmodules')->updateOrInsert(
            ['module' => 'supportpin', 'setting' => 'pin_salt'],
            ['value' => $salt]
        );
        pin_settings(true);
    }
    return $salt;
}

/* -------------------------------------------------------------------- pin */

function pin_hash($pin)
{
    return hash('sha256', $pin . '|' . pin_salt());
}

function pin_generate()
{
    $length = (int) pin_setting('pin_length', 6);
    if ($length < 4) {
        $length = 4;
    }
    if ($length > 8) {
        $length = 8;
    }
    $pin = '';
    for ($i = 0; $i < $length; $i++) {
        $pin .= (string) random_int(0, 9);
    }
    return $pin;
}

/**
 * Issue a new PIN for a user, replacing any existing one.
 * Returns the plaintext PIN (also stored encrypted for later display).
 */
function pin_issue($userid)
{
    $now = date('Y-m-d H:i:s');
    $expiryHours = (int) pin_setting('expiry_hours', 0);
    $expiresAt = $expiryHours > 0 ? date('Y-m-d H:i:s', strtotime("+{$expiryHours} hours")) : null;

    // Guarantee the PIN is unique among currently valid PINs.
    do {
        $pin  = pin_generate();
        $hash = pin_hash($pin);
        $clash = Capsule::table('mod_pin_pins')
            ->where('pin_hash', $hash)
            ->whereNull('used_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->exists();
    } while ($clash);

    Capsule::table('mod_pin_pins')->where('userid', $userid)->delete();
    Capsule::table('mod_pin_pins')->insert([
        'userid'        => (int) $userid,
        'pin_hash'      => $hash,
        'pin_encrypted' => encrypt($pin),
        'created_at'    => $now,
        'expires_at'    => $expiresAt,
    ]);
    pin_log('generated', $userid, 0, substr($pin, -2));
    return $pin;
}

/**
 * Current PIN row for a user with the decrypted PIN attached, or null.
 */
function pin_current($userid)
{
    $row = Capsule::table('mod_pin_pins')->where('userid', (int) $userid)->first();
    if (!$row) {
        return null;
    }
    $row->pin = decrypt($row->pin_encrypted);
    $row->is_expired = $row->expires_at && $row->expires_at <= date('Y-m-d H:i:s');
    $row->is_used = !empty($row->used_at);
    return $row;
}

/**
 * Verify a PIN presented by staff. Returns [status, row|null] where status is
 * one of: ok, invalid, used, expired, ratelimited.
 */
function pin_verify($pin, $adminid)
{
    $pin = preg_replace('/[^0-9]/', '', (string) $pin);
    if ($pin === '') {
        return ['invalid', null];
    }

    $limit = (int) pin_setting('verify_rate_limit', 10);
    if ($limit > 0) {
        $fails = Capsule::table('mod_pin_log')
            ->where('adminid', $adminid)
            ->where('action', 'verify_fail')
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-10 minutes')))
            ->count();
        if ($fails >= $limit) {
            return ['ratelimited', null];
        }
    }

    $now  = date('Y-m-d H:i:s');
    $hash = pin_hash($pin);
    $row  = Capsule::table('mod_pin_pins')->where('pin_hash', $hash)->first();

    if (!$row) {
        pin_log('verify_fail', 0, $adminid, substr($pin, -2));
        return ['invalid', null];
    }
    if ($row->used_at) {
        pin_log('verify_fail', $row->userid, $adminid, substr($pin, -2));
        return ['used', $row];
    }
    if ($row->expires_at && $row->expires_at <= $now) {
        pin_log('verify_fail', $row->userid, $adminid, substr($pin, -2));
        return ['expired', $row];
    }

    if (pin_setting('one_time', '') === 'on') {
        Capsule::table('mod_pin_pins')->where('id', $row->id)->update(['used_at' => $now]);
    }
    pin_log('verify_success', $row->userid, $adminid, substr($pin, -2));
    return ['ok', $row];
}

/* ----------------------------------------------------------------- grants */

function pin_grant($adminid, $userid)
{
    $clientid = pin_user_client($userid);
    if (!$clientid) {
        return 0;
    }
    $minutes = max(5, (int) pin_setting('grant_minutes', 30));
    Capsule::table('mod_pin_grants')->insert([
        'adminid'    => (int) $adminid,
        'userid'     => (int) $userid,
        'clientid'   => (int) $clientid,
        'expires_at' => date('Y-m-d H:i:s', strtotime("+{$minutes} minutes")),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    pin_log('grant', $userid, $adminid, '');
    return (int) $clientid;
}

function pin_has_grant($adminid, $clientid)
{
    return Capsule::table('mod_pin_grants')
        ->where('adminid', (int) $adminid)
        ->where('clientid', (int) $clientid)
        ->where('expires_at', '>', date('Y-m-d H:i:s'))
        ->exists();
}

/* ------------------------------------------------------------------ misc */

function pin_user_client($userid)
{
    $link = Capsule::table('tblusers_clients')
        ->where('auth_user_id', (int) $userid)->where('owner', 1)->first();
    if ($link) {
        return (int) $link->client_id;
    }
    // Contacts/sub-accounts: take the first account the user belongs to.
    $link = Capsule::table('tblusers_clients')->where('auth_user_id', (int) $userid)->first();
    return $link ? (int) $link->client_id : 0;
}

function pin_user_email($userid)
{
    $u = Capsule::table('tblusers')->where('id', (int) $userid)->first();
    return $u ? $u->email : '';
}

function pin_log($action, $userid, $adminid, $pinTail)
{
    Capsule::table('mod_pin_log')->insert([
        'userid'     => (int) $userid,
        'adminid'    => (int) $adminid,
        'action'     => $action,
        'pin_tail'   => $pinTail,
        'ip'         => isset($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}
