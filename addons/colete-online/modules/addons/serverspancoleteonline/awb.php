<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../init.php';

foreach (['ApiException','Support','TokenCodec','CacheRepository','ApiClient','ShipmentRepository'] as $file) {
    require_once __DIR__ . '/lib/' . $file . '.php';
}

use ServerSpan\WHMCS\ColeteOnline\ApiClient;
use ServerSpan\WHMCS\ColeteOnline\CacheRepository;
use ServerSpan\WHMCS\ColeteOnline\ShipmentRepository;
use ServerSpan\WHMCS\ColeteOnline\Support;

if (!Support::currentAdminHasModuleAccess('serverspancoleteonline')) {
    http_response_code(403);
    exit('Forbidden');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(400);
    exit('Invalid shipment ID');
}

$shipment = (new ShipmentRepository())->find((int) $id);
if (!$shipment) {
    http_response_code(404);
    exit('Shipment not found');
}

try {
    $clientId = trim((string) Support::setting('serverspancoleteonline', 'clientId'));
    $clientSecret = (string) Support::setting('serverspancoleteonline', 'clientSecret');
    $staging = Support::isOn(Support::setting('serverspancoleteonline', 'staging'));
    $debug = Support::isOn(Support::setting('serverspancoleteonline', 'apiDebug'));
    $api = new ApiClient($clientId, $clientSecret, $staging, new CacheRepository(), $debug);
    $file = $api->awb((string) $shipment['unique_id']);

    $contentType = trim((string) ($file['content_type'] ?? 'application/pdf'));
    if ($contentType === '' || str_contains(strtolower($contentType), 'json')) {
        $contentType = 'application/pdf';
    }
    $nameBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($shipment['awb'] ?: $shipment['unique_id'])) ?: 'awb';
    $extension = str_contains(strtolower($contentType), 'pdf') ? '.pdf' : '.bin';
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $nameBase . $extension . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    echo $file['body'];
} catch (Throwable $e) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unable to download AWB from Colete-Online: ' . $e->getMessage();
}
