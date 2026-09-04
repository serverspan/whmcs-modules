<?php
/**
 * ServerSpan Identity Verification (Stripe) - hooks
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/stripekyc/hooks.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Functions.php';

function sk_current_uid()
{
    $uid = (int) \WHMCS\Session::get('uid');
    if (!$uid && !empty($_SESSION['uid'])) {
        $uid = (int) $_SESSION['uid'];
    }
    return $uid;
}

/* ----------------------------------- banner for unverified clients */

add_hook('ClientAreaHeaderOutput', 1, function ($vars) {
    if (sk_setting('prompt_clients', '') !== 'on') {
        return;
    }
    if (isset($_GET['m']) && $_GET['m'] === 'stripekyc') {
        return;
    }
    $uid = sk_current_uid();
    if (!$uid || sk_is_verified($uid)) {
        return;
    }
    return '<div class="alert alert-info" style="margin:10px 0">Please complete identity verification. '
        . '<a href="index.php?m=stripekyc">Verify your identity</a> — it takes under a minute.</div>';
});

/* ----------------------------------- block checkout until verified */

add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    if (sk_setting('require_before_order', '') !== 'on') {
        return;
    }
    $uid = sk_current_uid();
    if (!$uid) {
        return;
    }
    if (!sk_is_verified($uid)) {
        return ['Please complete identity verification before placing an order: '
            . 'index.php?m=stripekyc'];
    }
});

/* ----------------------------------- sidebar link */

add_hook('ClientAreaPrimarySidebar', 1, function ($sidebar) {
    if (!$sidebar) {
        return;
    }
    $panel = $sidebar->getChild('Identity Verification');
    if (!$panel) {
        $panel = $sidebar->addChild('Identity Verification', [
            'label' => 'Identity Verification',
            'icon'  => 'fa-id-card',
            'order' => 91,
        ]);
    }
    if ($panel && !$panel->getChild('Verify Identity (Stripe)')) {
        $panel->addChild('Verify Identity (Stripe)', [
            'label' => 'Verify Identity',
            'uri'   => 'index.php?m=stripekyc',
            'icon'  => 'fa-check-circle',
        ]);
    }
});

/* ----------------------- webhook fallback: reconcile stale sessions */

add_hook('DailyCronJob', 1, function () {
    // Sessions stuck in a non-terminal state for over an hour get re-polled
    // (covers missed webhooks). 25 per run, 30-day horizon.
    $rows = Capsule::table('mod_stripekyc_sessions')
        ->whereIn('status', ['requires_input', 'processing'])
        ->where('updated_at', '<=', date('Y-m-d H:i:s', strtotime('-1 hour')))
        ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))
        ->orderBy('id')->limit(25)->get();
    foreach ($rows as $row) {
        sk_sync_session($row->session_id);
    }
    Capsule::table('mod_stripekyc_log')
        ->where('created_at', '<=', date('Y-m-d H:i:s', strtotime('-90 days')))->delete();
});
