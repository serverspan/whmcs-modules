<?php

declare(strict_types=1);

namespace ServerSpan\WHMCS\ColeteOnline;

use InvalidArgumentException;

final class PayloadBuilder
{
    public static function fromForm(array $input, array $order, int $senderAddressId, string $selectionType = 'bestPrice', ?string $serviceId = null): array
    {
        if ($senderAddressId < 1) {
            throw new InvalidArgumentException('Select a valid saved sender address from Colete-Online.');
        }

        $recipient = self::recipient($input);
        $sender = ['addressId' => $senderAddressId];
        $senderPoint = trim((string) ($input['sender_shipping_point_id'] ?? ''));
        if ($senderPoint !== '') {
            $sender['shippingPoint'] = ['id' => $senderPoint, 'countryCode' => 'RO'];
        }

        $service = ['selectionType' => $selectionType];
        if ($selectionType === 'directId') {
            if ($serviceId === null || trim($serviceId) === '') {
                throw new InvalidArgumentException('Select a courier service before creating the shipment.');
            }
            $service['serviceIds'] = [trim($serviceId)];
        }

        return [
            'sender' => $sender,
            'recipient' => $recipient,
            'packages' => self::packages($input),
            'service' => $service,
            'extraOptions' => self::extraOptions($input, $order),
        ];
    }

