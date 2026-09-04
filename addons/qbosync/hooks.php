<?php
/**
 * ServerSpan QuickBooks Sync - hooks
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/qbosync/hooks.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Functions.php';

/* ------------------------------------------- auto-queue on WHMCS events */

add_hook('ClientAdd', 1, function ($vars) {
    if (qbo_setting('auto_clients', '') === 'on') {
        qbo_enqueue('customer', $vars['client_id'], 'sync');
    }
});

add_hook('ClientEdit', 1, function ($vars) {
    // Sparse-update the existing QBO customer; never recreate it.
    if (qbo_setting('auto_clients', '') === 'on' && !empty($vars['userid'])) {
        qbo_enqueue('customer_update', $vars['userid'], 'sync');
    }
});

add_hook('InvoicePaid', 1, function ($vars) {
    if (qbo_setting('auto_invoices_paid', '') !== 'on') {
        return;
    }
    $invoiceId = (int) $vars['invoiceid'];
    qbo_enqueue('invoice', $invoiceId, 'sync');
    foreach (Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->where('amountin', '>', 0)->pluck('id') as $txnId) {
        qbo_enqueue('payment', $txnId, 'sync');
    }
});

add_hook('InvoiceRefunded', 1, function ($vars) {
    if (qbo_setting('auto_refunds', '') === 'on') {
        qbo_enqueue('refund', $vars['invoiceid'], 'sync');
    }
});

/* ------------------------------ cron: refresh tokens, drain the queue */

add_hook('DailyCronJob', 1, function () {
    // Touch the token daily so the 100-day rolling refresh never lapses.
    qbo_access_token();
    $limit = (int) qbo_setting('batch_limit', 25);
    if ($limit > 0) {
        qbo_process_queue($limit);
    }
    Capsule::table('mod_qbo_log')
        ->where('created_at', '<=', date('Y-m-d H:i:s', strtotime('-90 days')))->delete();
});
