<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/RevolutGateway.php';

use RevolutWhmcs\AppHelpers;
use RevolutWhmcs\FlowStore;
use RevolutWhmcs\RevolutClient;
use RevolutWhmcs\Support;

try {
    $gatewayParams = AppHelpers::gatewayParams();
    $encodedState = (string)($_POST['state'] ?? '');
    $signature = (string)($_POST['sig'] ?? '');

    if (!Support::verifyState($encodedState, $signature, (string)$gatewayParams['secretApiKey'])) {
        throw new RuntimeException('Invalid or tampered WHMCS checkout state.');
    }

    $state = Support::decodeState($encodedState);
    if ((int)($state['v'] ?? 0) !== 1 || abs(time() - (int)($state['iat'] ?? 0)) > 1800) {
        throw new RuntimeException('Checkout state has expired. Reload the invoice and try again.');
    }

    $action = (string)($state['action'] ?? '');
    if (!in_array($action, ['payment', 'create', 'update'], true)) {
        throw new RuntimeException('Invalid checkout action.');
    }

    $currency = strtoupper((string)($state['currency'] ?? 'EUR'));
    $amountMinor = $action === 'payment' ? Support::amountToMinor((string)$state['amount'], $currency) : 0;
    $amountMajor = Support::minorToMajorString($amountMinor, $currency);
    $nonce = (string)($state['flow_nonce'] ?? '');
    if (!preg_match('/^[a-f0-9]{48}$/', $nonce)) {
        throw new RuntimeException('Invalid checkout flow identifier.');
    }
    $completeBase = AppHelpers::moduleBaseUrl($gatewayParams) . '/complete.php?flow=' . rawurlencode($nonce);
    $completeSig = hash_hmac('sha256', $nonce, (string)$gatewayParams['secretApiKey']);
    $completeUrl = $completeBase . '&sig=' . rawurlencode($completeSig);

    $reference = $action === 'payment'
        ? 'whmcs:invoice:' . (int)$state['invoice_id']
        : 'whmcs:client:' . (int)$state['client_id'] . ':' . $action . ':' . (int)($state['paymethod_id'] ?? 0);

    $customer = [
        'email' => (string)$state['email'],
        'full_name' => trim((string)$state['first_name'] . ' ' . (string)$state['last_name']),
    ];
    $phone = trim((string)($state['phone'] ?? ''));
    if (preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
        $customer['phone'] = $phone;
    } else {
        $phone = '';
    }

    $force3DS = Support::isTruthy($gatewayParams['force3DS'] ?? false);
    $allowCardSaveChoice = $action === 'payment' && Support::isTruthy($gatewayParams['allowCardSaveChoice'] ?? false);
    $locale = Support::revolutLocale((string)($state['language'] ?? ''));

    $merchantUrl = $action === 'payment'
        ? Support::safeReturnUrl((string)($state['return_url'] ?? ''), (string)$gatewayParams['systemurl'])
        : rtrim((string)$gatewayParams['systemurl'], '/') . '/clientarea.php?action=paymentmethods';

    $orderPayload = [
        'amount' => $amountMinor,
        'currency' => $currency,
        'description' => $action === 'payment'
            ? 'WHMCS invoice #' . (int)$state['invoice_id']
            : 'WHMCS payment method setup',
        'customer' => $customer,
        'merchant_order_data' => [
            'reference' => $reference,
            'url' => $merchantUrl,
        ],
        'redirect_url' => $completeUrl,
        'capture_mode' => 'automatic',
        'enforce_challenge' => $force3DS ? 'forced' : 'automatic',
    ];

    $client = RevolutClient::fromGatewayParams($gatewayParams);
    $existingFlow = FlowStore::byNonce($nonce);
    if ($existingFlow) {
        if ((int)$existingFlow->client_id !== (int)$state['client_id']
            || (string)$existingFlow->action !== $action
            || (int)$existingFlow->amount_minor !== $amountMinor
            || strcasecmp((string)$existingFlow->currency, $currency) !== 0
            || (int)($existingFlow->invoice_id ?? 0) !== (int)($state['invoice_id'] ?? 0)) {
            throw new RuntimeException('Stored checkout flow does not match the signed request.');
        }
        if (!empty($existingFlow->processed_at)) {
            header('Location: ' . $completeUrl, true, 303);
            exit;
        }
        $orderId = (string)$existingFlow->revolut_order_id;
        $orderToken = (string)$existingFlow->revolut_order_token;
    } else {
        $order = $client->createOrder($orderPayload, 'whmcs-flow-' . substr(hash('sha256', $nonce), 0, 48));
        $orderId = (string)($order['id'] ?? '');
        $orderToken = (string)($order['token'] ?? '');
        if ($orderId === '' || $orderToken === '') {
            throw new RuntimeException('Revolut did not return the required order identifiers.');
        }

        FlowStore::create([
            'nonce' => $nonce,
            'revolut_order_id' => $orderId,
            'revolut_order_token' => $orderToken,
            'invoice_id' => (int)($state['invoice_id'] ?? 0) ?: null,
            'client_id' => (int)$state['client_id'],
            'action' => $action,
            'paymethod_id' => (int)($state['paymethod_id'] ?? 0) ?: null,
            'amount_minor' => $amountMinor,
            'amount_major' => $amountMajor,
            'currency' => $currency,
            'return_url' => (string)($state['return_url'] ?? ''),
            'old_gateway_token' => (string)($state['old_gateway_token'] ?? ''),
        ]);
    }

    $mode = Support::isTruthy($gatewayParams['sandbox'] ?? false) ? 'sandbox' : 'prod';
    $enableRevolutPay = $action === 'payment'
        && !$force3DS
        && Support::isTruthy($gatewayParams['enableRevolutPay'] ?? false)
        && trim((string)($gatewayParams['publicApiKey'] ?? '')) !== '';

    $billingAddress = array_filter([
        'countryCode' => (string)($state['country'] ?? ''),
        'region' => (string)($state['state'] ?? ''),
        'city' => (string)($state['city'] ?? ''),
        'postcode' => (string)($state['postcode'] ?? ''),
        'streetLine1' => (string)($state['address1'] ?? ''),
        'streetLine2' => (string)($state['address2'] ?? ''),
    ], static fn($v) => $v !== '');

    $customerJs = [
        'name' => trim((string)$state['first_name'] . ' ' . (string)$state['last_name']),
        'email' => (string)$state['email'],
        'phone' => $phone,
        'billingAddress' => $billingAddress,
    ];
} catch (Throwable $e) {
    http_response_code(400);
    echo '<!doctype html><meta charset="utf-8"><style>body{font-family:system-ui;padding:24px;color:#b42318}.box{border:1px solid #f1b4ad;background:#fff4f2;padding:16px;border-radius:10px}</style><div class="box"><strong>Unable to start Revolut checkout.</strong><br>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    exit;
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Secure payment</title>
    <script src="https://merchant.revolut.com/embed.js"></script>
    <style>
        *{box-sizing:border-box}body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;padding:20px;background:#fff;color:#111827}.wrap{max-width:640px;margin:0 auto}.heading{font-size:18px;font-weight:700;margin:0 0 4px}.sub{font-size:13px;color:#6b7280;margin:0 0 18px}.card{border:1px solid #e5e7eb;border-radius:12px;padding:16px;background:#fff}.label{display:block;font-size:13px;font-weight:600;margin:0 0 7px}.input{width:100%;border:1px solid #d1d5db;border-radius:9px;padding:11px 12px;font:inherit;margin:0 0 14px}.input:focus{outline:2px solid #111827;outline-offset:1px}.field{min-height:52px}.save{display:flex;align-items:flex-start;gap:8px;margin-top:12px;font-size:13px;color:#374151}.save input{margin-top:3px}.btn{width:100%;margin-top:14px;border:0;border-radius:9px;padding:12px 16px;background:#111827;color:#fff;font-weight:700;font-size:15px;cursor:pointer}.btn:disabled{opacity:.55;cursor:not-allowed}.sep{display:flex;align-items:center;gap:10px;margin:18px 0;color:#9ca3af;font-size:12px}.sep:before,.sep:after{content:"";height:1px;background:#e5e7eb;flex:1}.status{margin-top:12px;font-size:13px;min-height:20px}.error{color:#b42318}.ok{color:#067647}.rp{margin-top:8px}.secure{font-size:12px;color:#6b7280;margin-top:14px;text-align:center}
    </style>
</head>
<body>
<div class="wrap">
    <p class="heading"><?= $action === 'payment' ? 'Pay securely with Revolut' : 'Authorise a payment method' ?></p>
    <p class="sub"><?= $action === 'payment' ? htmlspecialchars($amountMajor . ' ' . $currency, ENT_QUOTES, 'UTF-8') : 'No charge will be made. The card is authorised for future automatic billing.' ?></p>
    <div class="card">
        <label class="label" for="cardholder-name">Cardholder name</label>
        <input class="input" id="cardholder-name" type="text" autocomplete="cc-name" required value="<?= htmlspecialchars(trim((string)$state['first_name'] . ' ' . (string)$state['last_name']), ENT_QUOTES, 'UTF-8') ?>">
        <div id="card-field" class="field"></div>
        <?php if ($allowCardSaveChoice): ?>
            <label class="save"><input type="checkbox" id="save-card" checked> <span>Save this card for future automatic payments</span></label>
        <?php endif; ?>
        <button type="button" id="card-submit" class="btn"><?= $action === 'payment' ? 'Pay now' : 'Save card' ?></button>
        <div id="status" class="status"></div>
    </div>
    <?php if ($enableRevolutPay): ?>
        <div class="sep">or</div>
        <div id="revolut-pay" class="rp"></div>
    <?php endif; ?>
    <div class="secure">Card details are entered directly into Revolut's secure field and never pass through WHMCS.</div>
</div>
<script>
(function(){
    const orderToken = <?= json_encode($orderToken, JSON_UNESCAPED_SLASHES) ?>;
    const mode = <?= json_encode($mode) ?>;
    const completeUrl = <?= json_encode($completeUrl, JSON_UNESCAPED_SLASHES) ?>;
    const customer = <?= json_encode($customerJs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const locale = <?= json_encode($locale) ?>;
    const action = <?= json_encode($action) ?>;
    const allowCardSaveChoice = <?= $allowCardSaveChoice ? 'true' : 'false' ?>;
    const statusEl = document.getElementById('status');
    const button = document.getElementById('card-submit');
    const nameInput = document.getElementById('cardholder-name');
    const saveCardInput = document.getElementById('save-card');
    let finishing = false;

    function status(message, isError) {
        statusEl.textContent = message || '';
        statusEl.className = 'status ' + (isError ? 'error' : '');
    }
    function finish() {
        if (finishing) return;
        finishing = true;
        button.disabled = true;
        status('Confirming payment...', false);
        window.location.href = completeUrl;
    }
    function errorMessage(error) {
        if (!error) return 'Payment failed. Please try again.';
        return error.message || error.error || String(error);
    }

    if (typeof RevolutCheckout !== 'function') {
        status('Revolut Checkout failed to load. Check Content-Security-Policy/ad-blocking and reload.', true);
        button.disabled = true;
        return;
    }

    RevolutCheckout(orderToken, mode).then(function(instance){
        const cardOptions = {
            target: document.getElementById('card-field'),
            name: customer.name,
            email: customer.email,
            phone: customer.phone || undefined,
            billingAddress: customer.billingAddress,
            locale: locale,
            showLoadingIndicator: true,
            onSuccess: finish,
            onError: function(err){
                button.disabled = false;
                finishing = false;
                status(errorMessage(err), true);
            },
            onCancel: function(){
                button.disabled = false;
                finishing = false;
                status('Payment was cancelled.', true);
            }
        };
        if (!allowCardSaveChoice || action !== 'payment') {
            cardOptions.savePaymentMethodFor = 'merchant';
        }
        const card = instance.createCardField(cardOptions);

        button.addEventListener('click', function(){
            const cardholderName = (nameInput.value || '').trim();
            if (!cardholderName) {
                status('Enter the cardholder name.', true);
                nameInput.focus();
                return;
            }

            button.disabled = true;
            status('Processing...', false);
            const submitData = {
                name: cardholderName,
                email: customer.email,
                phone: customer.phone || undefined,
                billingAddress: customer.billingAddress
            };
            if (!allowCardSaveChoice || !saveCardInput || saveCardInput.checked) {
                submitData.savePaymentMethodFor = 'merchant';
            }
            card.submit(submitData);
        });
    }).catch(function(err){
        status(errorMessage(err), true);
        button.disabled = true;
    });

    <?php if ($enableRevolutPay): ?>
    if (RevolutCheckout.payments) {
        RevolutCheckout.payments({
            publicToken: <?= json_encode((string)$gatewayParams['publicApiKey']) ?>,
            mode: mode,
            locale: locale
        }).then(function(modules){
            const rp = modules.revolutPay;
            rp.mount('#revolut-pay', {
                currency: <?= json_encode($currency) ?>,
                totalAmount: <?= (int)$amountMinor ?>,
                createOrder: async function(){ return { publicId: orderToken }; },
                customer: customer,
                savePaymentMethodForMerchant: false,
                buttonStyle: {size:'large',action:'pay'},
                mobileRedirectUrls: {
                    success: completeUrl + '&outcome=success',
                    failure: completeUrl + '&outcome=failure',
                    cancel: completeUrl + '&outcome=cancel'
                }
            });
            rp.on('payment', function(event){
                if (event.type === 'success') finish();
                if (event.type === 'error') status(errorMessage(event.error), true);
                if (event.type === 'cancel') status('Revolut Pay was cancelled.', true);
            });
        }).catch(function(){
            document.getElementById('revolut-pay').style.display = 'none';
        });
    }
    <?php endif; ?>
})();
</script>
</body>
</html>
