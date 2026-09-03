<?php

declare(strict_types=1);

namespace RevolutWhmcs;

use RuntimeException;
use WHMCS\Database\Capsule;

final class ApiException extends RuntimeException
{
    private int $statusCode;
    private array $responseData;

    public function __construct(string $message, int $statusCode = 0, array $responseData = [])
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->responseData = $responseData;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseData(): array
    {
        return $this->responseData;
    }
}

final class RevolutClient
{
    private string $secretKey;
    private string $baseUrl;
    private string $apiVersion;
    private int $timeout;

    public function __construct(string $secretKey, bool $sandbox, string $apiVersion = '2026-04-20', int $timeout = 30)
    {
        $this->secretKey = trim($secretKey);
        $this->baseUrl = $sandbox
            ? 'https://sandbox-merchant.revolut.com'
            : 'https://merchant.revolut.com';
        $this->apiVersion = $apiVersion ?: '2026-04-20';
        $this->timeout = max(5, $timeout);

        if ($this->secretKey === '') {
            throw new RuntimeException('Revolut Secret API key is not configured.');
        }
    }

    public static function fromGatewayParams(array $params): self
    {
        return new self(
            (string)($params['secretApiKey'] ?? ''),
            Support::isTruthy($params['sandbox'] ?? false),
            (string)($params['apiVersion'] ?? '2026-04-20'),
            (int)($params['apiTimeout'] ?? 30)
        );
    }

    public function createOrder(array $payload, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/api/orders', $payload, $idempotencyKey);
    }

    public function getOrder(string $orderId): array
    {
        return $this->request('GET', '/api/orders/' . rawurlencode($orderId));
    }

    public function payOrderWithSavedMethod(string $orderId, string $paymentMethodId, string $type): array
    {
        return $this->request('POST', '/api/orders/' . rawurlencode($orderId) . '/payments', [
            'saved_payment_method' => [
                'type' => $type,
                'id' => $paymentMethodId,
                'initiator' => 'merchant',
            ],
        ]);
    }

    public function refundOrder(string $orderId, int $amountMinor, string $currency, string $idempotencyKey): array
    {
        return $this->request('POST', '/api/orders/' . rawurlencode($orderId) . '/refund', [
            'amount' => $amountMinor,
            'currency' => strtoupper($currency),
        ], $idempotencyKey);
    }

    public function listCustomerPaymentMethods(string $customerId, bool $merchantOnly = true): array
    {
        $path = '/api/customers/' . rawurlencode($customerId) . '/payment-methods';
        if ($merchantOnly) {
            $path .= '?only_merchant=true';
        }

        return $this->request('GET', $path);
    }

    public function getCustomerPaymentMethod(string $customerId, string $paymentMethodId): array
    {
        return $this->request(
            'GET',
            '/api/customers/' . rawurlencode($customerId) . '/payment-methods/' . rawurlencode($paymentMethodId)
        );
    }

    public function deleteCustomerPaymentMethod(string $customerId, string $paymentMethodId): void
    {
        $this->request(
            'DELETE',
            '/api/customers/' . rawurlencode($customerId) . '/payment-methods/' . rawurlencode($paymentMethodId),
            null,
            null,
            [204]
        );
    }

    public function createWebhook(string $url, array $events = ['ORDER_COMPLETED']): array
    {
        return $this->request('POST', '/api/webhooks', [
            'url' => $url,
            'events' => array_values($events),
        ]);
    }

    private function request(
        string $method,
        string $path,
        ?array $payload = null,
        ?string $idempotencyKey = null,
        array $extraSuccessCodes = []
    ): array {
        $url = $this->baseUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Accept: application/json',
            'Revolut-Api-Version: ' . $this->apiVersion,
            'User-Agent: WHMCS-Revolut-Gateway/1.0',
        ];

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialise cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new ApiException('Revolut API transport error: ' . $curlError, $status);
        }

        $decoded = [];
        if (trim((string)$body) !== '') {
            $tmp = json_decode((string)$body, true);
            $decoded = is_array($tmp) ? $tmp : ['raw' => (string)$body];
        }

        $ok = ($status >= 200 && $status < 300) || in_array($status, $extraSuccessCodes, true);
        if (!$ok) {
            $message = (string)($decoded['message'] ?? $decoded['error'] ?? $decoded['code'] ?? 'Revolut API request failed');
            throw new ApiException($message . ' (HTTP ' . $status . ')', $status, $decoded);
        }

        return $decoded;
    }
}

