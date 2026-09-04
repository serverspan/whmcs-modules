<?php

declare(strict_types=1);

namespace ServerSpan\WHMCS\ColeteOnline;

final class ApiClient
{
    private const AUTH_URL = 'https://auth.colete-online.ro/token';
    private const PROD_URL = 'https://api.colete-online.ro/v1/';
    private const STAGING_URL = 'https://api.colete-online.ro/v1/staging/';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool $staging,
        private readonly CacheRepository $cache,
        private readonly bool $debug = false
    ) {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new ApiException('Colete-Online API credentials are not configured.');
        }
        if (!extension_loaded('curl')) {
            throw new ApiException('The PHP cURL extension is required.');
        }
    }

    public function balance(): array
    {
        return $this->requestJson('GET', 'user/balance');
    }

    public function addresses(): array
    {
        return $this->requestJson('GET', 'address');
    }

    public function services(): array
    {
        return $this->requestJson('GET', 'service/list');
    }

    public function quote(array $payload): array
    {
        return $this->requestJson('POST', 'order/price', $payload);
    }

    public function createOrder(array $payload): array
    {
        return $this->requestJson('POST', 'order', $payload);
    }

    public function status(string $uniqueId): array
    {
        return $this->requestJson('GET', 'order/status/' . rawurlencode($uniqueId));
    }

    public function awb(string $uniqueId): array
    {
        return $this->requestRaw('GET', 'order/awb/' . rawurlencode($uniqueId));
    }

    public function searchLocation(string $countryCode, string $needle): array
    {
        return $this->requestJson('GET', 'search/location/' . rawurlencode(strtoupper($countryCode)) . '/' . rawurlencode($needle), null, [
            'format' => 'objectFull',
            'group' => 'true',
            'limit' => '20',
        ]);
    }

    private function token(bool $force = false): string
    {
        if (!$force) {
            $cached = $this->cache->getToken();
            if ($cached && !empty($cached['token'])) {
                return (string) $cached['token'];
            }
        }

        $ch = curl_init(self::AUTH_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials']),
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            throw new ApiException('Authentication transport error: ' . ($error ?: 'unknown cURL error'));
        }
        $data = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['access_token'])) {
            throw new ApiException('Colete-Online authentication failed (HTTP ' . $status . ').', $status, is_array($data) ? $data : null);
        }

        $token = (string) $data['access_token'];
        $this->cache->putToken($token, (int) ($data['expires_in'] ?? 7199));
        return $token;
    }

    private function requestJson(string $method, string $path, ?array $payload = null, array $query = [], bool $retry401 = true): array
    {
        $result = $this->requestRaw($method, $path, $payload, $query, $retry401);
        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            throw new ApiException('Colete-Online returned an invalid JSON response.', $result['status']);
        }
        return $decoded;
    }

    private function requestRaw(string $method, string $path, ?array $payload = null, array $query = [], bool $retry401 = true): array
    {
        $url = ($this->staging ? self::STAGING_URL : self::PROD_URL) . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $this->token(),
            'Accept: */*',
        ];
        $body = null;
        if ($payload !== null) {
            $body = Support::safeJson($payload);
            $headers[] = 'Content-Type: application/json';
        }

        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return $length;
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($this->debug && function_exists('logModuleCall')) {
            logModuleCall('serverspancoleteonline', 'api', ['method' => $method, 'path' => $path], ['http_status' => $status]);
        }

        if ($errno !== 0 || $responseBody === false) {
            throw new ApiException('Colete-Online transport error: ' . ($error ?: 'unknown cURL error'));
        }

        if ($status === 401 && $retry401) {
            $this->cache->forgetToken();
            $this->token(true);
            return $this->requestRaw($method, $path, $payload, $query, false);
        }

        if ($status < 200 || $status >= 300) {
            $decoded = json_decode((string) $responseBody, true);
            $message = self::errorMessage(is_array($decoded) ? $decoded : null, $status);
            throw new ApiException($message, $status, is_array($decoded) ? $decoded : null);
        }

        return [
            'status' => $status,
            'body' => (string) $responseBody,
            'content_type' => $contentType ?: ($responseHeaders['content-type'] ?? 'application/octet-stream'),
            'headers' => $responseHeaders,
        ];
    }

    public static function errorMessage(?array $data, int $status): string
    {
        $candidate = $data['message'] ?? $data['error_description'] ?? $data['error'] ?? null;
        if (is_string($candidate) && trim($candidate) !== '') {
            return 'Colete-Online API error (HTTP ' . $status . '): ' . trim($candidate);
        }
        return 'Colete-Online API request failed (HTTP ' . $status . ').';
    }
}
