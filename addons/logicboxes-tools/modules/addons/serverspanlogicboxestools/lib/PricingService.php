<?php
namespace ServerSpan\LogicBoxesTools;

use WHMCS\Database\Capsule;

final class PricingService
{
    public function __construct(
        private readonly AccountRepository $accounts = new AccountRepository(),
        private readonly JobRepository $jobs = new JobRepository(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly PricingEngine $engine = new PricingEngine(),
    ) {}

    public function previewTldSync(int $accountId, array $policy): array
    {
        $account = $this->accounts->find($accountId, true) ?? throw new \RuntimeException('LogicBoxes account not found.');
        $api = $this->accounts->client($account);
        $source = strtolower((string)($policy['source'] ?? 'customer'));
        if (!in_array($source, ['customer', 'cost'], true)) {
            throw new \InvalidArgumentException('Pricing source must be customer or cost.');
        }
        $remote = $source === 'cost' ? $api->resellerCostPricing() : $api->customerPricing();
        $matrices = $this->engine->extractDomainMatrices($remote);
        $productDetails = $api->productDetails();
        $tldInfo = $api->tldInfo();
        $manualMap = array_merge($this->learnProductTldMap($api, $tldInfo), (array)($account['options']['product_tld_map'] ?? []));

        $currency = Capsule::table('tblcurrencies')->where('code', strtoupper((string)$account['currency']))->first();
        if (!$currency) {
            throw new \RuntimeException('WHMCS does not have the account currency ' . $account['currency'] . ' configured.');
        }
        $current = localAPI('GetTLDPricing', ['currencyid' => (int)$currency->id]);
        if (($current['result'] ?? '') !== 'success') {
            throw new \RuntimeException('WHMCS GetTLDPricing failed: ' . ($current['message'] ?? 'unknown error'));
        }
        $currentPricing = (array)($current['pricing'] ?? []);

        $jobId = $this->jobs->create('tld_price_sync', 'preview', $accountId, ['policy' => $this->safePolicy($policy), 'source' => $source], $this->adminId());
        $unresolved = [];
        $created = 0;
        foreach ($matrices as $productKey => $matrix) {
            $tld = $this->engine->resolveProductTld((string)$productKey, $productDetails, $manualMap, $tldInfo);
            if ($tld === null) {
                $unresolved[] = $productKey;
                continue;
            }
            $selling = $this->engine->buildSellingMatrix($matrix, $policy, (int)$account['multiplier']);
            if ($selling['register'] === [] && $selling['renew'] === [] && $selling['transfer'] === []) {
                continue;
            }
            $proposed = $this->engine->buildWhmcsPayload($tld, $selling, $account, $tldInfo);
            $key = ltrim($tld, '.');
            $before = isset($currentPricing[$key]) ? (array)$currentPricing[$key] : null;
            $this->jobs->addItem($jobId, 'tld', $tld, $before, $proposed);
            ++$created;
        }
        $this->jobs->recalculate($jobId);
        $this->audit->write('pricing.tld_preview', $accountId, 'job', $jobId, null, ['items' => $created], ['unresolved_product_keys' => $unresolved, 'policy' => $this->safePolicy($policy)], $this->adminId(), 'admin');
        return ['job_id' => $jobId, 'items' => $created, 'unresolved_product_keys' => $unresolved];
    }

    public function applyTldJob(string $jobId, int $limit = 500): array
    {
        $job = $this->jobs->get($jobId) ?? throw new \RuntimeException('Pricing job not found.');
        if ($job['type'] !== 'tld_price_sync' || !in_array($job['status'], ['queued', 'running', 'partial'], true)) {
            throw new \RuntimeException('Job is not an applicable TLD pricing preview.');
        }
        $this->jobs->start($jobId);
        foreach ($this->jobs->pendingItems($jobId, max(1, min(1000, $limit))) as $item) {
            try {
                $payload = Support::fromJson($item['proposed_json'] ?? null);
                $result = localAPI('CreateOrUpdateTLD', $payload);
                if (($result['result'] ?? '') !== 'success') {
                    throw new \RuntimeException($result['message'] ?? 'CreateOrUpdateTLD returned an error.');
                }
                $this->jobs->itemApplied((int)$item['id'], ['result' => 'success', 'extension' => $payload['extension'] ?? $item['entity_key']]);
            } catch (\Throwable $e) {
                $this->jobs->itemFailed((int)$item['id'], $e->getMessage());
            }
        }
        $this->jobs->recalculate($jobId);
        $result = $this->jobs->get($jobId) ?? [];
        $this->audit->write('pricing.tld_apply', (int)$job['account_id'], 'job', $jobId, null, ['status' => $result['status'] ?? null], [], $this->adminId(), 'admin');
        return $result;
    }

    public function previewTldRollback(string $sourceJobId): array
    {
        $source = $this->jobs->get($sourceJobId) ?? throw new \RuntimeException('Source job not found.');
        if ($source['type'] !== 'tld_price_sync') {
            throw new \RuntimeException('Only TLD price sync jobs can be rolled back here.');
        }
        $rollbackId = $this->jobs->create('tld_price_rollback', 'preview', (int)$source['account_id'], ['source_job_id' => $sourceJobId], $this->adminId());
        $items = Capsule::table(Schema::TABLE_JOB_ITEMS)->where('job_id', $sourceJobId)->where('status', 'applied')->orderBy('id')->get();
        $count = 0;
        foreach ($items as $item) {
            $before = Support::fromJson($item->before_json);
            if ($before === []) {
                continue;
            }
            $restore = $this->restorePayload((string)$item->entity_key, $before, (int)$source['account_id']);
            $this->jobs->addItem($rollbackId, 'tld', (string)$item->entity_key, Support::fromJson($item->proposed_json), $restore);
            ++$count;
        }
        $this->jobs->recalculate($rollbackId);
        return ['job_id' => $rollbackId, 'items' => $count];
    }

    public function applyTldRollback(string $jobId): array
    {
        $job = $this->jobs->get($jobId) ?? throw new \RuntimeException('Rollback job not found.');
        if ($job['type'] !== 'tld_price_rollback') {
            throw new \RuntimeException('Not a TLD rollback job.');
        }
        $this->jobs->start($jobId);
        foreach ($this->jobs->pendingItems($jobId, 1000) as $item) {
            try {
                $payload = Support::fromJson($item['proposed_json'] ?? null);
                $result = localAPI('CreateOrUpdateTLD', $payload);
                if (($result['result'] ?? '') !== 'success') {
                    throw new \RuntimeException($result['message'] ?? 'CreateOrUpdateTLD returned an error.');
                }
                $this->jobs->itemApplied((int)$item['id'], ['result' => 'success']);
            } catch (\Throwable $e) {
                $this->jobs->itemFailed((int)$item['id'], $e->getMessage());
            }
        }
        $this->jobs->recalculate($jobId);
        return $this->jobs->get($jobId) ?? [];
    }

    public function previewRecurringSync(int $accountId, array $options = []): array
    {
        $account = $this->accounts->find($accountId) ?? throw new \RuntimeException('LogicBoxes account not found.');
        $includeZero = Support::bool($options['include_zero'] ?? false);
        $statuses = (array)($options['statuses'] ?? ['Active', 'Pending Transfer']);
        $limit = max(1, min(10000, (int)($options['limit'] ?? 5000)));
        $query = Capsule::table('tbldomains')->where('registrar', (string)$account['registrar_module'])->whereIn('status', $statuses)->orderBy('id')->limit($limit);
        if (!$includeZero) {
            $query->where('recurringamount', '>', 0);
        }
        $domains = $query->get();
        $jobId = $this->jobs->create('domain_recurring_sync', 'preview', $accountId, ['include_zero' => $includeZero, 'statuses' => $statuses], $this->adminId());
        $clientPricing = [];
        $created = 0;
        $skipped = 0;
        foreach ($domains as $domain) {
            $clientId = (int)$domain->userid;
            if (!isset($clientPricing[$clientId])) {
                $result = localAPI('GetTLDPricing', ['clientid' => $clientId]);
                if (($result['result'] ?? '') !== 'success') {
                    ++$skipped;
                    continue;
                }
                $clientPricing[$clientId] = (array)($result['pricing'] ?? []);
            }
            $pricing = $clientPricing[$clientId];
            $tld = $this->engine->findTldForDomain((string)$domain->domain, array_keys($pricing));
            if ($tld === null) {
                ++$skipped;
                continue;
            }
            $entry = (array)($pricing[ltrim($tld, '.')] ?? []);
            $period = max(1, (int)$domain->registrationperiod);
            $renew = $this->engine->priceForPeriod($entry, 'renew', $period);
            if ($renew === null) {
                ++$skipped;
                continue;
            }
            if (abs((float)$domain->recurringamount - $renew) < 0.00001) {
                ++$skipped;
                continue;
            }
            $this->jobs->addItem($jobId, 'domain', (string)$domain->id, [
                'domainid' => (int)$domain->id,
                'domain' => (string)$domain->domain,
                'userid' => $clientId,
                'regperiod' => $period,
                'recurringamount' => (float)$domain->recurringamount,
            ], [
                'domainid' => (int)$domain->id,
                'recurringamount' => $renew,
                'autorecalc' => false,
            ]);
            ++$created;
        }
        $this->jobs->recalculate($jobId);
        $this->audit->write('pricing.recurring_preview', $accountId, 'job', $jobId, null, ['items' => $created, 'skipped' => $skipped], [], $this->adminId(), 'admin');
        return ['job_id' => $jobId, 'items' => $created, 'skipped' => $skipped];
    }

    public function applyRecurringJob(string $jobId, int $limit = 1000): array
    {
        $job = $this->jobs->get($jobId) ?? throw new \RuntimeException('Recurring price job not found.');
        if (!in_array($job['type'], ['domain_recurring_sync', 'domain_recurring_rollback'], true)) {
            throw new \RuntimeException('Not a recurring price job.');
        }
        $this->jobs->start($jobId);
        foreach ($this->jobs->pendingItems($jobId, max(1, min(5000, $limit))) as $item) {
            try {
                $payload = Support::fromJson($item['proposed_json'] ?? null);
                $result = localAPI('UpdateClientDomain', $payload);
                if (($result['result'] ?? '') !== 'success') {
                    throw new \RuntimeException($result['message'] ?? 'UpdateClientDomain returned an error.');
                }
                $this->jobs->itemApplied((int)$item['id'], ['result' => 'success', 'domainid' => $payload['domainid'] ?? null]);
            } catch (\Throwable $e) {
                $this->jobs->itemFailed((int)$item['id'], $e->getMessage());
            }
        }
        $this->jobs->recalculate($jobId);
        return $this->jobs->get($jobId) ?? [];
    }

    public function previewRecurringRollback(string $sourceJobId): array
    {
        $source = $this->jobs->get($sourceJobId) ?? throw new \RuntimeException('Source job not found.');
        if ($source['type'] !== 'domain_recurring_sync') {
            throw new \RuntimeException('Not a recurring sync job.');
        }
        $rollbackId = $this->jobs->create('domain_recurring_rollback', 'preview', (int)$source['account_id'], ['source_job_id' => $sourceJobId], $this->adminId());
        $items = Capsule::table(Schema::TABLE_JOB_ITEMS)->where('job_id', $sourceJobId)->where('status', 'applied')->orderBy('id')->get();
        $count = 0;
        foreach ($items as $item) {
            $before = Support::fromJson($item->before_json);
            if (!isset($before['domainid'], $before['recurringamount'])) {
                continue;
            }
            $this->jobs->addItem($rollbackId, 'domain', (string)$before['domainid'], Support::fromJson($item->proposed_json), [
                'domainid' => (int)$before['domainid'],
                'recurringamount' => (float)$before['recurringamount'],
                'autorecalc' => false,
            ]);
            ++$count;
        }
        $this->jobs->recalculate($rollbackId);
        return ['job_id' => $rollbackId, 'items' => $count];
    }

    private function restorePayload(string $tld, array $before, int $accountId): array
    {
        $account = $this->accounts->find($accountId) ?? throw new \RuntimeException('Account not found.');
        $payload = [
            'extension' => str_starts_with($tld, '.') ? $tld : '.' . $tld,
            'auto_registrar' => (string)$account['registrar_module'],
            'currency_code' => strtoupper((string)$account['currency']),
        ];
        foreach (['register', 'renew', 'transfer'] as $key) {
            if (isset($before[$key]) && is_array($before[$key])) {
                $payload[$key] = $before[$key];
            }
        }
        if (isset($before['addons']) && is_array($before['addons'])) {
            $payload['dns_management'] = Support::bool($before['addons']['dns'] ?? false);
            $payload['email_forwarding'] = Support::bool($before['addons']['email'] ?? false);
            $payload['id_protection'] = Support::bool($before['addons']['idprotect'] ?? false);
        }
        $payload['group'] = (string)($before['group'] ?? '');
        if (isset($before['redemption_period']) && is_array($before['redemption_period'])) {
            $payload['redemption_period_days'] = (int)($before['redemption_period']['days'] ?? 0);
            $payload['redemption_period_fee'] = (float)($before['redemption_period']['price'] ?? -1);
        }
        if (isset($before['grace_period']) && is_array($before['grace_period'])) {
            $payload['grace_period_days'] = (int)($before['grace_period']['days'] ?? 0);
            $payload['grace_period_fee'] = (float)($before['grace_period']['price'] ?? -1);
        }
        return $payload;
    }

    private function safePolicy(array $policy): array
    {
        return array_intersect_key($policy, array_flip(['source', 'margin_type', 'margin', 'round_to', 'round_mode']));
    }

    private function learnProductTldMap(ApiClient $api, array $tldInfo): array
    {
        $tlds = [];
        foreach ($tldInfo as $key => $value) {
            $candidate = is_string($key) && !is_numeric($key) ? $key : (is_string($value) ? $value : '');
            if ($candidate !== '') $tlds[] = $candidate;
        }
        if ($tlds === []) return [];
        $map = [];
        $conflicts = [];
        for ($page = 1; $page <= 2; ++$page) {
            try {
                $payload = $api->searchDomains($page, 500);
            } catch (\Throwable) {
                break;
            }
            $rows = Support::flattenRows($payload, 'orderid');
            if ($rows === []) break;
            foreach ($rows as $row) {
                $product = (string)Support::firstValue($row, ['productkey','product-key','entitytype.entitytypekey'], '');
                $domain = (string)Support::firstValue($row, ['domainname','domain-name','description','entity.description'], '');
                if ($product === '' || $domain === '') continue;
                $tld = $this->engine->findTldForDomain($domain, $tlds);
                if ($tld === null) continue;
                if (isset($map[$product]) && $map[$product] !== $tld) {
                    $conflicts[$product] = true;
                    unset($map[$product]);
                } elseif (!isset($conflicts[$product])) {
                    $map[$product] = $tld;
                }
            }
            if (count($rows) < 500) break;
        }
        return $map;
    }

    private function adminId(): ?int
    {
        return isset($_SESSION['adminid']) ? (int)$_SESSION['adminid'] : null;
    }
}