final class Support
{
    public static function isTruthy(mixed $value): bool
    {
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true) || $value === true;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64url value.');
        }
        return $decoded;
    }

    public static function encodeGatewayToken(string $customerId, string $paymentMethodId, string $type = 'card'): string
    {
        $json = json_encode([
            'v' => 1,
            'c' => $customerId,
            'p' => $paymentMethodId,
            't' => $type,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return 'rv1.' . self::base64UrlEncode($json);
    }

    public static function decodeGatewayToken(string $token): array
    {
        if (!str_starts_with($token, 'rv1.')) {
            throw new RuntimeException('Unsupported Revolut gateway token format.');
        }

        $data = json_decode(self::base64UrlDecode(substr($token, 4)), true);
        if (!is_array($data) || empty($data['c']) || empty($data['p']) || empty($data['t'])) {
            throw new RuntimeException('Malformed Revolut gateway token.');
        }

        return [
            'customer_id' => (string)$data['c'],
            'payment_method_id' => (string)$data['p'],
            'type' => (string)$data['t'],
        ];
    }

    public static function currencyExponent(string $currency): int
    {
        $currency = strtoupper($currency);
        $zero = [
            'BIF','CLP','DJF','GNF','ISK','JPY','KMF','KRW','PYG','RWF','UGX','UYI','VND','VUV','XAF','XOF','XPF',
        ];
        $three = ['BHD','IQD','JOD','KWD','LYD','OMR','TND'];

        if (in_array($currency, $zero, true)) {
            return 0;
        }
        if (in_array($currency, $three, true)) {
            return 3;
        }
        return 2;
    }

    public static function amountToMinor(mixed $amount, string $currency): int
    {
        $exp = self::currencyExponent($currency);
        $amountString = is_string($amount) ? trim($amount) : number_format((float)$amount, max($exp, 2), '.', '');
        $amountString = str_replace(',', '', $amountString);

        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $amountString)) {
            throw new RuntimeException('Invalid monetary amount: ' . $amountString);
        }

        $negative = str_starts_with($amountString, '-');
        if ($negative) {
            $amountString = substr($amountString, 1);
        }

        [$whole, $fraction] = array_pad(explode('.', $amountString, 2), 2, '');
        if (strlen($fraction) > $exp) {
            $extra = substr($fraction, $exp);
            if (trim($extra, '0') !== '') {
                throw new RuntimeException('Amount has more decimal places than supported by ' . strtoupper($currency) . '.');
            }
            $fraction = substr($fraction, 0, $exp);
        }

        $fraction = str_pad($fraction, $exp, '0');
        $digits = ltrim($whole . $fraction, '0');
        $minor = $digits === '' ? 0 : (int)$digits;
        return $negative ? -$minor : $minor;
    }

    public static function minorToMajorString(int $minor, string $currency): string
    {
        $exp = self::currencyExponent($currency);
        $negative = $minor < 0;
        $digits = (string)abs($minor);

        if ($exp === 0) {
            return ($negative ? '-' : '') . $digits;
        }

        $digits = str_pad($digits, $exp + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -$exp);
        $fraction = substr($digits, -$exp);
        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }

    public static function signState(string $encodedState, string $secretApiKey): string
    {
        return hash_hmac('sha256', $encodedState, $secretApiKey);
    }

    public static function verifyState(string $encodedState, string $signature, string $secretApiKey): bool
    {
        if ($encodedState === '' || $signature === '' || $secretApiKey === '') {
            return false;
        }
        return hash_equals(self::signState($encodedState, $secretApiKey), $signature);
    }

    public static function encodeState(array $state): string
    {
        return self::base64UrlEncode(json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function decodeState(string $encodedState): array
    {
        $state = json_decode(self::base64UrlDecode($encodedState), true);
        if (!is_array($state)) {
            throw new RuntimeException('Invalid checkout state.');
        }
        return $state;
    }

    public static function verifyWebhook(string $rawBody, string $timestamp, string $signatureHeader, string $signingSecret): bool
    {
        if ($rawBody === '' || $timestamp === '' || $signatureHeader === '' || $signingSecret === '') {
            return false;
        }

        if (!ctype_digit($timestamp)) {
            return false;
        }

        $timestampMs = (int)$timestamp;
        $nowMs = (int)round(microtime(true) * 1000);
        if (abs($nowMs - $timestampMs) > 300000) {
            return false;
        }

        $expected = 'v1=' . hash_hmac('sha256', 'v1.' . $timestamp . '.' . $rawBody, $signingSecret);
        foreach (explode(',', $signatureHeader) as $candidate) {
            if (hash_equals($expected, trim($candidate))) {
                return true;
            }
        }
        return false;
    }


    public static function revolutLocale(string $language): string
    {
        $value = strtolower(trim($language));
        if ($value === '') {
            return 'auto';
        }

        $normalized = str_replace('_', '-', $value);
        $short = explode('-', $normalized, 2)[0];
        $map = [
            'english' => 'en', 'en' => 'en',
            'romanian' => 'ro', 'romana' => 'ro', 'română' => 'ro', 'ro' => 'ro',
            'german' => 'de', 'deutsch' => 'de', 'de' => 'de',
            'spanish' => 'es', 'espanol' => 'es', 'español' => 'es', 'es' => 'es',
            'french' => 'fr', 'francais' => 'fr', 'français' => 'fr', 'fr' => 'fr',
            'italian' => 'it', 'italiano' => 'it', 'it' => 'it',
            'dutch' => 'nl', 'nederlands' => 'nl', 'nl' => 'nl',
            'polish' => 'pl', 'polski' => 'pl', 'pl' => 'pl',
            'portuguese' => 'pt', 'portugues' => 'pt', 'português' => 'pt', 'pt' => 'pt',
            'czech' => 'cs', 'cs' => 'cs',
            'hungarian' => 'hu', 'hu' => 'hu',
            'slovak' => 'sk', 'sk' => 'sk',
            'japanese' => 'ja', 'ja' => 'ja',
            'swedish' => 'sv', 'sv' => 'sv',
            'bulgarian' => 'bg', 'bg' => 'bg',
            'russian' => 'ru', 'ru' => 'ru',
            'greek' => 'el', 'el' => 'el',
            'croatian' => 'hr', 'hr' => 'hr',
            'turkish' => 'tr', 'tr' => 'tr',
            'lithuanian' => 'lt', 'lt' => 'lt',
        ];
        return $map[$value] ?? $map[$short] ?? 'auto';
    }

    public static function paymentFeeMinor(array $payment, string $currency): int
    {
        $currency = strtoupper($currency);
        $total = 0;
        $fees = is_array($payment['fees'] ?? null) ? $payment['fees'] : [];
        foreach ($fees as $fee) {
            if (!is_array($fee) || strtoupper((string)($fee['currency'] ?? '')) !== $currency) {
                continue;
            }
            $amount = $fee['amount'] ?? null;
            if (is_int($amount) || (is_string($amount) && preg_match('/^-?\d+$/', $amount))) {
                $total += (int)$amount;
            }
        }
        return max(0, $total);
    }

    public static function orderFeeMinor(array $order, string $currency): int
    {
        $total = 0;
        $payments = is_array($order['payments'] ?? null) ? $order['payments'] : [];
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $state = strtolower((string)($payment['state'] ?? ''));
            if (!in_array($state, ['captured', 'completed'], true)) {
                continue;
            }
            $total += self::paymentFeeMinor($payment, $currency);
        }
        return $total;
    }

    public static function cardTypeLabel(string $brand): string
    {
        $brand = strtolower($brand);
        if (str_contains($brand, 'mastercard')) {
            return 'MasterCard';
        }
        if (str_contains($brand, 'visa')) {
            return 'Visa';
        }
        if (str_contains($brand, 'amex') || str_contains($brand, 'american_express')) {
            return 'American Express';
        }
        if (str_contains($brand, 'discover')) {
            return 'Discover';
        }
        if (str_contains($brand, 'jcb')) {
            return 'JCB';
        }
        if (str_contains($brand, 'maestro')) {
            return 'Maestro';
        }
        return $brand !== '' ? ucfirst(str_replace('_', ' ', $brand)) : 'Card';
    }

    public static function expiryForWhmcs(array $method): string
    {
        $month = (int)($method['expiry_month'] ?? 0);
        $year = (int)($method['expiry_year'] ?? 0);
        if ($month < 1 || $month > 12 || $year < 1) {
            return '0129';
        }
        return sprintf('%02d%02d', $month, $year % 100);
    }

    public static function safeReturnUrl(string $url, string $systemUrl): string
    {
        $systemHost = parse_url($systemUrl, PHP_URL_HOST);
        $returnHost = parse_url($url, PHP_URL_HOST);
        if (!$systemHost || !$returnHost || strcasecmp((string)$systemHost, (string)$returnHost) !== 0) {
            return rtrim($systemUrl, '/') . '/clientarea.php';
        }
        return $url;
    }
}

