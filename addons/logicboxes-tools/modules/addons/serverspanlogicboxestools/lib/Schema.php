<?php
namespace ServerSpan\LogicBoxesTools;

use WHMCS\Database\Capsule;
use Illuminate\Database\Schema\Blueprint;

final class Schema
{
    public const TABLE_ACCOUNTS = 'mod_serverspan_logicboxes_accounts';
    public const TABLE_CUSTOMERS = 'mod_serverspan_logicboxes_customers';
    public const TABLE_DOMAINS = 'mod_serverspan_logicboxes_domains';
    public const TABLE_JOBS = 'mod_serverspan_logicboxes_jobs';
    public const TABLE_JOB_ITEMS = 'mod_serverspan_logicboxes_job_items';
    public const TABLE_PROMOS = 'mod_serverspan_logicboxes_promos';
    public const TABLE_AUDIT = 'mod_serverspan_logicboxes_audit';
    public const TABLE_LOCKS = 'mod_serverspan_logicboxes_locks';

    public static function install(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::TABLE_ACCOUNTS)) {
            $schema->create(self::TABLE_ACCOUNTS, function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name', 120);
                $table->string('registrar_module', 80)->default('resellerclub');
                $table->unsignedBigInteger('reseller_id');
                $table->text('api_key_cipher');
                $table->string('base_url', 191)->default('https://httpapi.com/api');
                $table->string('currency', 3)->default('USD');
                $table->unsignedInteger('multiplier')->default(1);
                $table->text('nameservers')->nullable();
                $table->decimal('fund_threshold', 18, 4)->nullable();
                $table->boolean('enabled')->default(true);
                $table->boolean('auto_customer_signup')->default(false);
                $table->boolean('auto_customer_modify')->default(false);
                $table->boolean('auto_customer_delete')->default(false);
                $table->boolean('auto_price_sync')->default(false);
                $table->boolean('auto_promo_sync')->default(false);
                $table->boolean('auto_transfer_sync')->default(false);
                $table->boolean('auto_recurring_sync')->default(false);
                $table->longText('options')->nullable();
                $table->dateTime('last_ok_at')->nullable();
                $table->text('last_error')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->unique(['registrar_module', 'reseller_id'], 'ss_lb_account_unique');
                $table->index(['enabled'], 'ss_lb_account_enabled');
            });
        }

        if (!$schema->hasTable(self::TABLE_CUSTOMERS)) {
            $schema->create(self::TABLE_CUSTOMERS, function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('account_id');
                $table->unsignedInteger('whmcs_client_id');
                $table->unsignedBigInteger('logicboxes_customer_id');
                $table->string('username', 255);
                $table->string('sync_hash', 64)->nullable();
                $table->string('origin', 20)->default('mapped');
                $table->string('status', 30)->default('active');
                $table->text('last_error')->nullable();
                $table->dateTime('last_synced_at')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->unique(['account_id', 'whmcs_client_id'], 'ss_lb_client_map_unique');
                $table->unique(['account_id', 'logicboxes_customer_id'], 'ss_lb_customer_map_unique');
                $table->index(['username'], 'ss_lb_customer_username');
            });
        }

        if (!$schema->hasTable(self::TABLE_DOMAINS)) {
            $schema->create(self::TABLE_DOMAINS, function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('account_id');
                $table->unsignedInteger('whmcs_domain_id')->nullable();
                $table->unsignedBigInteger('logicboxes_order_id');
                $table->unsignedBigInteger('logicboxes_customer_id')->nullable();
                $table->string('domain', 255);
                $table->string('product_key', 120)->nullable();
                $table->string('upstream_status', 80)->nullable();
                $table->string('verification_status', 80)->nullable();
                $table->dateTime('last_synced_at')->nullable();
                $table->text('last_error')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->unique(['account_id', 'logicboxes_order_id'], 'ss_lb_domain_order_unique');
                $table->unique(['account_id', 'domain'], 'ss_lb_domain_name_unique');
                $table->index(['whmcs_domain_id'], 'ss_lb_whmcs_domain');
                $table->index(['verification_status'], 'ss_lb_verification');
            });
        }

        if (!$schema->hasTable(self::TABLE_JOBS)) {
            $schema->create(self::TABLE_JOBS, function (Blueprint $table): void {
                $table->string('id', 36)->primary();
                $table->unsignedInteger('account_id')->nullable();
                $table->string('type', 80);
                $table->string('mode', 20)->default('preview');
                $table->string('status', 20)->default('queued');
                $table->unsignedInteger('created_by_admin_id')->nullable();
                $table->unsignedInteger('total_items')->default(0);
                $table->unsignedInteger('processed_items')->default(0);
                $table->unsignedInteger('changed_items')->default(0);
                $table->unsignedInteger('failed_items')->default(0);
                $table->longText('context')->nullable();
                $table->text('last_error')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->index(['status', 'type'], 'ss_lb_jobs_status');
                $table->index(['account_id', 'created_at'], 'ss_lb_jobs_account');
            });
        }

        if (!$schema->hasTable(self::TABLE_JOB_ITEMS)) {
            $schema->create(self::TABLE_JOB_ITEMS, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('job_id', 36);
                $table->string('entity_type', 50);
                $table->string('entity_key', 255);
                $table->string('status', 20)->default('pending');
                $table->longText('before_json')->nullable();
                $table->longText('proposed_json')->nullable();
                $table->longText('applied_json')->nullable();
                $table->text('error')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->unique(['job_id', 'entity_type', 'entity_key'], 'ss_lb_job_item_unique');
                $table->index(['job_id', 'status'], 'ss_lb_job_item_status');
            });
        }

        if (!$schema->hasTable(self::TABLE_PROMOS)) {
            $schema->create(self::TABLE_PROMOS, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('account_id');
                $table->string('promo_key', 64);
                $table->string('product_key', 120);
                $table->string('tld', 120)->nullable();
                $table->string('action_type', 40);
                $table->unsignedInteger('period')->nullable();
                $table->decimal('customer_price', 18, 4)->nullable();
                $table->decimal('reseller_price', 18, 4)->nullable();
                $table->decimal('barrier_price', 18, 4)->nullable();
                $table->string('currency', 12)->nullable();
                $table->dateTime('starts_at')->nullable();
                $table->dateTime('ends_at')->nullable();
                $table->boolean('is_active')->default(false);
                $table->longText('raw_json')->nullable();
                $table->dateTime('updated_at');
                $table->unique(['account_id', 'promo_key'], 'ss_lb_promo_unique');
                $table->index(['account_id', 'is_active'], 'ss_lb_promo_active');
            });
        }

        if (!$schema->hasTable(self::TABLE_AUDIT)) {
            $schema->create(self::TABLE_AUDIT, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('account_id')->nullable();
                $table->unsignedInteger('admin_id')->nullable();
                $table->string('actor', 40)->default('system');
                $table->string('action', 100);
                $table->string('entity_type', 60)->nullable();
                $table->string('entity_key', 255)->nullable();
                $table->longText('before_json')->nullable();
                $table->longText('after_json')->nullable();
                $table->longText('metadata')->nullable();
                $table->dateTime('created_at');
                $table->index(['account_id', 'created_at'], 'ss_lb_audit_account');
                $table->index(['action'], 'ss_lb_audit_action');
            });
        }

        if (!$schema->hasTable(self::TABLE_LOCKS)) {
            $schema->create(self::TABLE_LOCKS, function (Blueprint $table): void {
                $table->string('lock_key', 191)->primary();
                $table->string('owner', 80);
                $table->dateTime('expires_at');
                $table->dateTime('created_at');
            });
        }
    }

    public static function uninstall(): void
    {
        $schema = Capsule::schema();
        foreach ([
            self::TABLE_LOCKS,
            self::TABLE_AUDIT,
            self::TABLE_PROMOS,
            self::TABLE_JOB_ITEMS,
            self::TABLE_JOBS,
            self::TABLE_DOMAINS,
            self::TABLE_CUSTOMERS,
            self::TABLE_ACCOUNTS,
        ] as $table) {
            if ($schema->hasTable($table)) {
                $schema->drop($table);
            }
        }
    }
}
