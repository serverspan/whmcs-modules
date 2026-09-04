<?php
namespace ServerSpan\LogicBoxesTools;

use WHMCS\Database\Capsule;

final class AutomationService
{
    public function __construct(
        private readonly AccountRepository $accounts = new AccountRepository(),
        private readonly LockRepository $locks = new LockRepository(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {}

    public function runDaily(): array
    {
        $owner = 'cron-' . bin2hex(random_bytes(8));
        if (!$this->locks->acquire('daily', $owner, 3300)) {
            return ['status' => 'locked', 'accounts' => 0];
        }
        $processed = 0;
        $errors = [];
        try {
            foreach ($this->accounts->all(true) as $row) {
                $accountId = (int)$row['id'];
                try {
                    $this->runAccount($accountId);
                    ++$processed;
                } catch (\Throwable $e) {
                    $errors[$accountId] = $e->getMessage();
                    $this->accounts->markHealth($accountId, false, $e->getMessage());
                    $this->audit->write('cron.account_failed', $accountId, 'account', (string)$accountId, null, null, ['error' => $e->getMessage()], null, 'cron');
                }
            }
        } finally {
            $this->locks->release('daily', $owner);
        }
        return ['status' => $errors === [] ? 'success' : 'partial', 'accounts' => $processed, 'errors' => $errors];
    }

    public function runAccount(int $accountId): array
    {
        $account = $this->accounts->find($accountId, true) ?? throw new \RuntimeException('LogicBoxes account not found.');
        $api = $this->accounts->client($account);
        $api->ping();
        $this->accounts->markHealth($accountId, true);
        $result = ['health' => 'ok'];

        try {
            $balance = $api->resellerBalance();
            $result['balance'] = Support::redact($balance);
            $this->checkFundsThreshold($account, $balance);
        } catch (\Throwable $e) {
            $result['balance_error'] = $e->getMessage();
        }

        if ((bool)$account['auto_promo_sync']) {
            $result['promos'] = (new PromoService($this->accounts))->refresh($accountId);
        }
        if ((bool)$account['auto_transfer_sync']) {
            $customers = new CustomerService($this->accounts, $this->audit);
            $result['domains'] = (new DomainService($this->accounts, $customers, $this->audit))->refreshMapped($accountId, 250);
            $transfers = new TransferService($this->accounts, $this->audit);
            $result['transfers'] = $transfers->checkPending($accountId, 250);
            $result['verification_report'] = $transfers->sendVerificationReport($accountId, 500);
        }

        $options = (array)$account['options'];
        $allowFinancial = Support::bool($options['allow_unattended_financial_writes'] ?? false);
        if ((bool)$account['auto_price_sync']) {
            if (!$allowFinancial || !isset($options['scheduled_price_policy']) || !is_array($options['scheduled_price_policy'])) {
                $result['price_sync'] = 'skipped: unattended financial writes are not explicitly authorized with a scheduled policy';
            } else {
                $pricing = new PricingService($this->accounts);
                $preview = $pricing->previewTldSync($accountId, $options['scheduled_price_policy']);
                $result['price_sync'] = $preview['items'] > 0 ? $pricing->applyTldJob($preview['job_id'], 1000) : $preview;
            }
        }
        if ((bool)$account['auto_recurring_sync']) {
            if (!$allowFinancial) {
                $result['recurring_sync'] = 'skipped: unattended financial writes are not explicitly authorized';
            } else {
                $pricing = new PricingService($this->accounts);
                $preview = $pricing->previewRecurringSync($accountId, [
                    'include_zero' => Support::bool($options['recurring_include_zero'] ?? false),
                    'limit' => max(1, min(10000, (int)($options['recurring_daily_limit'] ?? 5000))),
                ]);
                $result['recurring_sync'] = $preview['items'] > 0 ? $pricing->applyRecurringJob($preview['job_id'], 5000) : $preview;
            }
        }

        $this->audit->write('cron.account_complete', $accountId, 'account', (string)$accountId, null, null, ['result' => $result], null, 'cron');
        return $result;
    }

    private function checkFundsThreshold(array $account, array $balance): void
    {
        if ($account['fund_threshold'] === null) {
            return;
        }
        $available = Support::firstValue($balance, ['sellingcurrencybalance', 'availablebalance', 'balance'], null);
        if (!is_numeric($available) || (float)$available >= (float)$account['fund_threshold']) {
            return;
        }
        $accountId = (int)$account['id'];
        $already = Capsule::table(Schema::TABLE_AUDIT)
            ->where('account_id', $accountId)
            ->where('action', 'funds.threshold_alert')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))
            ->exists();
        if ($already) {
            return;
        }
        $availableValue = (float)$available;
        $this->audit->write('funds.threshold_alert', $accountId, 'account', (string)$accountId, null, ['available' => $availableValue, 'threshold' => (float)$account['fund_threshold']], [], null, 'cron');
        $subject = '[ServerSpan LogicBoxes] Low funds: ' . (string)$account['name'];
        $message = '<p>The available LogicBoxes reseller balance is below the configured threshold.</p>'
            . '<p><strong>Account:</strong> ' . Support::e($account['name']) . '<br>'
            . '<strong>Available:</strong> ' . Support::e((string)$availableValue) . ' ' . Support::e((string)$account['currency']) . '<br>'
            . '<strong>Threshold:</strong> ' . Support::e((string)$account['fund_threshold']) . ' ' . Support::e((string)$account['currency']) . '</p>';
        try {
            localAPI('SendAdminEmail', ['customsubject' => $subject, 'custommessage' => $message, 'type' => 'system']);
        } catch (\Throwable) {
            // Audit entry is the durable alert even when mail delivery is unavailable.
        }
    }
}
