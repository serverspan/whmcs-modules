<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/gateways/revolutwhmcs/lib/RevolutGateway.php';

use RevolutWhmcs\Support;

$tests = 0;
$assert = static function (bool $condition, string $message) use (&$tests): void {
    $tests++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$assert(Support::amountToMinor('17.99', 'GBP') === 1799, 'GBP minor-unit conversion');
$assert(Support::amountToMinor(17.99, 'GBP') === 1799, 'GBP float-input minor-unit conversion');
$assert(Support::amountToMinor('100', 'JPY') === 100, 'JPY zero-decimal conversion');
$assert(Support::amountToMinor('1.234', 'KWD') === 1234, 'KWD three-decimal conversion');
$assert(Support::minorToMajorString(1799, 'GBP') === '17.99', 'GBP major-unit conversion');
$assert(Support::minorToMajorString(100, 'JPY') === '100', 'JPY major-unit conversion');

$threw = false;
try {
    Support::amountToMinor('17.991', 'GBP');
} catch (RuntimeException) {
    $threw = true;
}
$assert($threw, 'reject unsupported monetary precision');

$token = Support::encodeGatewayToken('customer-1', 'method-1', 'card');
$decoded = Support::decodeGatewayToken($token);
$assert($decoded['customer_id'] === 'customer-1', 'gateway token customer round-trip');
$assert($decoded['payment_method_id'] === 'method-1', 'gateway token method round-trip');
$assert($decoded['type'] === 'card', 'gateway token type round-trip');

$state = Support::encodeState(['v' => 1, 'invoice' => 123]);
$sig = Support::signState($state, 'secret-key');
$assert(Support::verifyState($state, $sig, 'secret-key'), 'state signature verification');
$assert(!Support::verifyState($state . 'x', $sig, 'secret-key'), 'tampered state rejection');

$assert(Support::revolutLocale('Romanian') === 'ro', 'Romanian locale mapping');
$assert(Support::revolutLocale('en-US') === 'en', 'language tag locale mapping');
$assert(Support::revolutLocale('unknown') === 'auto', 'unknown locale fallback');

$payment = [
    'state' => 'captured',
    'fees' => [
        ['type' => 'acquiring', 'amount' => 23, 'currency' => 'GBP'],
        ['type' => 'other', 'amount' => 7, 'currency' => 'GBP'],
        ['type' => 'foreign', 'amount' => 99, 'currency' => 'EUR'],
    ],
];
$assert(Support::paymentFeeMinor($payment, 'GBP') === 30, 'same-currency payment fee sum');
$assert(Support::orderFeeMinor(['payments' => [$payment, ['state' => 'declined', 'fees' => [['amount' => 9, 'currency' => 'GBP']]]]], 'GBP') === 30, 'captured-only order fee sum');

$timestamp = (string)((int)round(microtime(true) * 1000));
$body = '{"event":"ORDER_COMPLETED","order_id":"test"}';
$secret = 'webhook-secret';
$signature = 'v1=' . hash_hmac('sha256', 'v1.' . $timestamp . '.' . $body, $secret);
$assert(Support::verifyWebhook($body, $timestamp, $signature, $secret), 'webhook signature verification');
$assert(!Support::verifyWebhook($body . 'x', $timestamp, $signature, $secret), 'webhook tamper rejection');

$assert(
    Support::safeReturnUrl('https://billing.example.com/viewinvoice.php?id=1', 'https://billing.example.com') === 'https://billing.example.com/viewinvoice.php?id=1',
    'same-host return URL accepted'
);
$assert(
    Support::safeReturnUrl('https://evil.example/x', 'https://billing.example.com') === 'https://billing.example.com/clientarea.php',
    'cross-host return URL rejected'
);

echo "OK - {$tests} self-tests passed\n";
