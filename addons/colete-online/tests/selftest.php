<?php

declare(strict_types=1);

$lib = __DIR__ . '/../modules/addons/serverspancoleteonline/lib/';
foreach (['ApiException','Support','CacheRepository','ApiClient','ShipmentRepository','StatusParser','OrderRepository','PayloadBuilder','Renderer','Controller'] as $file) {
    require_once $lib . $file . '.php';
}

use ServerSpan\WHMCS\ColeteOnline\ApiClient;
use ServerSpan\WHMCS\ColeteOnline\Controller;
use ServerSpan\WHMCS\ColeteOnline\OrderRepository;
use ServerSpan\WHMCS\ColeteOnline\PayloadBuilder;
use ServerSpan\WHMCS\ColeteOnline\ShipmentRepository;
use ServerSpan\WHMCS\ColeteOnline\StatusParser;
use ServerSpan\WHMCS\ColeteOnline\Support;

$tests = 0;
$passed = 0;

function check(bool $condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        return;
    }
    $passed++;
    echo "PASS: {$label}\n";
}

function throws(callable $fn, string $needle): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), $needle);
    }
    return false;
}

$order = ['id' => 12, 'ordernum' => '1234567890', 'currency_code' => 'EUR'];
$base = [
    'recipient_name' => 'Jane Doe', 'phone' => '0712345678', 'email' => 'jane@example.test',
    'country' => 'RO', 'city' => 'Bucuresti', 'county' => 'B', 'street' => 'Calea Victoriei', 'number' => '10',
    'postal_code' => '010000', 'validation_strategy' => 'minimal',
    'package_type' => '2', 'content' => 'Hardware',
    'weight' => ['2.5'], 'height' => ['10'], 'width' => ['20'], 'length' => ['30'],
    'client_reference' => 'WHMCS-1234567890',
];

check(Support::isOn('on'), 'yes/no helper accepts on');
check(Support::isOn('TRUE'), 'yes/no helper accepts true case-insensitively');
check(!Support::isOn('off'), 'yes/no helper rejects off');
check(abs(Support::toFloat('2,50') - 2.5) < 0.0001, 'numeric helper accepts comma decimal');
check(Support::e('<x>') === '&lt;x&gt;', 'HTML escaping');
$GLOBALS['CONFIG']['SystemURL'] = 'https://billing.example.test/';
check(Support::systemUrl() === 'https://billing.example.test', 'SystemURL normalization for AWB bridge');

check(OrderRepository::splitStreetAndNumber('Strada Lunga 45') === ['Strada Lunga', '45'], 'street number split');
check(OrderRepository::splitStreetAndNumber('Strada Lunga, nr. 45A') === ['Strada Lunga', '45A'], 'street Romanian nr split');
check(OrderRepository::splitStreetAndNumber('No number street') === ['No number street', ''], 'street without number preserved');

$recipient = PayloadBuilder::recipient($base);
check($recipient['contact']['name'] === 'Jane Doe', 'recipient contact name');
check($recipient['address']['countryCode'] === 'RO', 'recipient country');
check($recipient['validationStrategy'] === 'minimal', 'recipient validation strategy');
$lockerInput = $base + ['recipient_shipping_point_id' => '991'];
$locker = PayloadBuilder::recipient($lockerInput);
check(($locker['shippingPoint']['id'] ?? '') === '991', 'recipient shipping-point payload');
check(throws(fn() => PayloadBuilder::recipient(array_replace($base, ['phone' => ''])), 'required'), 'recipient required fields enforced');
check(throws(fn() => PayloadBuilder::recipient(array_replace($base, ['country' => 'ROU'])), '2-letter'), 'ISO2 country enforced');

$packages = PayloadBuilder::packages($base);
check($packages['type'] === 2, 'box package type');
check(count($packages['list']) === 1, 'single package generated');
check(abs($packages['list'][0]['weight'] - 2.5) < 0.0001, 'package weight parsed');
$multi = array_replace($base, ['weight' => ['1','2'], 'height' => ['5','6'], 'width' => ['7','8'], 'length' => ['9','10']]);
check(count(PayloadBuilder::packages($multi)['list']) === 2, 'multi-package payload');
check(throws(fn() => PayloadBuilder::packages(array_replace($base, ['weight' => ['0']])), 'At least one'), 'zero package weight rejected');
check(throws(fn() => PayloadBuilder::packages(array_replace($base, ['height' => ['0']])), 'positive'), 'box dimensions enforced');
$envelope = PayloadBuilder::packages(array_replace($base, ['package_type' => '1', 'height' => ['0'], 'width' => ['0'], 'length' => ['0']]));
check($envelope['list'][0]['weight'] === 2.5, 'envelope can omit dimensions');

$payload = PayloadBuilder::fromForm($base, $order, 77, 'bestPrice');
check($payload['sender']['addressId'] === 77, 'saved sender address ID');
check($payload['service']['selectionType'] === 'bestPrice', 'quote uses bestPrice');
check(in_array(['id' => 10, 'baseCurrency' => 'EUR', 'priceCurrency' => 'RON'], $payload['extraOptions'], true), 'non-RON base currency option');
$direct = PayloadBuilder::fromForm($base, $order, 77, 'directId', 'svc-4');
check($direct['service']['serviceIds'] === ['svc-4'], 'create uses direct service id');
check(throws(fn() => PayloadBuilder::fromForm($base, $order, 0), 'valid saved sender'), 'sender ID required');
check(throws(fn() => PayloadBuilder::fromForm($base, $order, 77, 'directId', ''), 'Select a courier'), 'direct service required');