final class FlowStore
{
    public const TABLE = 'mod_revolutwhmcs_flows';

    public static function ensureSchema(): void
    {
        $schema = Capsule::schema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        try {
            $schema->create(self::TABLE, function ($table): void {
                $table->bigIncrements('id');
                $table->string('nonce', 64)->unique();
                $table->string('revolut_order_id', 64)->unique();
                $table->string('revolut_order_token', 128)->nullable();
                $table->unsignedInteger('invoice_id')->nullable()->index();
                $table->unsignedInteger('client_id')->index();
                $table->string('action', 16);
                $table->unsignedInteger('paymethod_id')->nullable();
                $table->bigInteger('amount_minor')->default(0);
                $table->string('amount_major', 32)->default('0');
                $table->string('currency', 8);
                $table->text('return_url')->nullable();
                $table->text('old_gateway_token')->nullable();
                $table->timestamp('processing_at')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->boolean('invoice_applied')->default(false);
                $table->boolean('paymethod_saved')->default(false);
                $table->text('last_error')->nullable();
                $table->timestamps();
            });
        } catch (\Throwable $e) {
            // Two simultaneous first requests may both observe a missing table.
            // If the other request created it first, that race is harmless.
            if (!$schema->hasTable(self::TABLE)) {
                throw $e;
            }
        }
    }

