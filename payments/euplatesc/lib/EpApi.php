<?php
/**
 * ServerSpan EuPlatesc - shared API library
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/gateways/euplatesc/lib/EpApi.php
 *
 * Signature scheme (EuPlătesc documented): for each signed field, append
 * strlen(value) . value to the buffer ('-' when empty), then
 * fp_hash = strtoupper(hash_hmac('sha1', buffer, pack('H*', key))).
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

define('EP_GATEWAY_URL', 'https://secure.euplatesc.ro/tdsprocess/tranzactd.php');
define('EP_WS_URL', 'https://manager.euplatesc.ro/v3/index.php?action=ws');

/* ------------------------------------------------------------------ config */

function ep_config($fresh = false)
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        foreach (Capsule::table('tblpaymentgateways')->where('gateway', 'euplatesc')->get() as $row) {
            $cache[$row->setting] = $row->value;
        }
    }
    return $cache;
}

function ep_setting($key, $default = '')
{
    $c = ep_config();
    return (isset($c[$key]) && $c[$key] !== '') ? $c[$key] : $default;
}

function ep_test_mode()
{
    return ep_setting('testMode', '') === 'on';
}

function ep_merchant_id()
{
    return ep_test_mode() && ep_setting('testMerchantId')
        ? ep_setting('testMerchantId') : ep_setting('merchantId');
}

function ep_secret_key()
{
    return ep_test_mode() && ep_setting('testSecretKey')
        ? ep_setting('testSecretKey') : ep_setting('secretKey');
}

/* --------------------------------------------------------------- signature */

/**
 * Sign an ordered field list. Empty values become '-'.
 */
function ep_sign(array $orderedValues, $key = null)
{
    $key = $key === null ? ep_secret_key() : $key;
    $buffer = '';
    foreach ($orderedValues as $value) {
        $value = (string) $value;
        $buffer .= strlen($value) ? strlen($value) . $value : '-';
    }
    return strtoupper(hash_hmac('sha1', $buffer, pack('H*', trim($key))));
}

/**
 * Verify an inbound payment response (IPN / backurl POST).
 */
function ep_verify_response(array $post)
{
    $signed = [
        isset($post['amount']) ? $post['amount'] : '',
        isset($post['curr']) ? $post['curr'] : '',
        isset($post['invoice_id']) ? $post['invoice_id'] : '',
        isset($post['ep_id']) ? $post['ep_id'] : '',
        isset($post['merch_id']) ? $post['merch_id'] : '',
        isset($post['action']) ? $post['action'] : '',
        isset($post['message']) ? $post['message'] : '',
        isset($post['approval']) ? $post['approval'] : '',
        isset($post['timestamp']) ? $post['timestamp'] : '',
        isset($post['nonce']) ? $post['nonce'] : '',
    ];
    $expected = ep_sign($signed);
    $given = isset($post['fp_hash']) ? strtoupper(trim((string) $post['fp_hash'])) : '';
    return $given !== '' && hash_equals($expected, $given);
}

/* ----------------------------------------------------------- payment page */

/**
 * Build the signed field set for the hosted payment page.
 */
function ep_payment_fields(array $args)
{
    $fields = [
        'amount'     => number_format((float) $args['amount'], 2, '.', ''),
        'curr'       => $args['currency'],
        'invoice_id' => (string) $args['invoice_id'],
        'order_desc' => $args['description'],
        'merch_id'   => ep_merchant_id(),
        'timestamp'  => gmdate('YmdHis'),
        'nonce'      => md5(uniqid(mt_rand(), true)),
    ];
    if (!empty($args['recurrent_freq'])) {
        $fields['recurent_freq'] = (string) $args['recurrent_freq'];
    }
    if (!empty($args['recurrent_exp'])) {
        $fields['recurent_exp'] = (string) $args['recurrent_exp'];
    }
    $fields['fp_hash'] = ep_sign($fields);

    // Unsigned extras.
    foreach (['success_url', 'fail_url', 'back_to_site', 'silent_url', 'lang', 'rate', 'filterRate', 'channel'] as $opt) {
        if (!empty($args[$opt])) {
            $fields[$opt] = $args[$opt];
        }
    }
    if (!empty($args['extra']) && is_array($args['extra'])) {
        foreach ($args['extra'] as $k => $v) {
            $fields['Extra[' . $k . ']'] = $v;
        }
    }
    // Billing details.
    $billing = [
        'order_email'   => 'email',
        'order_phone'   => 'phonenumber',
        'order_delivery' => 'address1',
        'order_city'    => 'city',
        'order_county'  => 'state',
        'order_zip'     => 'postcode',
        'order_country' => 'country',
        'order_company' => 'companyname',
        'order_fname'   => 'firstname',
        'order_lname'   => 'lastname',
    ];
    foreach ($billing as $wire => $src) {
        if (!empty($args['client'][$src])) {
            $fields[$wire] = $args['client'][$src];
        }
    }
    return $fields;
}

