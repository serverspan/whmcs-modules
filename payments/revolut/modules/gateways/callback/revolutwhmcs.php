<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../revolutwhmcs/lib/RevolutGateway.php';

use RevolutWhmcs\AppHelpers;
use RevolutWhmcs\FlowStore;
use RevolutWhmcs\Processor;
use RevolutWhmcs\RevolutClient;
use RevolutWhmcs\Support;
use WHMCS\Database\Capsule;

$rawBody = (string)file_get_contents('php://input');
$timestamp = (string)($_SERVER['HTTP_REVOLUT_REQUEST_TIMESTAMP'] ?? '');
$signature = (string)($_SERVER['HTTP_REVOLUT_SIGNATURE'] ?? '');

try {
    $gatewayParams = AppHelpers::gatewayParams();
    $signingSecret = (string)($gatewayParams['webhookSigningSecret'] ?? '');
    if ($signingSecret === '') {
        throw new RuntimeException('Webhook signing secret is not configured.');
    }
    if (!Support::verifyWebhook($rawBody, $timestamp, $signature, $signingSecret)) {
        throw new RuntimeException('Invalid Revolut webhook signature or timestamp.');
    }

    $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    $eventName = (string)($event['event'] ?? '');
    $orderId = (string)($event['order_id'] ?? '');

    if ($orderId === '') {
        throw new RuntimeException('Webhook does not contain an order_id.');
    }

    logTransaction('revolutwhmcs', $event, 'Webhook ' . ($eventName ?: 'Unknown'));

    if ($eventName === 'ORDER_COMPLETED') {
        $flow = FlowStore::byOrderId($orderId);
        if ($flow) {
            Processor::processFlow($flow, $gatewayParams);
        } else {
            // Covers automatic recurring captures, which do not use the browser-flow table.
            $client = RevolutClient::fromGatewayParams($gatewayParams);
            $order = $client->getOrder($orderId);
            $reference = (string)($order['merchant_order_data']['reference'] ?? ($event['merchant_order_ext_ref'] ?? ''));
            if (preg_match('/^whmcs:invoice:(\d+)$/', $reference, $m)) {
                $invoiceId = (int)$m[1];
                $invoiceId = checkCbInvoiceID($invoiceId, 'revolutwhmcs');
                $alreadyExists = Capsule::table('tblaccounts')->where('transid', $orderId)->exists();
                if (!$alreadyExists && ($order['state'] ?? '') === 'completed') {
                    $currency = (string)$order['currency'];
                    $amount = Support::minorToMajorString((int)$order['amount'], $currency);
                    $fee = '0';
                    if (Support::isTruthy($gatewayParams['recordFees'] ?? false)) {
                        $fee = Support::minorToMajorString(Support::orderFeeMinor($order, $currency), $currency);
                    }
                    addInvoicePayment($invoiceId, $orderId, $amount, $fee, 'revolutwhmcs');
                }
            }
        }
    }

    http_response_code(204);
} catch (Throwable $e) {
    try {
        if (function_exists('logTransaction')) {
            logTransaction('revolutwhmcs', ['raw' => $rawBody, 'error' => $e->getMessage()], 'Webhook Rejected/Error');
        }
    } catch (Throwable) {
    }
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Webhook rejected';
}