    public static function create(array $data): object
    {
        self::ensureSchema();
        $now = date('Y-m-d H:i:s');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $id = Capsule::table(self::TABLE)->insertGetId($data);
        return Capsule::table(self::TABLE)->where('id', $id)->first();
    }

    public static function byNonce(string $nonce): ?object
    {
        self::ensureSchema();
        return Capsule::table(self::TABLE)->where('nonce', $nonce)->first();
    }

    public static function byOrderId(string $orderId): ?object
    {
        self::ensureSchema();
        return Capsule::table(self::TABLE)->where('revolut_order_id', $orderId)->first();
    }

    public static function claim(int $id): bool
    {
        $staleBefore = date('Y-m-d H:i:s', time() - 300);
        $now = date('Y-m-d H:i:s');
        return Capsule::table(self::TABLE)
            ->where('id', $id)
            ->whereNull('processed_at')
            ->where(function ($q) use ($staleBefore): void {
                $q->whereNull('processing_at')->orWhere('processing_at', '<', $staleBefore);
            })
            ->update([
                'processing_at' => $now,
                'updated_at' => $now,
                'last_error' => null,
            ]) === 1;
    }

    public static function complete(int $id, bool $invoiceApplied, bool $paymethodSaved): void
    {
        $now = date('Y-m-d H:i:s');
        Capsule::table(self::TABLE)->where('id', $id)->update([
            'processed_at' => $now,
            'processing_at' => null,
            'invoice_applied' => $invoiceApplied,
            'paymethod_saved' => $paymethodSaved,
            'last_error' => null,
            'updated_at' => $now,
        ]);
    }

