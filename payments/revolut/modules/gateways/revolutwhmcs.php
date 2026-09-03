<?php
/**
 * Revolut Gateway for WHMCS
 * Maintained by ServerSpan - https://www.serverspan.com/
 * Repository: https://github.com/serverspan/whmcs-modules
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/revolutwhmcs/lib/RevolutGateway.php';

use RevolutWhmcs\ApiException;
use RevolutWhmcs\RevolutClient;
use RevolutWhmcs\Support;

function revolutwhmcs_MetaData(): array
{
    return [
        'DisplayName' => 'Revolut Gateway',
        'APIVersion' => '1.1',
    ];
}

function revolutwhmcs_config(): array
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Revolut Gateway',
        ],
        'secretApiKey' => [
            'FriendlyName' => 'Secret API Key',
            'Type' => 'password',
            'Size' => '64',
            'Description' => 'Revolut Merchant API secret key (sk_...).',
        ],
        'publicApiKey' => [
            'FriendlyName' => 'Public API Key',
            'Type' => 'text',
            'Size' => '64',
            'Description' => 'Required only when Revolut Pay is enabled.',
        ],
        'webhookSigningSecret' => [
            'FriendlyName' => 'Webhook Signing Secret',
            'Type' => 'password',
            'Size' => '64',
            'Description' => 'Signing secret (wsk_...) returned when the Revolut webhook is created.',
        ],
        'enableRevolutPay' => [
            'FriendlyName' => 'Enable Revolut Pay',
            'Type' => 'yesno',
            'Description' => 'Show Revolut Pay for one-time invoice payments.',
        ],
        'allowCardSaveChoice' => [
            'FriendlyName' => 'Let Customers Choose Card Saving',
            'Type' => 'yesno',
            'Description' => 'For invoice payments, show a checkbox allowing the customer to opt out of saving the card for future automatic billing. Add/Update Payment Method always saves.',
        ],
        'force3DS' => [
            'FriendlyName' => 'Force 3D Secure',
            'Type' => 'yesno',
            'Description' => 'Force a 3DS challenge for card checkout. Revolut Pay is hidden while this is enabled because forced challenge is card-only.',
        ],
        'recordFees' => [
            'FriendlyName' => 'Record Revolut Fees',
            'Type' => 'yesno',
            'Description' => 'Record same-currency Revolut acquiring fees in the WHMCS transaction fee field.',
        ],
        'sandbox' => [
            'FriendlyName' => 'Sandbox',
            'Type' => 'yesno',
            'Description' => 'Use Revolut Merchant Sandbox endpoints and sandbox Checkout mode.',
        ],
        'apiVersion' => [
            'FriendlyName' => 'Merchant API Version',
            'Type' => 'text',
            'Size' => '16',
            'Default' => '2026-04-20',
            'Description' => 'Pinned API version. Current implementation target: 2026-04-20.',
        ],
        'apiTimeout' => [
            'FriendlyName' => 'API Timeout',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '30',
            'Description' => 'HTTP timeout in seconds.',
        ],
    ];
}

function revolutwhmcs_nolocalcc(): void
{
}

/**
 * Remote token lifecycle hook. Remote Input handles create/update; WHMCS uses
 * the documented storeremote delete action when a tokenised Pay Method is removed.
 */
function revolutwhmcs_storeremote(array $params): array
{
    if ((string)($params['action'] ?? '') !== 'delete') {
        return [
            'status' => 'error',
            'rawdata' => ['error' => 'Create/update are handled by the Remote Input gateway flow.'],
        ];
    }

    try {
        $token = Support::decodeGatewayToken((string)($params['gatewayid'] ?? ''));
        $client = RevolutClient::fromGatewayParams($params);
        $client->deleteCustomerPaymentMethod($token['customer_id'], $token['payment_method_id']);

        return [
            'status' => 'success',
            'rawdata' => ['deleted' => true, 'payment_method_id' => $token['payment_method_id']],
        ];
    } catch (ApiException $e) {
        // A 404 means the remote payment method is already gone; local deletion is safe.
        if ($e->getStatusCode() === 404) {
            return [
                'status' => 'success',
                'rawdata' => ['deleted' => true, 'already_missing' => true],
            ];
        }
        return [
            'status' => 'error',
            'rawdata' => $e->getResponseData() + ['error' => $e->getMessage()],
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'rawdata' => ['error' => $e->getMessage()],
        ];
    }
}

