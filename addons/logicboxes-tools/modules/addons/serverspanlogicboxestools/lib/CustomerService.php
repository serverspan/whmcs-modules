<?php
namespace ServerSpan\LogicBoxesTools;

use WHMCS\Database\Capsule;

final class CustomerService
{
    private static bool $busy = false;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly AuditLogger $audit
    ) {}

    public function mappingForClient(int $accountId, int $clientId): ?array
    {
        $row = Capsule::table(Schema::TABLE_CUSTOMERS)
            ->where('account_id', $accountId)->where('whmcs_client_id', $clientId)->first();
        return $row ? (array) $row : null;
    }

    public function mappingForUpstream(int $accountId, int $customerId): ?array
    {
        $row = Capsule::table(Schema::TABLE_CUSTOMERS)
            ->where('account_id', $accountId)->where('logicboxes_customer_id', $customerId)->first();
        return $row ? (array) $row : null;
    }

    public function exportClient(int $accountId, int $clientId, bool $acceptPolicy = false, string $actor = 'admin'): array
    {
        if (self::$busy) {
            throw new \RuntimeException('Customer synchronization recursion prevented.');
        }
        self::$busy = true;
        try {
            $account = $this->accounts->find($accountId, true) ?? throw new \RuntimeException('LogicBoxes account not found.');
            $client = $this->whmcsClient($clientId);
            $api = $this->accounts->client($account);
            $existingMap = $this->mappingForClient($accountId, $clientId);

            if ($existingMap) {
                $payload = $this->logicBoxesPayload($client);
                $payload['customer-id'] = (int) $existingMap['logicboxes_customer_id'];
                $api->modifyCustomer($payload);
                $this->storeMapping($accountId, $clientId, (int) $existingMap['logicboxes_customer_id'], (string) $client['email'], (string) $existingMap['origin'], $client);
                $this->audit->write('customer.modify', $accountId, 'client', (string) $clientId, null, ['logicboxes_customer_id' => (int)$existingMap['logicboxes_customer_id']], [], $this->adminId(), $actor);
                return ['customer_id' => (int) $existingMap['logicboxes_customer_id'], 'created' => false, 'adopted' => false];
            }

            try {
                $found = $api->customerByUsername((string) $client['email']);
                $upstreamId = (int) Support::firstValue($found, ['customerid', 'customer-id'], 0);
            } catch (ApiException) {
                $upstreamId = 0;
            }

            if ($upstreamId > 0) {
                $this->storeMapping($accountId, $clientId, $upstreamId, (string) $client['email'], 'adopted', $client);
                $this->audit->write('customer.adopt', $accountId, 'client', (string) $clientId, null, ['logicboxes_customer_id' => $upstreamId], [], $this->adminId(), $actor);
                return ['customer_id' => $upstreamId, 'created' => false, 'adopted' => true];
            }

            if (!$acceptPolicy) {
                throw new \RuntimeException('Creating a LogicBoxes customer requires explicit upstream policy acceptance.');
            }

            $password = Support::randomPassword();
            $payload = $this->logicBoxesPayload($client);
            $payload['passwd'] = $password;
            $payload['accept-policy'] = true;
            $payload['marketing-email-consent'] = false;
            $upstreamId = $api->signupCustomer($payload);
            unset($password, $payload['passwd']);
            $this->storeMapping($accountId, $clientId, $upstreamId, (string) $client['email'], 'created', $client);
            $this->audit->write('customer.create', $accountId, 'client', (string) $clientId, null, ['logicboxes_customer_id' => $upstreamId], [], $this->adminId(), $actor);
            return ['customer_id' => $upstreamId, 'created' => true, 'adopted' => false];
        } finally {
            self::$busy = false;
        }
    }

    public function importCustomer(int $accountId, int $customerId, ?int $currencyId = null): array
    {
        if (self::$busy) {
            throw new \RuntimeException('Customer synchronization recursion prevented.');
        }
        self::$busy = true;
        try {
            $account = $this->accounts->find($accountId, true) ?? throw new \RuntimeException('LogicBoxes account not found.');
            $api = $this->accounts->client($account);
            $remote = $api->customerById($customerId);
            $email = strtolower(trim((string) Support::firstValue($remote, ['username', 'useremail'], '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Upstream customer does not have a valid email address.');
            }

            $existingMap = $this->mappingForUpstream($accountId, $customerId);
            if ($existingMap) {
                return ['client_id' => (int)$existingMap['whmcs_client_id'], 'created' => false, 'mapped' => true];
            }

            $client = Capsule::table('tblclients')->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($client) {
                $clientId = (int) $client->id;
                $created = false;
            } else {
                [$first, $last] = Support::splitName((string)($remote['name'] ?? ''));
                $phone = '+' . preg_replace('/\D+/', '', (string)($remote['telnocc'] ?? '')) . preg_replace('/\D+/', '', (string)($remote['telno'] ?? ''));
                $post = [
                    'firstname' => $first,
                    'lastname' => $last,
                    'companyname' => (string)($remote['company'] ?? ''),
                    'email' => $email,
                    'address1' => (string)($remote['address1'] ?? 'Not Applicable'),
                    'address2' => trim((string)($remote['address2'] ?? '') . ' ' . (string)($remote['address3'] ?? '')),
                    'city' => (string)($remote['city'] ?? 'Not Applicable'),
                    'state' => (string)($remote['state'] ?? 'Not Applicable'),
                    'postcode' => (string)($remote['zip'] ?? '00000'),
                    'country' => strtoupper((string)($remote['country'] ?? 'US')),
                    'phonenumber' => $phone,
                    'password2' => Support::randomPassword(32),
                    'noemail' => true,
                    'skipvalidation' => false,
                    'notes' => 'Imported from LogicBoxes account #' . $accountId . '; upstream customer ID ' . $customerId . '.',
                ];
                if ($currencyId) {
                    $post['currency'] = $currencyId;
                }
                $result = localAPI('AddClient', $post);
                if (($result['result'] ?? '') !== 'success') {
                    throw new \RuntimeException('WHMCS AddClient failed: ' . ($result['message'] ?? 'unknown error'));
                }
                $clientId = (int) $result['clientid'];
                $created = true;
            }

            $clientData = $this->whmcsClient($clientId);
            $this->storeMapping($accountId, $clientId, $customerId, $email, 'imported', $clientData);
            $this->audit->write('customer.import', $accountId, 'client', (string)$clientId, null, ['logicboxes_customer_id' => $customerId, 'created' => $created], [], $this->adminId(), 'admin');
            return ['client_id' => $clientId, 'created' => $created, 'mapped' => true];
        } finally {
            self::$busy = false;
        }
    }

    public function modifyMappedClient(int $clientId, string $actor = 'hook'): void
    {
        if (self::$busy) {
            return;
        }
        foreach ($this->accounts->all(true) as $account) {
            if (!(bool)$account['auto_customer_modify']) {
                continue;
            }
            $mapping = $this->mappingForClient((int)$account['id'], $clientId);
            if (!$mapping) {
                continue;
            }
            try {
                $this->exportClient((int)$account['id'], $clientId, false, $actor);
            } catch (\Throwable $e) {
                Capsule::table(Schema::TABLE_CUSTOMERS)->where('id', $mapping['id'])->update(['last_error' => substr($e->getMessage(), 0, 4000), 'updated_at' => date('Y-m-d H:i:s')]);
            }
        }
    }

    public function autoSignupClient(int $clientId): void
    {
        if (self::$busy) {
            return;
        }
        foreach ($this->accounts->all(true) as $account) {
            if (!(bool)$account['auto_customer_signup']) {
                continue;
            }
            $options = Support::fromJson($account['options'] ?? null);
            if (!Support::bool($options['accept_policy_for_auto_signup'] ?? false)) {
                continue;
            }
            try {
                $this->exportClient((int)$account['id'], $clientId, true, 'hook');
            } catch (\Throwable $e) {
                $this->audit->write('customer.auto_signup_failed', (int)$account['id'], 'client', (string)$clientId, null, null, ['error' => $e->getMessage()], null, 'hook');
            }
        }
    }

    public function deleteMappedClient(int $clientId): void
    {
        if (self::$busy) {
            return;
        }
        foreach ($this->accounts->all(true) as $account) {
            if (!(bool)$account['auto_customer_delete']) {
                continue;
            }
            $mapping = $this->mappingForClient((int)$account['id'], $clientId);
            if (!$mapping || $mapping['origin'] !== 'created') {
                continue;
            }
            try {
                $apiAccount = $this->accounts->find((int)$account['id'], true);
                $api = $this->accounts->client($apiAccount);
                $domains = $api->searchDomains(1, 10, ['customer-id' => [(int)$mapping['logicboxes_customer_id']]]);
                $rows = Support::flattenRows($domains, 'orderid');
                if ($rows !== []) {
                    throw new \RuntimeException('Upstream customer owns domain orders; destructive delete refused.');
                }
                $api->deleteCustomer((int)$mapping['logicboxes_customer_id']);
                Capsule::table(Schema::TABLE_CUSTOMERS)->where('id', $mapping['id'])->delete();
                $this->audit->write('customer.delete', (int)$account['id'], 'client', (string)$clientId, ['logicboxes_customer_id' => (int)$mapping['logicboxes_customer_id']], null, [], null, 'hook');
            } catch (\Throwable $e) {
                $this->audit->write('customer.delete_refused', (int)$account['id'], 'client', (string)$clientId, null, null, ['error' => $e->getMessage()], null, 'hook');
            }
        }
    }

    public function logicBoxesPayload(array $client): array
    {
        [$phoneCc, $phone] = Support::normalizePhone((string)$client['phonenumber'], (string)$client['country']);
        $state = trim((string)$client['state']);
        return [
            'username' => strtolower(trim((string)$client['email'])),
            'name' => trim((string)$client['firstname'] . ' ' . (string)$client['lastname']),
            'company' => trim((string)$client['companyname']) !== '' ? trim((string)$client['companyname']) : 'Not Applicable',
            'address-line-1' => substr(trim((string)$client['address1']), 0, 64),
            'address-line-2' => substr(trim((string)$client['address2']), 0, 64),
            'city' => trim((string)$client['city']),
            'state' => $state !== '' ? $state : 'Not Applicable',
            'country' => strtoupper((string)$client['country']),
            'zipcode' => trim((string)$client['postcode']),
            'phone-cc' => $phoneCc,
            'phone' => $phone,
            'lang-pref' => $this->languageCode((string)($client['language'] ?? 'english')),
            'vat-id' => trim((string)($client['tax_id'] ?? '')),
        ];
    }

    private function storeMapping(int $accountId, int $clientId, int $upstreamId, string $username, string $origin, array $client): void
    {
        $now = date('Y-m-d H:i:s');
        $hash = hash('sha256', Support::json([
            $client['firstname'], $client['lastname'], $client['companyname'], $client['email'], $client['address1'], $client['address2'],
            $client['city'], $client['state'], $client['postcode'], $client['country'], $client['phonenumber'], $client['tax_id'] ?? '',
        ]));
        Capsule::table(Schema::TABLE_CUSTOMERS)->updateOrInsert(
            ['account_id' => $accountId, 'whmcs_client_id' => $clientId],
            [
                'logicboxes_customer_id' => $upstreamId,
                'username' => strtolower($username),
                'sync_hash' => $hash,
                'origin' => $origin,
                'status' => 'active',
                'last_error' => null,
                'last_synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function whmcsClient(int $clientId): array
    {
        $row = Capsule::table('tblclients')->where('id', $clientId)->first();
        if (!$row) {
            throw new \RuntimeException('WHMCS client not found.');
        }
        return (array)$row;
    }

    private function languageCode(string $language): string
    {
        return match (strtolower($language)) {
            'romanian' => 'ro', 'german' => 'de', 'french' => 'fr', 'spanish' => 'es', 'italian' => 'it',
            'portuguese-br', 'portuguese' => 'pt', default => 'en',
        };
    }

    private function adminId(): ?int
    {
        return isset($_SESSION['adminid']) ? (int)$_SESSION['adminid'] : null;
    }
}
