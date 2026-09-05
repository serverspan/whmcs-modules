<?php
/**
 * ServerSpan EuPlatesc - IPN callback
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/gateways/callback/euplatesc.php
 */

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../euplatesc/lib/EpApi.php';

$gatewayModuleName = 'euplatesc';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(404);
    die('Module Not Activated');
}

$post = $_POST;
if (!$post || empty($post['invoice_id'])) {
    http_response_code(400);
    die('Invalid callback');
}

$invoiceId = (int) $post['invoice_id'];

// Verify the signature before trusting anything.
if (!ep_verify_response($post)) {
    ep_ipn_log($invoiceId, 'invalid_signature', $post);
    logTransaction($gatewayModuleName, $post, 'Invalid signature');
    http_response_code(400);
    die('Invalid signature');
}

// Validate the invoice and amount.
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
if (!$invoice) {
    http_response_code(404);
    die('Invoice not found');
}

$action = isset($post['action']) ? (string) $post['action'] : '';
$amount = (float) (isset($post['amount']) ? $post['amount'] : 0);
$epId   = isset($post['ep_id']) ? trim((string) $post['ep_id']) : '';

if ($amount <= 0 || abs($amount - (float) $invoice->total) > 0.01) {
    ep_ipn_log($invoiceId, 'amount_mismatch', $post);
    logTransaction($gatewayModuleName, $post, 'Amount mismatch');
    http_response_code(400);
    die('Amount mismatch');
}

if ($action === '0') {
    // Approved — record the payment (idempotent on ep_id).
    $exists = Capsule::table('tblaccounts')->where('transid', $epId)->exists();
    if (!$exists) {
        addInvoicePayment($invoiceId, $epId, $amount, 0, $gatewayModuleName);
        ep_ipn_log($invoiceId, 'payment_recorded', $post);
        logTransaction($gatewayModuleName, $post, 'Approved');
    }
} else {
    ep_ipn_log($invoiceId, 'payment_failed_action_' . $action, $post);
    logTransaction($gatewayModuleName, $post, 'Failed (action ' . $action . ')');
}

http_response_code(200);
echo 'OK';

/**
 * Persist IPN entries when the management addon is installed.
 */
function ep_ipn_log($invoiceId, $event, array $post)
{
    try {
        if (Capsule::schema()->hasTable('mod_ep_log')) {
            Capsule::table('mod_ep_log')->insert([
                'invoice_id' => (int) $invoiceId,
                'event'      => $event,
                'ep_id'      => isset($post['ep_id']) ? (string) $post['ep_id'] : '',
                'payload'    => json_encode($post),
                'ip'         => isset($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    } catch (\Exception $e) {
        // never break the IPN response because of logging
    }
}
