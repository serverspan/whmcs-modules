<?php

declare(strict_types=1);

namespace ServerSpan\WHMCS\ColeteOnline;

final class Controller
{
    private OrderRepository $orders;
    private ShipmentRepository $shipments;

    public function __construct(private readonly array $vars)
    {
        $this->orders = new OrderRepository();
        $this->shipments = new ShipmentRepository();
    }

    public function dispatch(): string
    {
        $action = (string) ($_GET['action'] ?? 'dashboard');
        return match ($action) {
            'prepare' => $this->prepare(),
            'shipment' => $this->shipment(),
            'connection' => $this->diagnostics(),
            default => $this->dashboard(),
        };
    }

    private function dashboard(): string
    {
        return Renderer::dashboard([
            'modulelink' => $this->vars['modulelink'],
            'orders' => $this->orders->recent((int) ($this->vars['recentOrders'] ?? 50)),
            'shipments' => $this->shipments->recent(50),
            'connection' => $this->connectionSummary(),
        ]);
    }

    private function prepare(): string
    {
        $orderId = filter_var($_GET['order'] ?? $_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$orderId) {
            return Renderer::error('A valid WHMCS order ID is required.', (string) $this->vars['modulelink']);
        }
        $order = $this->orders->find((int) $orderId);
        if (!$order) {
            return Renderer::error('WHMCS order not found.', (string) $this->vars['modulelink']);
        }

        $client = $this->api();
        $addresses = [];
        $addressError = '';
        try {
            $addresses = self::normalizeAddresses($client->addresses());
        } catch (\Throwable $e) {
            $addressError = $e->getMessage();
        }

        $values = $this->defaultValues($order);
        $offers = [];
        $notice = $addressError !== '' ? 'Could not load saved sender addresses: ' . $addressError : '';
        $noticeType = $addressError !== '' ? 'warning' : 'info';
        $existing = $this->shipments->forOrder((int) $orderId);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Support::checkCsrf();
            $values = array_replace($values, $_POST);
            $do = (string) ($_POST['do'] ?? '');
            try {
                $senderId = (int) ($values['sender_address_id'] ?? 0);
                if ($do === 'quote') {
                    $payload = PayloadBuilder::fromForm($values, $order, $senderId, 'bestPrice');
                    $response = $client->quote($payload);
                    $offers = PayloadBuilder::normalizeOffers($response);
                    if (!$offers) {
                        throw new \RuntimeException('Colete-Online returned no courier offers for this shipment.');
                    }
                    $notice = count($offers) . ' live courier offer(s) received.';
                    $noticeType = 'success';
                } elseif ($do === 'create') {
                    if ($existing && !Support::isOn($values['confirm_additional'] ?? null)) {
                        throw new \InvalidArgumentException('This WHMCS order already has a shipment. Tick the additional-shipment confirmation to create another one.');
                    }
                    $serviceId = trim((string) ($values['service_id'] ?? ''));
                    $payload = PayloadBuilder::fromForm($values, $order, $senderId, 'directId', $serviceId);
                    $response = $client->createOrder($payload);
                    $shipmentId = $this->shipments->createFromApi(
                        (int) $order['id'],
                        (int) $order['userid'],
                        (int) ($order['invoiceid'] ?? 0) ?: null,
                        $response
                    );
                    $url = (string) $this->vars['modulelink'] . '&action=shipment&id=' . $shipmentId . '&created=1';
                    if (!headers_sent()) {
                        header('Location: ' . $url, true, 303);
                        exit;
                    }
                    return '<script>window.location.href=' . json_encode($url) . ';</script><p>Shipment created. <a href="' . Support::e($url) . '">Continue</a>.</p>';
                }
            } catch (\Throwable $e) {
                $notice = $e->getMessage();
                $noticeType = 'danger';
                if (($values['service_id'] ?? '') !== '') {
                    try {
                        $quotePayload = PayloadBuilder::fromForm($values, $order, (int) ($values['sender_address_id'] ?? 0), 'bestPrice');
                        $offers = PayloadBuilder::normalizeOffers($client->quote($quotePayload));
                    } catch (\Throwable) {
                        // Keep the original error and form values.
                    }
                }
            }
        }

