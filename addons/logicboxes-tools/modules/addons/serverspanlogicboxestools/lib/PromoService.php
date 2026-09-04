<?php
namespace ServerSpan\LogicBoxesTools;

use WHMCS\Database\Capsule;

final class PromoService
{
    public function __construct(
        private readonly AccountRepository $accounts = new AccountRepository(),
        private readonly PricingEngine $engine = new PricingEngine(),
        private readonly JobRepository $jobs = new JobRepository(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {}

    public function refresh(int $accountId): array
    {
        $account = $this->accounts->find($accountId, true) ?? throw new \RuntimeException('LogicBoxes account not found.');
        $api = $this->accounts->client($account);
        $payload = $api->promotions();
        $details = $api->productDetails();
        $tldInfo = $api->tldInfo();
        $manualMap = (array)($account['options']['product_tld_map'] ?? []);
        $promos = $this->normalizePromotions($payload);
        $seen = [];
        $now = date('Y-m-d H:i:s');
        foreach ($promos as $promo) {
            $tld = $this->engine->resolveProductTld($promo['product_key'], $details, $manualMap, $tldInfo);
            $key = hash('sha256', implode('|', [
                $promo['product_key'], $promo['action_type'], (string)$promo['period'], (string)$promo['starts_at'], (string)$promo['ends_at'],
            ]));
            $seen[] = $key;
            Capsule::table(Schema::TABLE_PROMOS)->updateOrInsert(
                ['account_id' => $accountId, 'promo_key' => $key],
                [
                    'product_key' => $promo['product_key'],
                    'tld' => $tld,
                    'action_type' => $promo['action_type'],
                    'period' => $promo['period'],
                    'customer_price' => $promo['customer_price'],
                    'reseller_price' => $promo['reseller_price'],
                    'barrier_price' => $promo['barrier_price'],
                    'currency' => $promo['currency'],
                    'starts_at' => $promo['starts_at'],
                    'ends_at' => $promo['ends_at'],
                    'is_active' => $promo['is_active'] ? 1 : 0,
                    'raw_json' => Support::json(Support::redact($promo['raw'])),
                    'updated_at' => $now,
                ]
            );
        }
        if ($seen !== []) {
            Capsule::table(Schema::TABLE_PROMOS)->where('account_id', $accountId)->whereNotIn('promo_key', $seen)->update(['is_active' => 0, 'updated_at' => $now]);
        }
        $this->audit->write('promo.refresh', $accountId, 'account', (string)$accountId, null, ['promotions' => count($promos)], [], $this->adminId(), 'admin');
        return ['count' => count($promos), 'mapped' => count(array_filter($promos, function (array $p) use ($details, $manualMap, $tldInfo): bool {
            return $this->engine->resolveProductTld($p['product_key'], $details, $manualMap, $tldInfo) !== null;
        }))];
    }

    public function list(int $accountId, bool $activeOnly = false): array
    {
        $query = Capsule::table(Schema::TABLE_PROMOS)->where('account_id', $accountId)->orderByDesc('starts_at');
        if ($activeOnly) {
            $query->where('is_active', 1);
        }
        return array_map(static fn($r): array => (array)$r, $query->get()->all());
    }

    public function previewApply(int $promoId): array
    {
        $promoRow = Capsule::table(Schema::TABLE_PROMOS)->where('id', $promoId)->first();
        if (!$promoRow) {
            throw new \RuntimeException('Promotion not found.');
        }
        $promo = (array)$promoRow;
        if (!$promo['tld']) {
            throw new \RuntimeException('Promotion product key is not mapped to a TLD. Add a product_tld_map override to the account.');
        }
        if (!$promo['is_active']) {
            throw new \RuntimeException('Promotion is not currently active.');
        }
        if ($promo['customer_price'] === null) {
            throw new \RuntimeException('Promotion has no customer selling price.');
        }
        $account = $this->accounts->find((int)$promo['account_id']) ?? throw new \RuntimeException('Account not found.');
        $currency = Capsule::table('tblcurrencies')->where('code', strtoupper((string)$account['currency']))->first();
        if (!$currency) {
            throw new \RuntimeException('WHMCS currency is not configured.');
        }
        $current = localAPI('GetTLDPricing', ['currencyid' => (int)$currency->id]);
        if (($current['result'] ?? '') !== 'success') {
            throw new \RuntimeException('WHMCS GetTLDPricing failed.');
        }
        $key = ltrim((string)$promo['tld'], '.');
        $before = (array)(($current['pricing'] ?? [])[$key] ?? []);
        if ($before === []) {
            throw new \RuntimeException('TLD must already exist in WHMCS before applying a promotion.');
        }
        $action = $this->whmcsAction((string)$promo['action_type']);
        if ($action === null) {
            throw new \RuntimeException('Unsupported promotion action type: ' . $promo['action_type']);
        }
        $period = max(1, (int)($promo['period'] ?: 1));
        if ($action === 'transfer') {
            $period = array_key_first((array)($before['transfer'] ?? [])) ?: 1;
        }
        $price = round(((float)$promo['customer_price']) / max(1, (int)$account['multiplier']), 4);
        $proposed = [
            'extension' => '.' . $key,
            'auto_registrar' => (string)$account['registrar_module'],
            'currency_code' => strtoupper((string)$account['currency']),
            'register' => (array)($before['register'] ?? []),
            'renew' => (array)($before['renew'] ?? []),
            'transfer' => (array)($before['transfer'] ?? []),
            'group' => 'SALE',
        ];
        $proposed[$action][$period] = $price;
        $jobId = $this->jobs->create('promo_apply', 'preview', (int)$account['id'], ['promo_id' => $promoId, 'promo_key' => $promo['promo_key']], $this->adminId());
        $this->jobs->addItem($jobId, 'tld', '.' . $key, $before, $proposed);
        $this->jobs->recalculate($jobId);
        return ['job_id' => $jobId, 'tld' => '.' . $key, 'action' => $action, 'period' => $period, 'price' => $price];
    }

    public function apply(string $jobId): array
    {
        $job = $this->jobs->get($jobId) ?? throw new \RuntimeException('Promotion job not found.');
        if ($job['type'] !== 'promo_apply') {
            throw new \RuntimeException('Not a promotion apply job.');
        }
        $this->jobs->start($jobId);
        foreach ($this->jobs->pendingItems($jobId, 20) as $item) {
            try {
                $payload = Support::fromJson($item['proposed_json'] ?? null);
                $result = localAPI('CreateOrUpdateTLD', $payload);
                if (($result['result'] ?? '') !== 'success') {
                    throw new \RuntimeException($result['message'] ?? 'CreateOrUpdateTLD failed.');
                }
                $this->jobs->itemApplied((int)$item['id'], ['result' => 'success']);
            } catch (\Throwable $e) {
                $this->jobs->itemFailed((int)$item['id'], $e->getMessage());
            }
        }
        $this->jobs->recalculate($jobId);
        return $this->jobs->get($jobId) ?? [];
    }

    public function normalizePromotions(array $payload): array
    {
        $rows = [];
        $walk = function (mixed $value) use (&$walk, &$rows): void {
            if (!is_array($value)) {
                return;
            }
            $product = (string)Support::firstValue($value, ['productkey', 'product-key'], '');
            $action = (string)Support::firstValue($value, ['actiontype', 'action-type'], '');
            if ($product !== '' && $action !== '') {
                $rows[] = [
                    'product_key' => $product,
                    'action_type' => $action,
                    'period' => ($v = Support::firstValue($value, ['period'], null)) === null ? null : (int)$v,
                    'customer_price' => ($v = Support::firstValue($value, ['customerprice'], null)) === null ? null : (float)$v,
                    'reseller_price' => ($v = Support::firstValue($value, ['resellerprice'], null)) === null ? null : (float)$v,
                    'barrier_price' => ($v = Support::firstValue($value, ['barrierprice'], null)) === null ? null : (float)$v,
                    'currency' => (string)Support::firstValue($value, ['serviceprovidersellingcurrency', 'resellerpricecurrencysymbol'], ''),
                    'starts_at' => $this->apiTime(Support::firstValue($value, ['starttime'], null)),
                    'ends_at' => $this->apiTime(Support::firstValue($value, ['endtime'], null)),
                    'is_active' => Support::bool(Support::firstValue($value, ['isactive'], false)),
                    'raw' => $value,
                ];
                return;
            }
            foreach ($value as $item) {
                $walk($item);
            }
        };
        $walk($payload);
        return $rows;
    }

    private function whmcsAction(string $action): ?string
    {
        $a = strtolower(preg_replace('/[^a-z]/i', '', $action) ?? '');
        return match ($a) {
            'addnewdomain', 'register', 'registration' => 'register',
            'renewdomain', 'renew', 'renewal' => 'renew',
            'addtransferdomain', 'transfer' => 'transfer',
            default => null,
        };
    }

    private function apiTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $n = (int)$value;
            if ($n > 1000000000000) {
                $n = intdiv($n, 1000);
            }
            return $n > 0 ? date('Y-m-d H:i:s', $n) : null;
        }
        $ts = strtotime((string)$value);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    private function adminId(): ?int
    {
        return isset($_SESSION['adminid']) ? (int)$_SESSION['adminid'] : null;
    }
}
