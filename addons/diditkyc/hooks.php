<?php
/**
 * ServerSpan Identity Verification (Didit) - hooks
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/diditkyc/hooks.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Functions.php';

function didit_current_uid()
{
    $uid = (int) \WHMCS\Session::get('uid');
    if (!$uid && !empty($_SESSION['uid'])) {
        $uid = (int) $_SESSION['uid'];
    }
    return $uid;
}

/* ----------------------------------- banner for unverified clients */

add_hook('ClientAreaHeaderOutput', 1, function ($vars) {
    if (didit_setting('prompt_clients', '') !== 'on') {
        return;
    }
    if (isset($_GET['m']) && $_GET['m'] === 'diditkyc') {
        return;
    }
    $uid = didit_current_uid();
    if (!$uid || didit_is_approved($uid)) {
        return;
    }
    return '<div class="alert alert-info" style="margin:10px 0">Please complete identity verification. '
        . '<a href="index.php?m=diditkyc">Verify your identity</a> — it takes under a minute.</div>';
});

/* ----------------------------------- block checkout until verified */

add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    if (didit_setting('require_before_order', '') !== 'on') {
        return;
    }
    $uid = didit_current_uid();
    if (!$uid) {
        return;
    }
    if (!didit_is_approved($uid)) {
        return ['Please complete identity verification before placing an order: '
            . 'index.php?m=diditkyc'];
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
    if ($panel && !$panel->getChild('Verify Identity')) {
        $panel->addChild('Verify Identity', [
            'label' => 'Verify Identity',
            'uri'   => 'index.php?m=diditkyc',
            'icon'  => 'fa-check-circle',
        ]);
    }
});

/* ----------------------- webhook fallback: reconcile stale sessions */

add_hook('DailyCronJob', 1, function () {
    // Sessions stuck in a non-terminal state for over an hour get re-polled
    // (covers missed webhooks). 25 per run, 30-day horizon.
    $rows = Capsule::table('mod_didit_sessions')
        ->whereIn('status', ['Not Started', 'In Progress', 'In Review', 'Resubmitted', 'Awaiting User'])
        ->where('updated_at', '<=', date('Y-m-d H:i:s', strtotime('-1 hour')))
        ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))
        ->orderBy('id')->limit(25)->get();
    foreach ($rows as $row) {
        didit_sync_session($row->session_id);
    }
    Capsule::table('mod_didit_log')
        ->where('created_at', '<=', date('Y-m-d H:i:s', strtotime('-90 days')))->delete();
});