        return Renderer::prepare([
            'modulelink' => $this->vars['modulelink'],
            'order' => $order,
            'values' => $values,
            'addresses' => $addresses,
            'offers' => $offers,
            'existing' => $existing,
            'notice' => $notice,
            'notice_type' => $noticeType,
        ]);
    }

    private function shipment(): string
    {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            return Renderer::error('A valid shipment ID is required.', (string) $this->vars['modulelink']);
        }
        $shipment = $this->shipments->find((int) $id);
        if (!$shipment) {
            return Renderer::error('Shipment not found.', (string) $this->vars['modulelink']);
        }

        $notice = isset($_GET['created']) ? 'Shipment and AWB created successfully.' : '';
        $noticeType = 'success';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'refresh') {
            Support::checkCsrf();
            try {
                $response = $this->api()->status((string) $shipment['unique_id']);
                $this->shipments->updateStatus((int) $shipment['id'], $response);
                $shipment = $this->shipments->find((int) $id) ?? $shipment;
                $notice = 'Tracking refreshed from Colete-Online.';
            } catch (\Throwable $e) {
                $notice = $e->getMessage();
                $noticeType = 'danger';
            }
        }

        $tracking = [];
        if (!empty($shipment['tracking_json'])) {
            $decoded = json_decode((string) $shipment['tracking_json'], true);
            if (is_array($decoded)) {
                $tracking = StatusParser::history($decoded);
            }
        }
        return Renderer::shipment([
            'modulelink' => $this->vars['modulelink'],
            'shipment' => $shipment,
            'awb_url' => Support::systemUrl() . '/modules/addons/serverspancoleteonline/awb.php?id=' . (int) $shipment['id'],
            'history' => $tracking,
            'notice' => $notice,
            'notice_type' => $noticeType,
        ]);
    }

    private function diagnostics(): string
    {
        $data = $this->connectionSummary(true);
        $data['modulelink'] = $this->vars['modulelink'];
        $data['environment'] = Support::isOn($this->vars['staging'] ?? null) ? 'Staging' : 'Production';
        return Renderer::diagnostics($data);
    }

    private function connectionSummary(bool $full = false): array
    {
        try {
            $api = $this->api();
            $balance = $api->balance();
            $data = [
                'ok' => true,
                'message' => '',
                'balance_text' => self::balanceText($balance),
                'address_count' => 0,
                'service_count' => 0,
            ];
            if ($full) {
                $data['address_count'] = count(self::normalizeAddresses($api->addresses()));
                $data['service_count'] = self::responseCount($api->services());
            }
            return $data;
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'balance_text' => '',
                'address_count' => 0,
                'service_count' => 0,
            ];
        }
    }

    private function api(): ApiClient
    {
        return new ApiClient(
            trim((string) ($this->vars['clientId'] ?? '')),
            (string) ($this->vars['clientSecret'] ?? ''),
            Support::isOn($this->vars['staging'] ?? null),
            new CacheRepository(),
            Support::isOn($this->vars['apiDebug'] ?? null)
        );
    }

    private function defaultValues(array $order): array
    {
        return [
            'sender_address_id' => (string) ($this->vars['defaultSenderAddressId'] ?? ''),
            'sender_shipping_point_id' => '',
            'recipient_name' => $order['recipient_name'],
            'company' => $order['companyname'] ?? '',
            'phone' => $order['phonenumber'] ?? '',
            'phone2' => '',
            'email' => $order['email'] ?? '',
            'country' => $order['country'] ?? 'RO',
            'city' => $order['city'] ?? '',
            'county' => $order['state'] ?? '',
            'street' => $order['street'] ?? '',
            'number' => $order['number'] ?? '',
            'postal_code' => $order['postcode'] ?? '',
            'additional_info' => $order['address2'] ?? '',
            'recipient_shipping_point_id' => '',
            'validation_strategy' => $this->vars['validationStrategy'] ?? 'minimal',
            'package_type' => (string) ($this->vars['defaultPackageType'] ?? '2'),
            'content' => $this->vars['defaultContent'] ?? 'Products',
            'weight' => [(string) ($this->vars['defaultWeight'] ?? '1')],
            'height' => [(string) ($this->vars['defaultHeight'] ?? '10')],
            'width' => [(string) ($this->vars['defaultWidth'] ?? '10')],
            'length' => [(string) ($this->vars['defaultLength'] ?? '10')],
            'client_reference' => 'WHMCS-' . ($order['ordernum'] ?? $order['id']),
            'insurance_amount' => '0',
            'declared_value' => '0',
            'account_repayment_amount' => '0',
            'cash_repayment_amount' => '0',
            'account_holder_name' => '',
            'bank_account' => '',
            'pickup_date' => '',
            'pickup_from' => '',
            'pickup_to' => '',
        ];
    }

    public static function normalizeAddresses(array $response): array
    {
        $list = $response['data'] ?? $response['list'] ?? $response;
        if (!is_array($list)) {
            return [];
        }
        $result = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $item['addressId'] ?? $item['locationId'] ?? $item['id'] ?? null;
            if ($id === null || (string) $id === '') {
                continue;
            }
            $address = is_array($item['address'] ?? null) ? $item['address'] : [];
            $contact = is_array($item['contact'] ?? null) ? $item['contact'] : [];
            $bits = array_filter([
                $item['shortName'] ?? null,
                $contact['name'] ?? null,
                trim((string) ($address['street'] ?? '') . ' ' . (string) ($address['number'] ?? '')),
                $address['city'] ?? null,
                $address['county'] ?? null,
            ], static fn($v): bool => $v !== null && trim((string) $v) !== '');
            $result[] = ['id' => (string) $id, 'label' => implode(' - ', array_map('strval', $bits)) ?: ('Address ' . $id)];
        }
        return $result;
    }

    public static function balanceText(array $response): string
    {
        foreach (['balance', 'availableBalance', 'amount', 'value'] as $key) {
            if (isset($response[$key]) && is_scalar($response[$key])) {
                $currency = isset($response['currency']) && is_scalar($response['currency']) ? ' ' . $response['currency'] : '';
                return (string) $response[$key] . $currency;
            }
        }
        if (isset($response['data']) && is_array($response['data'])) {
            return self::balanceText($response['data']);
        }
        return '';
    }

    private static function responseCount(array $response): int
    {
        foreach (['data', 'list', 'items', 'services'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return count($response[$key]);
            }
        }
        return array_is_list($response) ? count($response) : 0;
    }
}