/* ------------------------------------------------------------- backoffice */

/**
 * Backoffice (manager.euplatesc.ro) web-service call. Capture, reversal,
 * refund, status and reporting live here and require the user credentials
 * (userKey/userApi from the EuPlătesc panel).
 * The ws action names map to the EuPlătesc backoffice methods.
 */
function ep_ws($action, array $fields = [])
{
    $payload = array_merge([
        'mid'        => ep_merchant_id(),
        'user_key'   => ep_setting('userKey'),
        'user_api'   => ep_setting('userApi'),
        'ws_action'  => $action,
        'timestamp'  => gmdate('YmdHis'),
        'nonce'      => md5(uniqid(mt_rand(), true)),
    ], $fields);
    $payload['fp_hash'] = ep_sign(array_values($payload));

    $ch = curl_init(EP_WS_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return ['error' => $err];
    }
    $decoded = json_decode((string) $body, true);
    return is_array($decoded) ? $decoded : ['raw' => $body];
}

function ep_ws_ok($resp)
{
    return is_array($resp) && isset($resp['success']);
}

/* ---------------------------------------------- backoffice method wrappers */

function ep_get_status($epId, $invoiceId = '')
{
    return ep_ws('get_status', ['ep_id' => $epId, 'invoice_id' => $invoiceId]);
}

function ep_capture($epId)
{
    return ep_ws('capture', ['ep_id' => $epId]);
}

function ep_reversal($epId)
{
    return ep_ws('reversal', ['ep_id' => $epId]);
}

function ep_partial_capture($epId, $amount)
{
    return ep_ws('partial_capture', ['ep_id' => $epId, 'amount' => number_format((float) $amount, 2, '.', '')]);
}

function ep_refund($epId, $amount, $reason = '')
{
    return ep_ws('refund', [
        'ep_id' => $epId,
        'amount' => number_format((float) $amount, 2, '.', ''),
        'reason' => $reason,
    ]);
}

function ep_cancel_recurring($epId, $reason = '')
{
    return ep_ws('cancel_recurring', ['ep_id' => $epId, 'reason' => $reason]);
}

function ep_update_invoice_id($epId, $newInvoiceId)
{
    return ep_ws('update_invoice_id', ['ep_id' => $epId, 'invoice_id' => $newInvoiceId]);
}

function ep_invoice_list($from = '', $to = '')
{
    return ep_ws('invoice_list', ['from' => $from, 'to' => $to]);
}

function ep_invoice_transactions($settlementInvoice)
{
    return ep_ws('invoice_transactions', ['invoice' => $settlementInvoice]);
}

function ep_captured_total($from = '', $to = '')
{
    return ep_ws('captured_total', ['from' => $from, 'to' => $to]);
}

function ep_card_art($epId)
{
    return ep_ws('card_art', ['ep_id' => $epId]);
}

function ep_saved_cards($clientId)
{
    return ep_ws('saved_cards', ['client_id' => $clientId]);
}

function ep_remove_card($clientId, $cardId)
{
    return ep_ws('remove_card', ['client_id' => $clientId, 'card_id' => $cardId]);
}

function ep_check_mid()
{
    return ep_ws('check_mid', []);
}
