<?php
namespace ServerSpan\LogicBoxesTools;

use WHMCS\Database\Capsule;

final class AccountRepository
{
    public function all(bool $enabledOnly = false): array
    {
        $query = Capsule::table(Schema::TABLE_ACCOUNTS)->orderBy('name');
        if ($enabledOnly) {
            $query->where('enabled', 1);
        }
        return array_map(fn($r) => (array) $r, $query->get()->all());
    }

    public function find(int $id, bool $withSecret = false): ?array
    {
        $row = Capsule::table(Schema::TABLE_ACCOUNTS)->where('id', $id)->first();
        if (!$row) {
            return null;
        }
        $account = (array) $row;
        $account['options'] = Support::fromJson($account['options'] ?? null);
        $account['nameservers'] = Support::fromJson($account['nameservers'] ?? null);
        if ($withSecret) {
            $account['api_key'] = Crypto::decrypt((string) $account['api_key_cipher']);
        }
        unset($account['api_key_cipher']);
        return $account;
    }

    public function save(array $data, ?int $id = null): int
    {
        $now = date('Y-m-d H:i:s');
        $payload = [
            'name' => trim((string) ($data['name'] ?? '')),
            'registrar_module' => preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($data['registrar_module'] ?? 'resellerclub')),
            'reseller_id' => (int) ($data['reseller_id'] ?? 0),
            'base_url' => rtrim(trim((string) ($data['base_url'] ?? 'https://httpapi.com/api')), '/'),
            'currency' => strtoupper(substr(trim((string) ($data['currency'] ?? 'USD')), 0, 3)),
            'multiplier' => max(1, (int) ($data['multiplier'] ?? 1)),
            'nameservers' => Support::json(array_values(array_filter(array_map('trim', (array) ($data['nameservers'] ?? []))))),
            'fund_threshold' => ($data['fund_threshold'] ?? '') === '' ? null : (float) $data['fund_threshold'],
            'enabled' => Support::bool($data['enabled'] ?? false) ? 1 : 0,
            'auto_customer_signup' => Support::bool($data['auto_customer_signup'] ?? false) ? 1 : 0,
            'auto_customer_modify' => Support::bool($data['auto_customer_modify'] ?? false) ? 1 : 0,
            'auto_customer_delete' => Support::bool($data['auto_customer_delete'] ?? false) ? 1 : 0,
            'auto_price_sync' => Support::bool($data['auto_price_sync'] ?? false) ? 1 : 0,
            'auto_promo_sync' => Support::bool($data['auto_promo_sync'] ?? false) ? 1 : 0,
            'auto_transfer_sync' => Support::bool($data['auto_transfer_sync'] ?? false) ? 1 : 0,
            'auto_recurring_sync' => Support::bool($data['auto_recurring_sync'] ?? false) ? 1 : 0,
            'options' => Support::json((array) ($data['options'] ?? [])),
            'updated_at' => $now,
        ];
        if ($payload['name'] === '' || $payload['reseller_id'] <= 0) {
            throw new \InvalidArgumentException('Account name and reseller ID are required.');
        }
        if (!preg_match('#^https://#i', $payload['base_url'])) {
            throw new \InvalidArgumentException('API endpoint must use HTTPS.');
        }

        $apiKey = trim((string) ($data['api_key'] ?? ''));
        if ($id === null) {
            if ($apiKey === '') {
                throw new \InvalidArgumentException('API key is required for a new account.');
            }
            $payload['api_key_cipher'] = Crypto::encrypt($apiKey);
            $payload['created_at'] = $now;
            return (int) Capsule::table(Schema::TABLE_ACCOUNTS)->insertGetId($payload);
        }

        if ($apiKey !== '') {
            $payload['api_key_cipher'] = Crypto::encrypt($apiKey);
        }
        Capsule::table(Schema::TABLE_ACCOUNTS)->where('id', $id)->update($payload);
        return $id;
    }

    public function markHealth(int $id, bool $ok, ?string $error = null): void
    {
        $payload = [
            'last_error' => $ok ? null : substr((string) $error, 0, 4000),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($ok) {
            $payload['last_ok_at'] = date('Y-m-d H:i:s');
        }
        Capsule::table(Schema::TABLE_ACCOUNTS)->where('id', $id)->update($payload);
    }

    public function delete(int $id): void
    {
        $mapped = Capsule::table(Schema::TABLE_CUSTOMERS)->where('account_id', $id)->count()
            + Capsule::table(Schema::TABLE_DOMAINS)->where('account_id', $id)->count();
        if ($mapped > 0) {
            throw new \RuntimeException('Account has mapped customers/domains and cannot be deleted. Disable it instead.');
        }
        Capsule::table(Schema::TABLE_ACCOUNTS)->where('id', $id)->delete();
    }

    public function client(array $account): ApiClient
    {
        if (!isset($account['api_key'])) {
            $account = $this->find((int) $account['id'], true) ?? throw new \RuntimeException('Account not found.');
        }
        return new ApiClient(
            (string) $account['base_url'],
            (int) $account['reseller_id'],
            (string) $account['api_key']
        );
    }
}