function revolutwhmcs_remoteinput(array $params): string
{
    $action = ((int)($params['invoiceid'] ?? 0) > 0 && (float)($params['amount'] ?? 0) > 0) ? 'payment' : 'create';
    return revolutwhmcs_buildRemoteForm($params, $action);
}

function revolutwhmcs_remoteupdate(array $params): string
{
    $form = revolutwhmcs_buildRemoteForm($params, 'update', true);
    return '<div id="revolutWhmcsRemoteUpdate" class="text-center">' . $form .
        '<iframe name="revolutWhmcsUpdateFrame" class="auth3d-area" width="100%" height="650" scrolling="auto" src="about:blank" style="border:0"></iframe>' .
        '</div><script>(function(){var f=document.querySelector("#revolutWhmcsRemoteUpdate form");if(f){f.target="revolutWhmcsUpdateFrame";f.submit();}})();</script>';
}

function revolutwhmcs_buildRemoteForm(array $params, string $action, bool $forUpdate = false): string
{
    $client = $params['clientdetails'] ?? [];
    $clientId = 0;
    if (isset($client['model']) && is_object($client['model']) && isset($client['model']->id)) {
        $clientId = (int)$client['model']->id;
    } else {
        $clientId = (int)($client['id'] ?? ($client['userid'] ?? 0));
    }
    if ($clientId <= 0) {
        throw new RuntimeException('Unable to determine the WHMCS client ID.');
    }

    $state = [
        'v' => 1,
        'iat' => time(),
        'flow_nonce' => bin2hex(random_bytes(24)),
        'action' => $action,
        'invoice_id' => (int)($params['invoiceid'] ?? 0),
        'amount' => $action === 'payment' ? (string)($params['amount'] ?? '0') : '0',
        'currency' => strtoupper((string)($params['currency'] ?? 'EUR')),
        'client_id' => $clientId,
        'first_name' => (string)($client['firstname'] ?? ''),
        'last_name' => (string)($client['lastname'] ?? ''),
        'email' => (string)($client['email'] ?? ''),
        'phone' => (string)($client['phonenumber'] ?? ''),
        'address1' => (string)($client['address1'] ?? ''),
        'address2' => (string)($client['address2'] ?? ''),
        'city' => (string)($client['city'] ?? ''),
        'state' => (string)($client['state'] ?? ''),
        'postcode' => (string)($client['postcode'] ?? ''),
        'country' => strtoupper((string)($client['country'] ?? '')),
        'language' => (string)($client['language'] ?? ((isset($client['model']) && is_object($client['model'])) ? ($client['model']->language ?? '') : '')),
        'return_url' => (string)($params['returnurl'] ?? ''),
        'paymethod_id' => (int)($params['paymethodid'] ?? 0),
        'old_gateway_token' => $action === 'update' ? (string)($params['gatewayid'] ?? '') : '',
    ];

    $encoded = Support::encodeState($state);
    $signature = Support::signState($encoded, (string)($params['secretApiKey'] ?? ''));
    $systemUrl = (string)($params['systemurl'] ?? '');
    if ($systemUrl === '') {
        $systemUrl = (string)(\WHMCS\Config\Setting::getValue('SystemURL') ?? '');
    }
    $actionUrl = rtrim($systemUrl, '/') . '/modules/gateways/revolutwhmcs/checkout.php';

    $target = $forUpdate ? ' target="revolutWhmcsUpdateFrame"' : '';
    return '<form method="post" action="' . htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') . '"' . $target . '>' .
        '<input type="hidden" name="state" value="' . htmlspecialchars($encoded, ENT_QUOTES, 'UTF-8') . '">' .
        '<input type="hidden" name="sig" value="' . htmlspecialchars($signature, ENT_QUOTES, 'UTF-8') . '">' .
        '<noscript><button type="submit">Continue to secure payment</button></noscript>' .
        '</form>';
}

