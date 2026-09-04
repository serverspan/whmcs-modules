<?php
namespace ServerSpan\LogicBoxesTools;

final class ApiClient
{
    private string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly int $resellerId,
        private readonly string $apiKey,
        private readonly mixed $transport = null
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if (!preg_match('#^https://#i', $baseUrl)) {
            throw new \InvalidArgumentException('LogicBoxes API URL must use HTTPS.');
        }
        $this->baseUrl = $baseUrl;
    }

    public function ping(): array
    {
        return $this->searchCustomers(1, 10);
    }

    public function searchCustomers(int $page = 1, int $limit = 100, array $filters = []): array
    {
        return $this->request('GET', '/customers/search.json', array_merge($filters, [
            'page-no' => max(1, $page),
            'no-of-records' => min(500, max(10, $limit)),
        ]));
    }

    public function customerById(int $customerId): array
    {
        return $this->request('GET', '/customers/details-by-id.json', ['customer-id' => $customerId]);
    }

    public function customerByUsername(string $username): array
    {
        return $this->request('GET', '/customers/details.json', ['username' => $username]);
    }

    public function signupCustomer(array $data): int
    {
        $response = $this->request('POST', '/customers/v2/signup.xml', $data);
        if (isset($response['customerid'])) {
            return (int) $response['customerid'];
        }
        foreach ($response as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }
        throw new ApiException('LogicBoxes signup response did not contain a customer ID.', null, $response);
    }

    public function modifyCustomer(array $data): bool
    {
        $response = $this->request('POST', '/customers/modify.json', $data);
        return $response === ['value' => true] || ($response['status'] ?? null) === 'SUCCESS' || ($response['result'] ?? null) === true;
    }

    public function deleteCustomer(int $customerId): array
    {
        return $this->request('POST', '/customers/delete.json', ['customer-id' => $customerId]);
    }

    public function customerLoginToken(int $customerId, string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('A valid customer IP address is required for OrderBox SSO.');
        }
        $result = $this->request('GET', '/customers/generate-login-token.json', [
            'customer-id' => $customerId,
            'ip' => $ip,
        ]);
        $token = (string)($result['value'] ?? Support::firstValue($result, ['token', 'auth-token'], ''));
        if ($token === '') {
            throw new \RuntimeException('LogicBoxes did not return a customer login token.');
        }
        return $token;
    }

    public function searchDomains(int $page = 1, int $limit = 100, array $filters = []): array
    {
        return $this->request('GET', '/domains/search.json', array_merge($filters, [
            'page-no' => max(1, $page),
            'no-of-records' => min(500, max(10, $limit)),
        ]));
    }

    public function domainDetails(int $orderId): array
    {
        return $this->request('GET', '/domains/details.json', [
            'order-id' => $orderId,
            'options' => 'OrderDetails',
        ]);
    }

    public function customerPricing(?int $customerId = null): array
    {
        $params = $customerId ? ['customer-id' => $customerId] : [];
        return $this->request('GET', '/products/customer-price.json', $params);
    }

    public function resellerCostPricing(): array
    {
        return $this->request('GET', '/products/reseller-cost-price.json');
    }

    public function resellerSlabPricing(): array
    {
        return $this->request('GET', '/products/reseller-price.json');
    }

    public function promotions(): array
    {
        return $this->request('GET', '/resellers/promo-details.json');
    }

    public function productDetails(): array
    {
        return $this->request('GET', '/products/details.json');
    }

    public function productCategoryMapping(): array
    {
        return $this->request('GET', '/products/category-keys-mapping.json');
    }

    public function tldInfo(): array
    {
        return $this->request('POST', '/domains/tld-info.json');
    }

    public function resellerBalance(): array
    {
        return $this->request('GET', '/billing/reseller-balance.json', ['reseller-id' => $this->resellerId]);
    }

    public function moveProducts(string $domain, int $fromCustomerId, int $toCustomerId): array
    {
        return $this->request('POST', '/products/move.json', [
            'domain-name' => Support::canonicalDomain($domain),
            'existing-customer-id' => $fromCustomerId,
            'new-customer-id' => $toCustomerId,
            'default-contact' => 'oldcontact',
        ]);
    }

    public function request(string $method, string $path, array $params = []): array
    {
        $params['auth-userid'] = $this->resellerId;
        $params['api-key'] = $this->apiKey;
        $method = strtoupper($method);
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        if (is_callable($this->transport)) {
            $raw = ($this->transport)($method, $url, $params);
            if (!is_array($raw) || !array_key_exists('body', $raw)) {
                throw new ApiException('Invalid test transport response.');
            }
            $status = (int) ($raw['status'] ?? 200);
            $body = (string) $raw['body'];
            $contentType = (string) ($raw['content_type'] ?? 'application/json');
        } else {
            if (!function_exists('curl_init')) {
                throw new ApiException('PHP cURL extension is required.');
            }
            $ch = curl_init();
            $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $requestUrl = $method === 'GET' ? $url . '?' . $query : $url;
            curl_setopt_array($ch, [
                CURLOPT_URL => $requestUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 40,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'ServerSpan-WHMCS-LogicBoxes/1.0',
                CURLOPT_HTTPHEADER => ['Accept: application/json, application/xml;q=0.9, text/xml;q=0.8'],
            ]);
            if ($method !== 'GET') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Accept: application/json, application/xml;q=0.9, text/xml;q=0.8',
                    'Content-Type: application/x-www-form-urlencoded',
                ]);
            }
            $body = curl_exec($ch);
            if ($body === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new ApiException('LogicBoxes transport error: ' . $error);
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
        }

        $decoded = $this->decode((string) $body, $contentType);
        if ($status < 200 || $status >= 300) {
            throw new ApiException('LogicBoxes HTTP error ' . $status . '.', $status, Support::redact($decoded));
        }
        if (is_array($decoded)) {
            $apiStatus = strtoupper((string) ($decoded['status'] ?? ''));
            if ($apiStatus === 'ERROR' || isset($decoded['error'])) {
                $message = (string) ($decoded['message'] ?? $decoded['error'] ?? $decoded['error_description'] ?? 'LogicBoxes API error');
                throw new ApiException($message, $status, Support::redact($decoded));
            }
        }
        return $decoded;
    }

    private function decode(string $body, string $contentType): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }
        if (str_contains(strtolower($contentType), 'json') || str_starts_with($body, '{') || str_starts_with($body, '[')) {
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                if (is_bool($decoded) || is_numeric($decoded) || is_string($decoded)) {
                    return ['value' => $decoded];
                }
                throw new ApiException('Invalid JSON response from LogicBoxes.');
            }
            return $decoded;
        }
        if (str_starts_with($body, '<')) {
            if (!function_exists('simplexml_load_string')) {
                throw new ApiException('SimpleXML is required to parse this LogicBoxes response.');
            }
            $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml === false) {
                throw new ApiException('Invalid XML response from LogicBoxes.');
            }
            $json = json_encode($xml, JSON_THROW_ON_ERROR);
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return ['value' => (string) $xml];
            }
            if ($decoded === [] && trim((string) $xml) !== '') {
                return ['value' => trim((string) $xml)];
            }
            return $decoded;
        }
        if (is_numeric($body)) {
            return ['value' => $body];
        }
        if (strcasecmp($body, 'true') === 0 || strcasecmp($body, 'false') === 0) {
            return ['value' => strcasecmp($body, 'true') === 0];
        }
        return ['value' => $body];
    }
}
