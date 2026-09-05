<?php
/**
 * ServerSpan EuPlatesc Manager - shared library
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/epmanager/lib/Functions.php
 *
 * Reuses the gateway library (modules/gateways/euplatesc/lib/EpApi.php) so
 * credentials and signing exist in exactly one place.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

$epLib = (defined('ROOTDIR') ? ROOTDIR : dirname(__FILE__, 5))
    . '/modules/gateways/euplatesc/lib/EpApi.php';
if (file_exists($epLib) && !function_exists('ep_sign')) {
    require_once $epLib;
}

function epm_gateway_installed()
{
    return function_exists('ep_sign') && ep_setting('merchantId') !== '';
}

function epm_gateway_active()
{
    return Capsule::table('tblpaymentgateways')
        ->where('gateway', 'euplatesc')->where('setting', 'active')->value('value') === '1';
}

function epm_log($invoiceId, $event, $epId, $payload)
{
    Capsule::table('mod_ep_log')->insert([
        'invoice_id' => (int) $invoiceId,
        'event'      => $event,
        'ep_id'      => (string) $epId,
        'payload'    => is_string($payload) ? $payload : json_encode($payload),
        'ip'         => isset($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Run a backoffice action and log the outcome. Returns [ok, message].
 */
function epm_run_action($action, $epId, array $extra = [])
{
    switch ($action) {
        case 'capture':
            $resp = ep_capture($epId);
            break;
        case 'reversal':
            $resp = ep_reversal($epId);
            break;
        case 'partial_capture':
            $resp = ep_partial_capture($epId, isset($extra['amount']) ? $extra['amount'] : 0);
            break;
        case 'refund':
            $resp = ep_refund($epId, isset($extra['amount']) ? $extra['amount'] : 0,
                isset($extra['reason']) ? $extra['reason'] : '');
            break;
        case 'cancel_recurring':
            $resp = ep_cancel_recurring($epId, isset($extra['reason']) ? $extra['reason'] : '');
            break;
        case 'update_invoice_id':
            $resp = ep_update_invoice_id($epId, isset($extra['invoice_id']) ? $extra['invoice_id'] : '');
            break;
        default:
            return [false, 'Unknown action.'];
    }
    $ok = ep_ws_ok($resp);
    epm_log(isset($extra['invoice_id']) ? $extra['invoice_id'] : 0, 'ws_' . $action, $epId,
        $ok ? $resp['success'] : $resp);
    return $ok ? [true, is_string($resp['success']) ? $resp['success'] : 'OK']
        : [false, isset($resp['error']) ? $resp['error'] : 'Unexpected response'];
}