    public static function release(int $id): void
    {
        Capsule::table(self::TABLE)->where('id', $id)->update([
            'processing_at' => null,
            'last_error' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function releaseWithError(int $id, string $error): void
    {
        Capsule::table(self::TABLE)->where('id', $id)->update([
            'processing_at' => null,
            'last_error' => substr($error, 0, 65535),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

final class Processor
{
    public static function processFlow(object $flow, array $gatewayParams): array
    {
        if (!empty($flow->processed_at)) {
            return ['status' => 'processed', 'flow' => $flow];
        }

        if (!FlowStore::claim((int)$flow->id)) {
            $fresh = FlowStore::byNonce((string)$flow->nonce);
            return ['status' => !empty($fresh?->processed_at) ? 'processed' : 'processing', 'flow' => $fresh ?: $flow];
        }

        try {
            AppHelpers::loadWhmcsGatewayFunctions();
            $client = RevolutClient::fromGatewayParams($gatewayParams);
            $order = $client->getOrder((string)$flow->revolut_order_id);

            $orderState = strtolower((string)($order['state'] ?? ''));
            if ($orderState !== 'completed') {
                if (in_array($orderState, ['pending', 'processing', 'authorised'], true)) {
                    FlowStore::release((int)$flow->id);
                    return [
                        'status' => 'pending',
                        'flow' => FlowStore::byNonce((string)$flow->nonce) ?: $flow,
                        'order' => $order,
                    ];
                }
                throw new RuntimeException('Revolut order did not complete successfully (state: ' . ($orderState ?: 'unknown') . ').');
            }

            $expectedMinor = (int)$flow->amount_minor;
            if ((int)($order['amount'] ?? -1) !== $expectedMinor) {
                throw new RuntimeException('Revolut order amount does not match WHMCS flow amount.');
            }
            if (strcasecmp((string)($order['currency'] ?? ''), (string)$flow->currency) !== 0) {
                throw new RuntimeException('Revolut order currency does not match WHMCS flow currency.');
            }

            $invoiceApplied = (bool)$flow->invoice_applied;
            $paymethodSaved = (bool)$flow->paymethod_saved;
            $savedMethod = self::findSavedMerchantMethod($client, $order, (string)$flow->action);

            if ((string)$flow->action === 'payment' && (int)$flow->invoice_id > 0 && !$invoiceApplied) {
                $invoiceId = checkCbInvoiceID((int)$flow->invoice_id, (string)$gatewayParams['paymentmethod']);
                $alreadyExists = Capsule::table('tblaccounts')
                    ->where('transid', (string)$flow->revolut_order_id)
                    ->exists();

                if (!$alreadyExists) {
                    $fee = '0';
                    if (Support::isTruthy($gatewayParams['recordFees'] ?? false)) {
                        $fee = Support::minorToMajorString(
                            Support::orderFeeMinor($order, (string)$flow->currency),
                            (string)$flow->currency
                        );
                    }
                    addInvoicePayment(
                        $invoiceId,
                        (string)$flow->revolut_order_id,
                        (string)$flow->amount_major,
                        $fee,
                        'revolutwhmcs'
                    );
                }
                $invoiceApplied = true;
            }

            if ($savedMethod !== null && !$paymethodSaved) {
                $remoteToken = Support::encodeGatewayToken(
                    (string)$savedMethod['customer_id'],
                    (string)$savedMethod['id'],
                    (string)$savedMethod['type']
                );
                $lastFour = (string)($savedMethod['last_four'] ?? '0000');
                $brand = Support::cardTypeLabel((string)($savedMethod['brand'] ?? 'card'));
                $expiry = Support::expiryForWhmcs($savedMethod);

                if ((string)$flow->action === 'payment' && (int)$flow->invoice_id > 0) {
                    invoiceSaveRemoteCard((int)$flow->invoice_id, $lastFour, $brand, $expiry, $remoteToken);
                    $paymethodSaved = true;
                } elseif ((string)$flow->action === 'create') {
                    createCardPayMethod(
                        (int)$flow->client_id,
                        'revolutwhmcs',
                        $lastFour,
                        $expiry,
                        $brand,
                        null,
                        null,
                        $remoteToken
                    );
                    $paymethodSaved = true;
                } elseif ((string)$flow->action === 'update' && (int)$flow->paymethod_id > 0) {
                    updateCardPayMethod(
                        (int)$flow->client_id,
                        (int)$flow->paymethod_id,
                        $expiry,
                        null,
                        null,
                        $remoteToken
                    );
                    $paymethodSaved = true;
                    self::deleteOldRemoteMethodIfNeeded($client, (string)($flow->old_gateway_token ?? ''), $remoteToken);
                }
            } elseif (in_array((string)$flow->action, ['create', 'update'], true)) {
                throw new RuntimeException('No merchant-saved card was found after the Revolut setup flow.');
            }

            FlowStore::complete((int)$flow->id, $invoiceApplied, $paymethodSaved);
            $fresh = FlowStore::byNonce((string)$flow->nonce) ?: $flow;
            return [
                'status' => 'success',
                'flow' => $fresh,
                'order' => $order,
                'saved_method' => $savedMethod,
            ];
        } catch (\Throwable $e) {
            FlowStore::releaseWithError((int)$flow->id, $e->getMessage());
            throw $e;
        }
    }

    private static function findSavedMerchantMethod(RevolutClient $client, array $order, string $action): ?array
    {
        $customerId = (string)($order['customer']['id'] ?? '');
        if ($customerId === '') {
            return null;
        }

        $payments = is_array($order['payments'] ?? null) ? $order['payments'] : [];
        $lastPaymentMethod = null;
        foreach (array_reverse($payments) as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $pm = $payment['payment_method'] ?? null;
            if (is_array($pm)) {
                $lastPaymentMethod = $pm;
                break;
            }
        }

        $transactionalType = strtolower((string)($lastPaymentMethod['type'] ?? ''));
        if ($action === 'payment' && $transactionalType !== '' && str_starts_with($transactionalType, 'revolut_pay')) {
            return null;
        }

        $transactionalId = (string)($lastPaymentMethod['id'] ?? '');
        if ($transactionalId !== '' && ($transactionalType === 'card' || $action !== 'payment')) {
            try {
                $method = $client->getCustomerPaymentMethod($customerId, $transactionalId);
                if (($method['saved_for'] ?? '') === 'merchant' && ($method['type'] ?? '') === 'card') {
                    $method['customer_id'] = $customerId;
                    return $method;
                }
            } catch (\Throwable) {
                // Fall back to the customer payment-method list below.
            }
        }

        if ($action === 'payment') {
            return null;
        }

        $list = $client->listCustomerPaymentMethods($customerId, true);
        $methods = is_array($list['payment_methods'] ?? null) ? $list['payment_methods'] : [];
        usort($methods, static function (array $a, array $b): int {
            return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
        });

        $orderCreatedAt = strtotime((string)($order['created_at'] ?? '')) ?: 0;
        foreach ($methods as $method) {
            if (($method['type'] ?? '') !== 'card' || ($method['saved_for'] ?? '') !== 'merchant') {
                continue;
            }
            $createdAt = strtotime((string)($method['created_at'] ?? '')) ?: 0;
            if ($orderCreatedAt > 0 && $createdAt > 0 && $createdAt + 60 < $orderCreatedAt) {
                continue;
            }
            $method['customer_id'] = $customerId;
            return $method;
        }

        return null;
    }

    private static function deleteOldRemoteMethodIfNeeded(RevolutClient $client, string $oldToken, string $newToken): void
    {
        if ($oldToken === '' || hash_equals($oldToken, $newToken)) {
            return;
        }
        try {
            $old = Support::decodeGatewayToken($oldToken);
            $new = Support::decodeGatewayToken($newToken);
            if ($old['customer_id'] !== $new['customer_id'] || $old['payment_method_id'] !== $new['payment_method_id']) {
                $client->deleteCustomerPaymentMethod($old['customer_id'], $old['payment_method_id']);
            }
        } catch (\Throwable) {
            // Updating the WHMCS Pay Method succeeded; remote cleanup is best-effort.
        }
    }
}

final class AppHelpers
{
    public static function loadWhmcsGatewayFunctions(): void
    {
        if (class_exists('App')) {
            \App::load_function('gateway');
            \App::load_function('invoice');
        }
    }

    public static function gatewayParams(): array
    {
        self::loadWhmcsGatewayFunctions();
        $params = getGatewayVariables('revolutwhmcs');
        if (empty($params['type'])) {
            throw new RuntimeException('Revolut WHMCS gateway is not activated.');
        }

        // getGatewayVariables() is gateway configuration, not the full runtime
        // merchant parameter array. Callback/bridge scripts therefore recover
        // the WHMCS System URL from the supported configuration model.
        if (empty($params['systemurl'])) {
            $systemUrl = null;
            if (class_exists('\\WHMCS\\Config\\Setting')) {
                $systemUrl = \WHMCS\Config\Setting::getValue('SystemURL');
            }
            if (!$systemUrl && isset($GLOBALS['CONFIG']['SystemURL'])) {
                $systemUrl = (string)$GLOBALS['CONFIG']['SystemURL'];
            }
            if (!$systemUrl) {
                throw new RuntimeException('Unable to determine the WHMCS System URL.');
            }
            $params['systemurl'] = (string)$systemUrl;
        }

        return $params;
    }

    public static function moduleBaseUrl(array $gatewayParams): string
    {
        return rtrim((string)$gatewayParams['systemurl'], '/') . '/modules/gateways/revolutwhmcs';
    }
}
