<?php
/**
 * ServerSpan EuPlatesc - WHMCS payment gateway
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/gateways/euplatesc.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/euplatesc/lib/EpApi.php';

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
    return [
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
}

function euplatesc_config_validate()
{
    // Nothing hard-validated server-side; the addon's Dashboard > Check MID verifies credentials.
}

function euplatesc_link($params)
{
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
        // Recurring services: 30-day frequency until cancelled.
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
