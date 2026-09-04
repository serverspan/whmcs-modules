<?php
namespace ServerSpan\LogicBoxesTools;

use WHMCS\Database\Capsule;

final class Controller
{
    private AccountRepository $accounts;
    private CustomerService $customers;
    private DomainService $domains;
    private PricingService $pricing;
    private PromoService $promos;
    private JobRepository $jobs;
    private AuditLogger $audit;
    private Renderer $renderer;

    public function __construct()
    {
        $this->accounts = new AccountRepository();
        $this->audit = new AuditLogger();
        $this->customers = new CustomerService($this->accounts, $this->audit);
        $this->domains = new DomainService($this->accounts, $this->customers, $this->audit);
        $this->pricing = new PricingService($this->accounts, new JobRepository(), $this->audit);
        $this->promos = new PromoService($this->accounts, new PricingEngine(), new JobRepository(), $this->audit);
        $this->jobs = new JobRepository();
        $this->renderer = new Renderer();
    }

    public function output(array $vars): string
    {
        $flash = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                $this->verifyCsrf();
                $flash = ['type' => 'success', 'message' => $this->handlePost($_POST)];
            } catch (\Throwable $e) {
                $flash = ['type' => 'danger', 'message' => $e->getMessage()];
            }
        }
        $view = preg_replace('/[^a-z_]/', '', (string)($_GET['view'] ?? 'dashboard')) ?: 'dashboard';
        $data = $this->dataFor($view);
        $data['view'] = $view;
        $data['modulelink'] = (string)($vars['modulelink'] ?? 'addonmodules.php?module=serverspanlogicboxestools');
        $data['token'] = function_exists('generate_token') ? generate_token('plain') : '';
        $data['flash'] = $flash;
        return $this->renderer->admin($data);
    }

    private function handlePost(array $post): string
    {
        $action = (string)($post['ss_action'] ?? '');
        return match ($action) {
            'save_account' => $this->saveAccount($post),
            'delete_account' => $this->deleteAccount($post),
            'test_account' => $this->testAccount($post),
            'export_customer' => $this->exportCustomer($post),
            'import_customer' => $this->importCustomer($post),
            'import_domain' => $this->importDomain($post),
            'refresh_domains' => $this->refreshDomains($post),
            'move_domain' => $this->moveDomain($post),
            'preview_tld_pricing' => $this->previewTldPricing($post),
            'apply_tld_job' => $this->applyTldJob($post),
            'preview_tld_rollback' => $this->previewTldRollback($post),
            'apply_tld_rollback' => $this->applyTldRollback($post),
            'preview_recurring' => $this->previewRecurring($post),
            'apply_recurring' => $this->applyRecurring($post),
            'preview_recurring_rollback' => $this->previewRecurringRollback($post),
            'refresh_promos' => $this->refreshPromos($post),
            'preview_promo' => $this->previewPromo($post),
            'apply_promo' => $this->applyPromo($post),
            'run_account_automation' => $this->runAccountAutomation($post),
            default => throw new \InvalidArgumentException('Unknown or missing action.'),
        };
    }

    private function dataFor(string $view): array
    {
        $accountId = max(0, (int)($_GET['account_id'] ?? 0));
        $data = [
            'accounts' => $this->accounts->all(),
            'account' => $accountId ? $this->accounts->find($accountId) : null,
        ];
        if ($view === 'dashboard') {
            $data['counts'] = [
                'accounts' => Capsule::table(Schema::TABLE_ACCOUNTS)->count(),
                'customers' => Capsule::table(Schema::TABLE_CUSTOMERS)->count(),
                'domains' => Capsule::table(Schema::TABLE_DOMAINS)->count(),
                'active_promos' => Capsule::table(Schema::TABLE_PROMOS)->where('is_active', 1)->count(),
            ];
            $data['jobs'] = $this->jobs->recent(10);
            $data['audit'] = $this->recentAudit(10);
        } elseif ($view === 'customers') {
            $query = Capsule::table(Schema::TABLE_CUSTOMERS)->orderByDesc('updated_at')->limit(200);
            if ($accountId) $query->where('account_id', $accountId);
            $data['mappings'] = array_map(static fn($r): array => (array)$r, $query->get()->all());
        } elseif ($view === 'domains') {
            $query = Capsule::table(Schema::TABLE_DOMAINS)->orderByDesc('updated_at')->limit(250);
            if ($accountId) $query->where('account_id', $accountId);
            $data['mappings'] = array_map(static fn($r): array => (array)$r, $query->get()->all());
        } elseif ($view === 'promos') {
            $data['promos'] = $accountId ? $this->promos->list($accountId) : [];
        } elseif ($view === 'jobs') {
            $data['jobs'] = $this->jobs->recent(100);
            $jobId = preg_replace('/[^a-f0-9-]/i', '', (string)($_GET['job_id'] ?? ''));
            $data['selected_job'] = $jobId ? $this->jobs->get($jobId) : null;
            $data['job_items'] = $jobId ? array_map(static fn($r): array => (array)$r, Capsule::table(Schema::TABLE_JOB_ITEMS)->where('job_id', $jobId)->orderBy('id')->limit(1000)->get()->all()) : [];
        } elseif ($view === 'audit') {
            $data['audit'] = $this->recentAudit(250, $accountId ?: null);
        }
        return $data;
    }

    private function saveAccount(array $post): string
    {
        $id = (int)($post['account_id'] ?? 0) ?: null;
        $existing = $id ? ($this->accounts->find($id) ?? throw new \RuntimeException('Account not found.')) : null;
        $options = (array)($existing['options'] ?? []);
        $options['accept_policy_for_auto_signup'] = Support::bool($post['accept_policy_for_auto_signup'] ?? false);
        $options['allow_orderbox_sso'] = Support::bool($post['allow_orderbox_sso'] ?? false);
        $options['allow_client_domain_move'] = Support::bool($post['allow_client_domain_move'] ?? false);
        $options['transfer_send_confirmation'] = Support::bool($post['transfer_send_confirmation'] ?? false);
        $options['proactive_transfer_mail'] = Support::bool($post['proactive_transfer_mail'] ?? false);
        $options['cancel_broken_transfers'] = Support::bool($post['cancel_broken_transfers'] ?? false);
        $options['raa_daily_report'] = Support::bool($post['raa_daily_report'] ?? false);
        $options['allow_unattended_financial_writes'] = Support::bool($post['allow_unattended_financial_writes'] ?? false);
        $options['auto_apply_active_promos'] = Support::bool($post['auto_apply_active_promos'] ?? false);
        $options['control_panel_url'] = rtrim((string)($post['control_panel_url'] ?? ($options['control_panel_url'] ?? 'https://manage.resellerclub.com')), '/');
        if (!preg_match('#^https://#i', $options['control_panel_url'])) {
            throw new \InvalidArgumentException('Control panel URL must use HTTPS.');
        }
        $map = [];
        foreach (preg_split('/\R/', (string)($post['product_tld_map'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            [$key, $value] = array_pad(array_map('trim', explode('=', $line, 2)), 2, '');
            if ($key !== '' && preg_match('/^\.[a-z0-9.-]+$/i', $value)) $map[$key] = strtolower($value);
        }
        $options['product_tld_map'] = $map;
        $options['scheduled_price_policy'] = [
            'source' => in_array(($post['scheduled_source'] ?? 'customer'), ['customer', 'cost'], true) ? $post['scheduled_source'] : 'customer',
            'margin_type' => in_array(($post['scheduled_margin_type'] ?? 'percent'), ['percent', 'fixed'], true) ? $post['scheduled_margin_type'] : 'percent',
            'margin' => (float)($post['scheduled_margin'] ?? 0),
            'round_to' => max(0.0001, (float)($post['scheduled_round_to'] ?? 0.01)),
            'round_mode' => in_array(($post['scheduled_round_mode'] ?? 'nearest'), ['nearest', 'up', 'down'], true) ? $post['scheduled_round_mode'] : 'nearest',
        ];
        $nameservers = array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', (string)($post['nameservers'] ?? '')) ?: [])));
        $saved = $this->accounts->save([
            'name' => $post['name'] ?? '', 'registrar_module' => $post['registrar_module'] ?? 'resellerclub', 'reseller_id' => $post['reseller_id'] ?? 0,
            'api_key' => $post['api_key'] ?? '', 'base_url' => $post['base_url'] ?? 'https://httpapi.com/api', 'currency' => $post['currency'] ?? 'USD',
            'multiplier' => $post['multiplier'] ?? 1, 'nameservers' => $nameservers, 'fund_threshold' => $post['fund_threshold'] ?? '', 'enabled' => $post['enabled'] ?? false,
            'auto_customer_signup' => $post['auto_customer_signup'] ?? false, 'auto_customer_modify' => $post['auto_customer_modify'] ?? false,
            'auto_customer_delete' => $post['auto_customer_delete'] ?? false, 'auto_price_sync' => $post['auto_price_sync'] ?? false,
            'auto_promo_sync' => $post['auto_promo_sync'] ?? false, 'auto_transfer_sync' => $post['auto_transfer_sync'] ?? false,
            'auto_recurring_sync' => $post['auto_recurring_sync'] ?? false, 'options' => $options,
        ], $id);
        $this->audit->write($id ? 'account.update' : 'account.create', $saved, 'account', (string)$saved, $existing, ['name' => $post['name'] ?? ''], [], $this->adminId(), 'admin');
        return 'LogicBoxes account saved as #' . $saved . '.';
    }

    private function deleteAccount(array $post): string
    {
        if (($post['confirm'] ?? '') !== 'DELETE') throw new \RuntimeException('Type DELETE to confirm account deletion.');
        $id = (int)($post['account_id'] ?? 0);
        $this->accounts->delete($id);
        return 'Account deleted.';
    }

    private function testAccount(array $post): string
    {
        $id = (int)($post['account_id'] ?? 0);
        $account = $this->accounts->find($id, true) ?? throw new \RuntimeException('Account not found.');
        try {
            $this->accounts->client($account)->ping();
            $this->accounts->markHealth($id, true);
        } catch (\Throwable $e) {
            $this->accounts->markHealth($id, false, $e->getMessage());
            throw $e;
        }
        return 'API connection successful.';
    }

    private function exportCustomer(array $post): string
    {
        $r = $this->customers->exportClient((int)$post['account_id'], (int)$post['client_id'], Support::bool($post['accept_policy'] ?? false));
        return 'Customer synchronized. LogicBoxes customer ID: ' . $r['customer_id'] . '.';
    }

    private function importCustomer(array $post): string
    {
        $r = $this->customers->importCustomer((int)$post['account_id'], (int)$post['logicboxes_customer_id'], ($post['currency_id'] ?? '') === '' ? null : (int)$post['currency_id']);
        return 'Customer mapped to WHMCS client #' . $r['client_id'] . '.';
    }

    private function importDomain(array $post): string
    {
        $r = $this->domains->importOrder((int)$post['account_id'], (int)$post['order_id'], Support::bool($post['recalculate_price'] ?? true));
        return 'Domain imported/mapped: ' . $r['domain'] . ' (WHMCS domain #' . $r['whmcs_domain_id'] . ').';
    }

    private function refreshDomains(array $post): string
    {
        $r = $this->domains->refreshMapped((int)$post['account_id'], max(1, min(1000, (int)($post['limit'] ?? 250))));
        return 'Checked ' . $r['checked'] . ' mapped domains; ' . $r['updated'] . ' updated, ' . $r['failed'] . ' failed.';
    }

    private function moveDomain(array $post): string
    {
        if (($post['confirm'] ?? '') !== 'MOVE') throw new \RuntimeException('Type MOVE to confirm domain/service ownership change.');
        $r = $this->domains->moveDomainAndServices((int)$post['account_id'], (string)$post['domain'], (int)$post['target_client_id']);
        return 'Moved ' . $r['domain'] . ' to WHMCS client #' . $r['target_client_id'] . '.';
    }

    private function previewTldPricing(array $post): string
    {
        $r = $this->pricing->previewTldSync((int)$post['account_id'], [
            'source' => $post['source'] ?? 'customer', 'margin_type' => $post['margin_type'] ?? 'percent', 'margin' => (float)($post['margin'] ?? 0),
            'round_to' => (float)($post['round_to'] ?? 0.01), 'round_mode' => $post['round_mode'] ?? 'nearest',
        ]);
        return 'Pricing preview created: job ' . $r['job_id'] . ' with ' . $r['items'] . ' changes; ' . count($r['unresolved_product_keys']) . ' product keys unresolved.';
    }

    private function applyTldJob(array $post): string { $r = $this->pricing->applyTldJob((string)$post['job_id']); return 'TLD pricing job status: ' . ($r['status'] ?? 'unknown') . '.'; }
    private function previewTldRollback(array $post): string { $r = $this->pricing->previewTldRollback((string)$post['job_id']); return 'Rollback preview ' . $r['job_id'] . ' created with ' . $r['items'] . ' restorable TLDs.'; }
    private function applyTldRollback(array $post): string { $r = $this->pricing->applyTldRollback((string)$post['job_id']); return 'Rollback status: ' . ($r['status'] ?? 'unknown') . '.'; }
    private function previewRecurring(array $post): string { $r = $this->pricing->previewRecurringSync((int)$post['account_id'], ['include_zero' => $post['include_zero'] ?? false, 'limit' => (int)($post['limit'] ?? 5000)]); return 'Recurring-price preview ' . $r['job_id'] . ': ' . $r['items'] . ' changes, ' . $r['skipped'] . ' skipped.'; }
    private function applyRecurring(array $post): string { $r = $this->pricing->applyRecurringJob((string)$post['job_id']); return 'Recurring-price job status: ' . ($r['status'] ?? 'unknown') . '.'; }
    private function previewRecurringRollback(array $post): string { $r = $this->pricing->previewRecurringRollback((string)$post['job_id']); return 'Recurring rollback preview ' . $r['job_id'] . ' created with ' . $r['items'] . ' changes.'; }
    private function refreshPromos(array $post): string { $r = $this->promos->refresh((int)$post['account_id']); return 'Promotions refreshed: ' . $r['count'] . ' found, ' . $r['mapped'] . ' mapped to TLDs.'; }
    private function previewPromo(array $post): string { $r = $this->promos->previewApply((int)$post['promo_id']); return 'Promotion preview ' . $r['job_id'] . ' created for ' . $r['tld'] . '.'; }
    private function applyPromo(array $post): string { $r = $this->promos->apply((string)$post['job_id']); return 'Promotion job status: ' . ($r['status'] ?? 'unknown') . '.'; }
    private function runAccountAutomation(array $post): string { $r = (new AutomationService($this->accounts))->runAccount((int)$post['account_id']); return 'Account automation completed: ' . Support::json(Support::redact($r)); }

    private function recentAudit(int $limit, ?int $accountId = null): array
    {
        $query = Capsule::table(Schema::TABLE_AUDIT)->orderByDesc('id')->limit($limit);
        if ($accountId) $query->where('account_id', $accountId);
        return array_map(static fn($r): array => (array)$r, $query->get()->all());
    }

    private function verifyCsrf(): void
    {
        if (function_exists('check_token')) {
            check_token('WHMCS.admin.default');
        }
    }

    private function adminId(): ?int { return isset($_SESSION['adminid']) ? (int)$_SESSION['adminid'] : null; }
}
