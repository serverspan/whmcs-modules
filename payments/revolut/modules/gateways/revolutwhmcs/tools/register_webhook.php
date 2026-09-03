<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__, 4);
require_once $root . '/init.php';
require_once dirname(__DIR__) . '/lib/RevolutGateway.php';

use RevolutWhmcs\AppHelpers;
use RevolutWhmcs\RevolutClient;

try {
    $params = AppHelpers::gatewayParams();
    $callbackUrl = rtrim((string)$params['systemurl'], '/') . '/modules/gateways/callback/revolutwhmcs.php';
    $client = RevolutClient::fromGatewayParams($params);
    $result = $client->createWebhook($callbackUrl, ['ORDER_COMPLETED']);

    echo "Webhook created.\n";
    echo "URL: " . $callbackUrl . "\n";
    echo "ID: " . ($result['id'] ?? '(unknown)') . "\n";
    echo "Signing secret: " . ($result['signing_secret'] ?? '(not returned)') . "\n\n";
    echo "Copy the signing secret into WHMCS > System Settings > Payment Gateways > Revolut Gateway > Webhook Signing Secret.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