    public static function recipient(array $input): array
    {
        $name = trim((string) ($input['recipient_name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $country = strtoupper(trim((string) ($input['country'] ?? 'RO')));
        $city = trim((string) ($input['city'] ?? ''));
        $county = trim((string) ($input['county'] ?? ''));
        $street = trim((string) ($input['street'] ?? ''));
        $number = trim((string) ($input['number'] ?? ''));
        $postal = trim((string) ($input['postal_code'] ?? ''));

        if ($name === '' || $phone === '' || $country === '' || $city === '' || $county === '') {
            throw new InvalidArgumentException('Recipient name, phone, country, city and county are required.');
        }
        if (strlen($country) !== 2) {
            throw new InvalidArgumentException('Recipient country must be a 2-letter ISO country code.');
        }

        $contact = array_filter([
            'name' => $name,
            'phone' => $phone,
            'phone2' => trim((string) ($input['phone2'] ?? '')),
            'email' => $email,
            'company' => trim((string) ($input['company'] ?? '')),
        ], static fn($v): bool => $v !== '');

        $address = array_filter([
            'countryCode' => $country,
            'postalCode' => $postal,
            'city' => $city,
            'county' => $county,
            'street' => $street,
            'number' => $number,
            'additionalInfo' => trim((string) ($input['additional_info'] ?? '')),
        ], static fn($v): bool => $v !== '');

        $recipient = [
            'contact' => $contact,
            'address' => $address,
            'validationStrategy' => in_array(($input['validation_strategy'] ?? ''), ['minimal', 'priceMinimal'], true)
                ? (string) $input['validation_strategy']
                : 'minimal',
        ];

        $shippingPoint = trim((string) ($input['recipient_shipping_point_id'] ?? ''));
        if ($shippingPoint !== '') {
            $recipient['shippingPoint'] = ['id' => $shippingPoint, 'countryCode' => $country];
        }
        return $recipient;
    }

    public static function packages(array $input): array
    {
        $type = (int) ($input['package_type'] ?? 2);
        if (!in_array($type, [1, 2], true)) {
            $type = 2;
        }
        $content = trim((string) ($input['content'] ?? 'Products'));
        if ($content === '') {
            $content = 'Products';
        }

        $weights = is_array($input['weight'] ?? null) ? $input['weight'] : [$input['weight'] ?? 1];
        $heights = is_array($input['height'] ?? null) ? $input['height'] : [$input['height'] ?? 1];
        $widths = is_array($input['width'] ?? null) ? $input['width'] : [$input['width'] ?? 1];
        $lengths = is_array($input['length'] ?? null) ? $input['length'] : [$input['length'] ?? 1];
        $count = max(count($weights), count($heights), count($widths), count($lengths));
        $list = [];
        for ($i = 0; $i < $count; $i++) {
            $weight = Support::toFloat($weights[$i] ?? 0);
            $height = Support::toFloat($heights[$i] ?? 0);
            $width = Support::toFloat($widths[$i] ?? 0);
            $length = Support::toFloat($lengths[$i] ?? 0);
            if ($weight <= 0) {
                continue;
            }
            if ($type === 2 && ($height <= 0 || $width <= 0 || $length <= 0)) {
                throw new InvalidArgumentException('Every parcel must have positive weight, height, width and length.');
            }
            $item = ['weight' => $weight];
            if ($height > 0) { $item['height'] = $height; }
            if ($width > 0) { $item['width'] = $width; }
            if ($length > 0) { $item['length'] = $length; }
            $list[] = $item;
        }
        if (!$list) {
            throw new InvalidArgumentException('At least one package with a positive weight is required.');
        }

        return ['type' => $type, 'content' => $content, 'list' => $list];
    }

    public static function extraOptions(array $input, array $order): array
    {
        $options = [];
        if (Support::isOn($input['open_at_delivery'] ?? null)) {
            $options[] = ['id' => 2];
        }
        if (Support::isOn($input['saturday_delivery'] ?? null)) {
            $options[] = ['id' => 3, 'isMandatory' => Support::isOn($input['saturday_mandatory'] ?? null)];
        }

        $insurance = Support::toFloat($input['insurance_amount'] ?? 0);
        $accountRepayment = Support::toFloat($input['account_repayment_amount'] ?? 0);
        $cashRepayment = Support::toFloat($input['cash_repayment_amount'] ?? 0);
        $declared = Support::toFloat($input['declared_value'] ?? 0);
        if ($insurance > 0) {
            $options[] = ['id' => 4, 'amount' => $insurance];
        }
        if ($accountRepayment > 0) {
            $option = ['id' => 5, 'amount' => $accountRepayment];
            $holder = trim((string) ($input['account_holder_name'] ?? ''));
            $iban = trim((string) ($input['bank_account'] ?? ''));
            if ($holder !== '') { $option['accountHolderName'] = $holder; }
            if ($iban !== '') { $option['bankAccount'] = $iban; }
            $options[] = $option;
        }
        if ($cashRepayment > 0) {
            $options[] = ['id' => 6, 'amount' => $cashRepayment];
        }
        if ($declared > 0) {
            if ($insurance > 0 || $accountRepayment > 0 || $cashRepayment > 0) {
                throw new InvalidArgumentException('Declared value cannot be combined with insurance or repayment/COD options in the Colete-Online API.');
            }
            $options[] = ['id' => 7, 'amount' => $declared];
        }

        $pickupDate = trim((string) ($input['pickup_date'] ?? ''));
        $pickupFrom = trim((string) ($input['pickup_from'] ?? ''));
        $pickupTo = trim((string) ($input['pickup_to'] ?? ''));
        if ($pickupDate !== '' || $pickupFrom !== '' || $pickupTo !== '') {
            $pickup = ['id' => 8];
            if ($pickupDate !== '') { $pickup['date'] = self::date($pickupDate); }
            if ($pickupFrom !== '') { $pickup['fromTime'] = self::time($pickupFrom); }
            if ($pickupTo !== '') { $pickup['toTime'] = self::time($pickupTo); }
            $options[] = $pickup;
        }

        $reference = trim((string) ($input['client_reference'] ?? ('WHMCS-' . ($order['ordernum'] ?? $order['id'] ?? ''))));
        if ($reference !== '') {
            $options[] = ['id' => 9, 'clientReference' => (function_exists('mb_substr') ? mb_substr($reference, 0, 50) : substr($reference, 0, 50))];
        }

        $currency = strtoupper((string) ($order['currency_code'] ?? 'RON'));
        if ($currency !== 'RON' && strlen($currency) === 3) {
            $options[] = ['id' => 10, 'baseCurrency' => $currency, 'priceCurrency' => 'RON'];
        }
        return $options;
    }

    public static function normalizeOffers(array $response): array
    {
        if (!isset($response['list']) && isset($response['data']) && is_array($response['data'])) {
            $response = $response['data'];
        }
        $list = $response['list'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        $offers = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $service = is_array($item['service'] ?? null) ? $item['service'] : [];
            $price = is_array($item['price'] ?? null) ? $item['price'] : [];
            $id = (string) ($service['id'] ?? $service['activationId'] ?? '');
            if ($id === '') {
                continue;
            }
            $offers[] = [
                'id' => $id,
                'activation_id' => (string) ($service['activationId'] ?? ''),
                'courier' => (string) ($service['courierName'] ?? $service['courier'] ?? 'Courier'),
                'name' => (string) ($service['displayName'] ?? $service['name'] ?? ('Service ' . $id)),
                'total' => is_numeric($price['total'] ?? null) ? (float) $price['total'] : null,
                'no_vat' => is_numeric($price['noVat'] ?? null) ? (float) $price['noVat'] : null,
                'shipping_point' => $service['shippingPoint'] ?? null,
                'fixed_locations_from' => $service['fixedLocationsFrom'] ?? null,
            ];
        }
        return $offers;
    }

    private static function date(string $value): string
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$dt || $dt->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Pickup date must use YYYY-MM-DD.');
        }
        return $value;
    }

    private static function time(string $value): string
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw new InvalidArgumentException('Pickup time must use HH:MM.');
        }
        return $value;
    }
}
