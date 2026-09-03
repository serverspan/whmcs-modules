<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/RevolutGateway.php';

use RevolutWhmcs\AppHelpers;
use RevolutWhmcs\FlowStore;
use RevolutWhmcs\Processor;
use RevolutWhmcs\Support;

try {
    $gatewayParams = AppHelpers::gatewayParams();
    $nonce = (string)($_GET['flow'] ?? '');
    $sig = (string)($_GET['sig'] ?? '');
    $expected = hash_hmac('sha256', $nonce, (string)$gatewayParams['secretApiKey']);
    if ($nonce === '' || !hash_equals($expected, $sig)) {
        throw new RuntimeException('Invalid payment completion token.');
    }

    $flow = FlowStore::byNonce($nonce);
    if (!$flow) {
        throw new RuntimeException('Payment flow was not found.');
    }

    try {
        $result = Processor::processFlow($flow, $gatewayParams);
    } catch (Throwable $e) {
        $flow = FlowStore::byNonce($nonce) ?: $flow;
        if ((string)$flow->action === 'payment' && (int)$flow->invoice_id > 0) {
            logTransaction('revolutwhmcs', ['order_id' => $flow->revolut_order_id, 'error' => $e->getMessage()], 'Completion Pending/Failed');
            callback3DSecureRedirect((int)$flow->invoice_id, false);
            exit;
        }
        throw $e;
    }

    $flow = $result['flow'] ?? $flow;

    // The webhook and browser can arrive almost simultaneously. If the webhook
    // currently owns the flow lock, give it a brief bounded chance to finish
    // instead of presenting WHMCS with a false payment-failed result.
    if (($result['status'] ?? '') === 'processing') {
        for ($i = 0; $i < 6; $i++) {
            usleep(250000);
            $fresh = FlowStore::byNonce($nonce);
            if ($fresh && !empty($fresh->processed_at)) {
                $flow = $fresh;
                $result['status'] = 'processed';
                break;
            }
        }
    }

    if ((string)$flow->action === 'payment' && (int)$flow->invoice_id > 0) {
        $success = !empty($flow->invoice_applied) || in_array($result['status'] ?? '', ['success', 'processed'], true);
        if ($success) {
            callback3DSecureRedirect((int)$flow->invoice_id, true);
            exit;
        }

        // Revolut already completed the order, but another callback still owns
        // the reconciliation lock. Return to the invoice without falsely marking
        // the card attempt as failed; the verified webhook will finish the update.
        $returnUrl = Support::safeReturnUrl((string)($flow->return_url ?? ''), (string)$gatewayParams['systemurl']);
        echo '<!doctype html><meta charset="utf-8"><style>body{font-family:system-ui;padding:24px;text-align:center;color:#111827}.pending{padding:16px;border:1px solid #fedf89;background:#fffaeb;border-radius:10px}</style><div class="pending">Payment accepted. WHMCS is confirming the invoice status.</div><script>setTimeout(function(){try{window.parent.location.href=' . json_encode($returnUrl, JSON_UNESCAPED_SLASHES) . ';}catch(e){window.location.href=' . json_encode($returnUrl, JSON_UNESCAPED_SLASHES) . ';}},900);</script>';
        exit;
    }

    $success = !empty($flow->paymethod_saved) || in_array($result['status'] ?? '', ['success', 'processed'], true);
    $message = $success ? 'Payment method saved successfully.' : 'Payment method is still being confirmed. Please refresh the page shortly.';
    echo '<!doctype html><meta charset="utf-8"><style>body{font-family:system-ui;padding:24px;text-align:center;color:#111827}.ok{padding:16px;border:1px solid #abefc6;background:#ecfdf3;border-radius:10px}.pending{padding:16px;border:1px solid #fedf89;background:#fffaeb;border-radius:10px}</style><div class="' . ($success ? 'ok' : 'pending') . '">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div><script>setTimeout(function(){try{window.parent.location.reload();}catch(e){}},900);</script>';
} catch (Throwable $e) {
    http_response_code(400);
    echo '<!doctype html><meta charset="utf-8"><style>body{font-family:system-ui;padding:24px;color:#b42318}.box{border:1px solid #f1b4ad;background:#fff4f2;padding:16px;border-radius:10px}</style><div class="box"><strong>Revolut completion error.</strong><br>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
}