$extras = PayloadBuilder::extraOptions(array_replace($base, [
    'open_at_delivery' => '1', 'saturday_delivery' => '1', 'saturday_mandatory' => '1',
    'insurance_amount' => '100.5', 'client_reference' => 'ABC',
]), ['ordernum' => '99', 'currency_code' => 'RON']);
check(in_array(['id' => 2], $extras, true), 'open-at-delivery option');
check(in_array(['id' => 3, 'isMandatory' => true], $extras, true), 'Saturday option');
check(in_array(['id' => 4, 'amount' => 100.5], $extras, true), 'insurance option');
check(in_array(['id' => 9, 'clientReference' => 'ABC'], $extras, true), 'client reference option');
check(throws(fn() => PayloadBuilder::extraOptions(['insurance_amount'=>'1','declared_value'=>'2'], $order), 'cannot be combined'), 'declared value conflict enforced');
check(throws(fn() => PayloadBuilder::extraOptions(['pickup_date'=>'2026/09/05'], $order), 'YYYY-MM-DD'), 'pickup date validation');
check(throws(fn() => PayloadBuilder::extraOptions(['pickup_from'=>'29:00'], $order), 'HH:MM'), 'pickup time validation');

$offers = PayloadBuilder::normalizeOffers(['list' => [[
    'service' => ['id' => 6, 'courierName' => 'Courier X', 'name' => 'Express'],
    'price' => ['total' => 23.8, 'noVat' => 20],
]]]);
check(count($offers) === 1, 'offer list normalized');
check($offers[0]['id'] === '6', 'offer service ID string normalized');
check($offers[0]['courier'] === 'Courier X', 'offer courier name');
check(abs($offers[0]['total'] - 23.8) < 0.0001, 'offer price');
check(count(PayloadBuilder::normalizeOffers(['data' => ['list' => [[
    'service' => ['id' => 9, 'name' => 'Wrapped'], 'price' => ['total' => 5]
]]]])) === 1, 'wrapped offer response supported');

$oldCreate = ShipmentRepository::parseCreateResponse([
    'uniqueId' => 'U1', 'awb' => 'AWB1', 'estimatedPickupDate' => '2026-09-05',
    'curierService' => ['service' => ['id' => 6, 'courierName' => 'TNT', 'name' => 'Express'], 'price' => ['total' => 23.8, 'noVat' => 20]],
]);
check($oldCreate['unique_id'] === 'U1', 'old create response unique ID');
check($oldCreate['courier_name'] === 'TNT', 'old create response courier');
check($oldCreate['price_total'] === 23.8, 'old create response price');
$currentCreate = ShipmentRepository::parseCreateResponse([
    'uniqueId' => 'U2', 'awb' => 'AWB2', 'estimatedPickUpDate' => '2026-09-06',
    'service' => ['id' => '8', 'courierName' => 'Courier Y', 'displayName' => 'Locker'],
]);
check($currentCreate['service_id'] === '8', 'current create response service');
check($currentCreate['service_name'] === 'Locker', 'current create displayName');
check($currentCreate['estimated_pickup_date'] === '2026-09-06', 'current estimatedPickUpDate spelling');
$wrappedCreate = ShipmentRepository::parseCreateResponse(['data' => ['uniqueId' => 'U3', 'awb' => 'AWB3', 'service' => ['id' => 10]]]);
check($wrappedCreate['unique_id'] === 'U3', 'wrapped create response supported');

$status = [
    'summary' => ['uniqueId' => 'U1', 'awb' => 'AWB1'],
    'history' => [
        ['dateTime' => '2026-09-04 10:00:00', 'code' => 'A', 'statusTextParts' => ['ro' => ['name' => 'Preluata']], 'comment' => ['ro' => 'Bucuresti']],
        ['dateTime' => '2026-09-05 11:00:00', 'code' => 'B', 'statusTextParts' => ['ro' => ['name' => 'In tranzit']], 'comment' => ['ro' => 'Brasov']],
    ],
];
$history = StatusParser::history($status);
check(count($history) === 2, 'tracking history parsed');
check($history[0]['code'] === 'B', 'tracking sorted newest first');
check($history[0]['text'] === 'In tranzit', 'Romanian status text extracted');
check(StatusParser::latest($status)['comment'] === 'Brasov', 'latest status comment');
check(count(StatusParser::history(['data' => $status])) === 2, 'wrapped status response supported');

$addresses = Controller::normalizeAddresses(['data' => [[
    'locationId' => 42, 'shortName' => 'HQ', 'contact' => ['name' => 'Sender'],
    'address' => ['street' => 'Str. A', 'number' => '1', 'city' => 'Bucuresti', 'county' => 'B'],
]]]);
check($addresses[0]['id'] === '42', 'saved sender locationId normalized');
check(str_contains($addresses[0]['label'], 'HQ'), 'saved sender label includes short name');
check(Controller::balanceText(['balance' => 123.45, 'currency' => 'RON']) === '123.45 RON', 'balance response normalization');
check(Controller::balanceText(['data' => ['amount' => '99']]) === '99', 'nested balance normalization');

check(ApiClient::errorMessage(['message' => 'Bad input'], 400) === 'Colete-Online API error (HTTP 400): Bad input', 'API error message extraction');
check(ApiClient::errorMessage([], 500) === 'Colete-Online API request failed (HTTP 500).', 'generic API error message');

printf("\n%d/%d tests passed.\n", $passed, $tests);
exit($passed === $tests ? 0 : 1);