function revolutwhmcs_capture(array $params): array
{
    try {
        $token = Support::decodeGatewayToken((string)($params['gatewayid'] ?? ''));
        $currency = strtoupper((string)$params['currency']);
        $amountMinor = Support::amountToMinor($params['amount'], $currency);
        if ($amountMinor <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        $client = RevolutClient::fromGatewayParams($params);
        $invoiceId = (int)$params['invoiceid'];
        $idempotencySeed = 'capture|' . $invoiceId . '|' . $amountMinor . '|' . $currency . '|' . $token['payment_method_id'];
        $order = $client->createOrder([
            'amount' => $amountMinor,
            'currency' => $currency,
            'description' => (string)($params['description'] ?? ('WHMCS invoice #' . $invoiceId)),
            'customer' => ['id' => $token['customer_id']],
            'merchant_order_data' => [
                'reference' => 'whmcs:invoice:' . $invoiceId,
                'url' => (string)($params['returnurl'] ?? ''),
            ],
            'capture_mode' => 'automatic',
        ], 'whmcs-' . substr(hash('sha256', $idempotencySeed), 0, 48));

        $orderId = (string)($order['id'] ?? '');
        if ($orderId === '') {
            throw new RuntimeException('Revolut did not return an order ID.');
        }

        $payment = $client->payOrderWithSavedMethod(
            $orderId,
            $token['payment_method_id'],
            $token['type']
        );
        $state = strtolower((string)($payment['state'] ?? ''));

        if (in_array($state, ['captured', 'completed'], true)) {
            $fee = 0.0;
            if (Support::isTruthy($params['recordFees'] ?? false)) {
                $fee = (float)Support::minorToMajorString(Support::paymentFeeMinor($payment, $currency), $currency);
            }
            return [
                'status' => 'success',
                'transid' => $orderId,
                'fee' => $fee,
                'rawdata' => $payment,
            ];
        }

        if (in_array($state, [
            'pending',
            'authentication_challenge',
            'authentication_verified',
            'authorisation_started',
            'authorisation_passed',
            'authorised',
            'capture_started',
            'completing',
        ], true)) {
            return [
                'status' => 'pending',
                'transid' => $orderId,
                'rawdata' => $payment,
            ];
        }

        return [
            'status' => 'declined',
            'declinereason' => (string)($payment['decline_reason'] ?? ('Revolut payment state: ' . $state)),
            'rawdata' => $payment,
        ];
    } catch (ApiException $e) {
        return [
            'status' => $e->getStatusCode() === 422 ? 'declined' : 'error',
            'declinereason' => $e->getMessage(),
            'rawdata' => $e->getResponseData(),
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'declinereason' => $e->getMessage(),
            'rawdata' => ['error' => $e->getMessage()],
        ];
    }
}

function revolutwhmcs_refund(array $params): array
{
    try {
        $currency = strtoupper((string)$params['currency']);
        $amountMinor = Support::amountToMinor($params['amount'], $currency);
        if ($amountMinor <= 0) {
            throw new RuntimeException('Refund amount must be greater than zero.');
        }

        $orderId = (string)$params['transid'];
        $client = RevolutClient::fromGatewayParams($params);
        // A WHMCS invoice may legitimately receive two partial refunds of the same amount.
        // Do not collapse those into one Revolut idempotency key. The client does not retry
        // this POST internally, so a per-request key is the least-surprising behaviour.
        $refund = $client->refundOrder(
            $orderId,
            $amountMinor,
            $currency,
            'whmcs-refund-' . bin2hex(random_bytes(16))
        );

        return [
            'status' => 'success',
            'transid' => (string)($refund['id'] ?? $orderId),
            'rawdata' => $refund,
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'rawdata' => ['error' => $e->getMessage()],
        ];
    }
}
