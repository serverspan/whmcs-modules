<?php
namespace ServerSpan\LogicBoxesTools;

use WHMCS\Database\Capsule;

final class DomainService
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly CustomerService $customers,
        private readonly AuditLogger $audit
    ) {}

    public function discover(int $accountId, int $page = 1, int $limit = 100, array $filters = []): array
    {
        $account = $this->accounts->find($accountId, true) ?? throw new \RuntimeException('LogicBoxes account not found.');
        $payload = $this->accounts->client($account)->searchDomains($page, $limit, $filters);
        return $this->normalizeSearchRows($payload);
    }

    public function importOrder(int $accountId, int $orderId, bool $recalculatePricing = true): array
    {
        $account = $this->accounts->find($accountId, true) ?? throw new \RuntimeException('LogicBoxes account not found.');
        $details = $this->accounts->client($account)->domainDetails($orderId);
        $normalized = $this->normalizeDomain($details);
        if ($normalized['order_id'] <= 0 || $normalized['domain'] === '') {
            throw new \RuntimeException('Could not identify LogicBoxes domain order.');
        }

        $existingMap = Capsule::table(Schema::TABLE_DOMAINS)
            ->where('account_id', $accountId)->where('logicboxes_order_id', $normalized['order_id'])->first();
        if ($existingMap && $existingMap->whmcs_domain_id) {
            $this->storeMapping($accountId, (int)$existingMap->whmcs_domain_id, $normalized);
            return ['domain_id' => (int)$existingMap->whmcs_domain_id, 'whmcs_domain_id' => (int)$existingMap->whmcs_domain_id, 'domain' => $normalized['domain'], 'created' => false];
        }

        $domainRow = Capsule::table('tbldomains')->whereRaw('LOWER(domain) = ?', [$normalized['domain']])->first();
        if ($domainRow) {
            $domainId = (int)$domainRow->id;
            $created = false;
        } else {
            if ($normalized['customer_id'] <= 0) {
                throw new \RuntimeException('LogicBoxes domain has no customer ID.');
            }
            $customerMap = $this->customers->mappingForUpstream($accountId, $normalized['customer_id']);
            if (!$customerMap) {
                throw new \RuntimeException('Import/map the upstream customer before importing this domain.');
            }
            $domainId = $this->createWhmcsDomain((int)$customerMap['whmcs_client_id'], $account, $normalized, $recalculatePricing);
            $created = true;
        }

        $this->storeMapping($accountId, $domainId, $normalized);
        $this->audit->write('domain.import', $accountId, 'domain', (string)$domainId, null, [
            'logicboxes_order_id' => $normalized['order_id'],
            'domain' => $normalized['domain'],
            'created' => $created,
        ], [], $this->adminId(), 'admin');
        return ['domain_id' => $domainId, 'whmcs_domain_id' => $domainId, 'domain' => $normalized['domain'], 'created' => $created];
    }

    public function refreshMapped(int $accountId, int $limit = 100): array
    {
        $account = $this->accounts->find($accountId, true) ?? throw new \RuntimeException('LogicBoxes account not found.');
        $api = $this->accounts->client($account);
        $rows = Capsule::table(Schema::TABLE_DOMAINS)->where('account_id', $accountId)->orderBy('id')->limit(max(1, $limit))->get();
        $stats = ['checked' => 0, 'updated' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            ++$stats['checked'];
            try {
                $details = $api->domainDetails((int)$row->logicboxes_order_id);
                $normalized = $this->normalizeDomain($details);
                $this->storeMapping($accountId, $row->whmcs_domain_id ? (int)$row->whmcs_domain_id : null, $normalized);
                if ($row->whmcs_domain_id) {
                    $this->syncWhmcsStatus((int)$row->whmcs_domain_id, $normalized);
                }
                ++$stats['updated'];
            } catch (\Throwable $e) {
                ++$stats['failed'];
                Capsule::table(Schema::TABLE_DOMAINS)->where('id', $row->id)->update([
                    'last_error' => substr($e->getMessage(), 0, 4000), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
        return $stats;
    }

    public function verificationQueue(int $accountId, int $page = 1, int $limit = 100): array
    {
        $pending = $this->discover($accountId, $page, $limit, ['status' => ['Pending Verification', 'Failed Verification']]);
        return array_values(array_filter($pending, static fn(array $row): bool =>
            in_array($row['status'], ['Pending Verification', 'Failed Verification'], true)
            || in_array($row['verification_status'], ['Pending', 'Suspended'], true)
        ));
    }

    public function moveDomainAndServices(int $accountId, string $domain, int $targetWhmcsClientId): array
    {
        $domain = Support::canonicalDomain($domain);
        $account = $this->accounts->find($accountId, true) ?? throw new \RuntimeException('LogicBoxes account not found.');
        $map = Capsule::table(Schema::TABLE_DOMAINS)->where('account_id', $accountId)->where('domain', $domain)->first();
        if (!$map || !$map->whmcs_domain_id || !$map->logicboxes_customer_id) {
            throw new \RuntimeException('Domain must be mapped before it can be moved.');
        }
        $targetMap = $this->customers->mappingForClient($accountId, $targetWhmcsClientId);
        if (!$targetMap) {
            throw new \RuntimeException('Target WHMCS client is not mapped to this LogicBoxes account.');
        }
        $domainRow = Capsule::table('tbldomains')->where('id', $map->whmcs_domain_id)->first();
        if (!$domainRow) {
            throw new \RuntimeException('Mapped WHMCS domain no longer exists.');
        }
        $sourceWhmcsClientId = (int)$domainRow->userid;
        if ($sourceWhmcsClientId === $targetWhmcsClientId) {
            throw new \RuntimeException('Source and target clients are the same.');
        }

        $api = $this->accounts->client($account);
        $api->moveProducts($domain, (int)$map->logicboxes_customer_id, (int)$targetMap['logicboxes_customer_id']);

        try {
            Capsule::connection()->transaction(function () use ($domainRow, $domain, $sourceWhmcsClientId, $targetWhmcsClientId): void {
                Capsule::table('tbldomains')->where('id', $domainRow->id)->where('userid', $sourceWhmcsClientId)->update(['userid' => $targetWhmcsClientId]);
                Capsule::table('tblhosting')->where('userid', $sourceWhmcsClientId)->whereRaw('LOWER(domain) = ?', [$domain])->update(['userid' => $targetWhmcsClientId]);
            });
        } catch (\Throwable $e) {
            try {
                $api->moveProducts($domain, (int)$targetMap['logicboxes_customer_id'], (int)$map->logicboxes_customer_id);
            } catch (\Throwable) {
                // Best-effort compensation only; audit below makes the split-brain visible.
            }
            $this->audit->write('domain.move_partial_failure', $accountId, 'domain', $domain, [
                'source_client_id' => $sourceWhmcsClientId,
            ], ['target_client_id' => $targetWhmcsClientId], ['error' => $e->getMessage()], $this->adminId(), 'admin');
            throw new \RuntimeException('LogicBoxes move succeeded but WHMCS update failed; compensation was attempted. ' . $e->getMessage());
        }

        Capsule::table(Schema::TABLE_DOMAINS)->where('id', $map->id)->update([
            'logicboxes_customer_id' => (int)$targetMap['logicboxes_customer_id'], 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit->write('domain.move', $accountId, 'domain', $domain, [
            'whmcs_client_id' => $sourceWhmcsClientId,
            'logicboxes_customer_id' => (int)$map->logicboxes_customer_id,
        ], [
            'whmcs_client_id' => $targetWhmcsClientId,
            'logicboxes_customer_id' => (int)$targetMap['logicboxes_customer_id'],
        ], [], $this->adminId(), 'admin');
        return ['domain' => $domain, 'source_client_id' => $sourceWhmcsClientId, 'target_client_id' => $targetWhmcsClientId];
    }

    public function normalizeSearchRows(array $payload): array
    {
        $rows = [];
        $walk = function (mixed $value) use (&$walk, &$rows): void {
            if (!is_array($value)) {
                return;
            }
            $normalized = $this->normalizeDomain($value);
            if ($normalized['order_id'] > 0 && $normalized['domain'] !== '') {
                $rows[(string)$normalized['order_id']] = $normalized;
                return;
            }
            foreach ($value as $item) {
                $walk($item);
            }
        };
        $walk($payload);
        return array_values($rows);
    }

    public function normalizeDomain(array $row): array
    {
        $orderId = (int)Support::firstValue($row, ['orderid', 'order-id', 'orders.orderid'], 0);
        $domain = (string)Support::firstValue($row, ['domainname', 'domain-name', 'description', 'entity.description'], '');
        $customerId = (int)Support::firstValue($row, ['customerid', 'customer-id', 'entity.customerid'], 0);
        $status = (string)Support::firstValue($row, ['currentstatus', 'status', 'entity.currentstatus'], '');
        $verification = (string)Support::firstValue($row, ['raaVerificationStatus', 'raa_verification_status', 'verificationstatus'], '');
        $productKey = (string)Support::firstValue($row, ['productkey', 'product-key', 'entitytype.entitytypekey'], '');
        return [
            'order_id' => $orderId,
            'domain' => Support::canonicalDomain($domain),
            'customer_id' => $customerId,
            'status' => $status,
            'verification_status' => $verification,
            'product_key' => $productKey,
            'creation_time' => (int)Support::firstValue($row, ['creationtime', 'creationdt', 'orders.creationtime', 'orders.creationdt'], 0),
            'expiry_time' => (int)Support::firstValue($row, ['endtime', 'expirytime', 'orders.endtime'], 0),
            'privacy' => Support::firstValue($row, ['privacyprotection', 'orders.privacyprotection'], null),
        ];
    }

    private function createWhmcsDomain(int $clientId, array $account, array $remote, bool $recalculatePricing): int
    {
        if (!class_exists('WHMCS\\Domain\\Domain')) {
            throw new \RuntimeException('WHMCS Domain model is unavailable.');
        }
        $model = new \WHMCS\Domain\Domain();
        $model->clientId = $clientId;
        $model->orderId = 0;
        $model->type = 'Register';
        $model->registrationDate = \WHMCS\Carbon::createFromTimestamp($remote['creation_time'] ?: time());
        $model->domain = $remote['domain'];
        $model->firstPaymentAmount = 0.0;
        $model->recurringAmount = 0.0;
        $model->registrarModuleName = (string)$account['registrar_module'];
        $model->registrationPeriod = 1;
        if ($remote['expiry_time'] > 0) {
            $model->expiryDate = \WHMCS\Carbon::createFromTimestamp($remote['expiry_time']);
            if (property_exists($model, 'nextDueDate') || method_exists($model, 'setAttribute')) {
                $model->nextDueDate = \WHMCS\Carbon::createFromTimestamp($remote['expiry_time']);
            }
        }
        $model->status = $this->whmcsStatus($remote['status']);
        $model->save();
        if ($recalculatePricing && method_exists($model, 'recalculateRecurringPrice')) {
            try {
                $model->recalculateRecurringPrice();
                $model->save();
            } catch (\Throwable $e) {
                $this->audit->write('domain.import_price_recalc_failed', (int)$account['id'], 'domain', (string)$model->id, null, null, ['error' => $e->getMessage()], $this->adminId(), 'admin');
            }
        }
        return (int)$model->id;
    }

    private function syncWhmcsStatus(int $domainId, array $remote): void
    {
        $row = Capsule::table('tbldomains')->where('id', $domainId)->first();
        if (!$row) {
            return;
        }
        $params = ['domainid' => $domainId];
        $desiredStatus = $this->whmcsStatus($remote['status']);
        if ($desiredStatus !== '' && $desiredStatus !== (string)$row->status) {
            $params['status'] = $desiredStatus;
        }
        if ($remote['expiry_time'] > 0) {
            $date = date('Y-m-d', $remote['expiry_time']);
            if ((string)$row->expirydate !== $date) {
                $params['expirydate'] = $date;
            }
        }
        if (count($params) === 1) {
            return;
        }
        $result = localAPI('UpdateClientDomain', $params);
        if (($result['result'] ?? '') !== 'success') {
            throw new \RuntimeException('WHMCS UpdateClientDomain failed: ' . ($result['message'] ?? 'unknown error'));
        }
    }

    private function whmcsStatus(string $upstream): string
    {
        return match (strtolower(trim($upstream))) {
            'active' => 'Active',
            'inactive' => 'Pending',
            'pending verification' => 'Active',
            'failed verification', 'suspended' => 'Active',
            'pending delete restorable' => 'Expired',
            'deleted', 'archived' => 'Cancelled',
            default => '',
        };
    }

    private function storeMapping(int $accountId, ?int $domainId, array $remote): void
    {
        $now = date('Y-m-d H:i:s');
        Capsule::table(Schema::TABLE_DOMAINS)->updateOrInsert(
            ['account_id' => $accountId, 'logicboxes_order_id' => $remote['order_id']],
            [
                'whmcs_domain_id' => $domainId,
                'logicboxes_customer_id' => $remote['customer_id'] ?: null,
                'domain' => $remote['domain'],
                'product_key' => $remote['product_key'] ?: null,
                'upstream_status' => $remote['status'] ?: null,
                'verification_status' => $remote['verification_status'] ?: null,
                'last_synced_at' => $now,
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function adminId(): ?int
    {
        return isset($_SESSION['adminid']) ? (int)$_SESSION['adminid'] : null;
    }
}
