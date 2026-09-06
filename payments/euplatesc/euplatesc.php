<?php
/**
 * ServerSpan EuPlatesc - WHMCS payment gateway
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/gateways/euplatesc.php
 *
 * The shared library loads lazily: a missing lib must degrade visibly, never
 * make the gateway silently disappear from the gateway list.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function euplatesc_bootstrap()
{
    if (function_exists('ep_sign')) {
        return true;
    }
    $lib = __DIR__ . '/euplatesc/lib/EpApi.php';
    if (is_file($lib)) {
        require_once $lib;
        return function_exists('ep_sign');
    }
    return false;
}

function euplatesc_MetaData()
{
    return [
        'DisplayName' => 'EuPlătesc (ServerSpan)',
        'APIVersion'  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function euplatesc_config()
{
    $fields = [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'EuPlătesc (ServerSpan)'],
        'merchantId' => [
            'FriendlyName' => 'Merchant ID (live)', 'Type' => 'text', 'Size' => '30',
            'Description' => 'EuPlătesc Panel > Integration Parameters.',
        ],
        'secretKey' => [
            'FriendlyName' => 'Secret Key (live)', 'Type' => 'password', 'Size' => '60',
        ],
        'testMerchantId' => [
            'FriendlyName' => 'Merchant ID (test)', 'Type' => 'text', 'Size' => '30',
        ],
        'testSecretKey' => [
            'FriendlyName' => 'Secret Key (test)', 'Type' => 'password', 'Size' => '60',
        ],
        'testMode' => [
            'FriendlyName' => 'Test Mode', 'Type' => 'yesno',
            'Description' => 'Use the test credentials above.',
        ],
        'userKey' => [
            'FriendlyName' => 'User Key (backoffice)', 'Type' => 'text', 'Size' => '40',
            'Description' => 'EuPlătesc Panel > Settings > User settings > Account permissions. '
                . 'Required for capture/refund/status/reporting.',
        ],
        'userApi' => [
            'FriendlyName' => 'User API (backoffice)', 'Type' => 'password', 'Size' => '40',
        ],
        'lang' => [
            'FriendlyName' => 'Payment Page Language', 'Type' => 'dropdown',
            'Options' => 'auto,ro,en,fr,de,it,es,hu', 'Default' => 'auto',
        ],
        'recurring' => [
            'FriendlyName' => 'Enable Recurring', 'Type' => 'yesno',
            'Description' => 'Send recurent_freq for invoices on recurring services (requires '
                . 'recurring enabled on your EuPlătesc account).',
        ],
        'installments' => [
            'FriendlyName' => 'Installments Filter', 'Type' => 'text', 'Size' => '40',
            'Description' => 'Optional, e.g. apb-3-4,btrl-5-6. Leave empty to allow all.',
        ],
    ];
    if (!euplatesc_bootstrap()) {
        $fields['_libwarning'] = [
            'FriendlyName' => 'Installation Problem', 'Type' => 'text', 'Size' => '0',
            'Description' => 'modules/gateways/euplatesc/lib/EpApi.php is missing — '
                . 'copy the lib folder from the package or the gateway will not work.',
        ];
    }
    return $fields;
}

function euplatesc_link($params)
{
    if (!euplatesc_bootstrap()) {
        return '<div class="alert alert-danger">EuPlătesc gateway is not fully installed '
            . '(missing modules/gateways/euplatesc/lib/EpApi.php). Contact support.</div>';
    }

    $systemUrl = rtrim($params['systemurl'], '/');
    $invoiceId = (int) $params['invoiceid'];

    $args = [
        'amount'      => $params['amount'],
        'currency'    => $params['currency'],
        'invoice_id'  => $invoiceId,
        'description' => $params['description'],
        'client'      => $params['clientdetails'],
        'success_url' => $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentsuccess=true',
        'fail_url'    => $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentfailed=true',
        'back_to_site' => $systemUrl . '/viewinvoice.php?id=' . $invoiceId,
        'silent_url'  => $systemUrl . '/modules/gateways/callback/euplatesc.php',
        'extra'       => ['invoice_id' => $invoiceId],
    ];
    if (ep_setting('lang') && ep_setting('lang') !== 'auto') {
        $args['lang'] = ep_setting('lang');
    }
    if (ep_setting('installments')) {
        $args['filterRate'] = ep_setting('installments');
    }
    if (ep_setting('recurring', '') === 'on' && !empty($params['clientdetails']['tblhosting_recurring'])) {
        $args['recurrent_freq'] = '30';
        $args['recurrent_exp']  = gmdate('Ymd', strtotime('+1 year'));
    }

    $fields = ep_payment_fields($args);

    $form = '<form method="post" action="' . EP_GATEWAY_URL . '" id="epPayForm">';
    foreach ($fields as $name => $value) {
        $form .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="'
            . htmlspecialchars((string) $value) . '">';
    }
    $form .= '<button type="submit" class="btn btn-success">Pay by Card (EuPlătesc)</button></form>';
    $form .= '<script>document.getElementById("epPayForm").style.display="block";</script>';
    return $form;
}
