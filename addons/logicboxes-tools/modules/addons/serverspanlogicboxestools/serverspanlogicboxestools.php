<?php
/**
 * ServerSpan LogicBoxes Tools for WHMCS
 * https://github.com/serverspan/whmcs-modules
 */
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!function_exists('serverspanlogicboxestools_bootstrap')) {
    function serverspanlogicboxestools_bootstrap(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        spl_autoload_register(static function (string $class): void {
            $prefix = 'ServerSpan\\LogicBoxesTools\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $name = substr($class, strlen($prefix));
            if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                return;
            }

            $file = __DIR__ . '/lib/' . $name . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}

serverspanlogicboxestools_bootstrap();

if (!function_exists('serverspanlogicboxestools_config')) {
    function serverspanlogicboxestools_config(): array
    {
        return [
            'name' => 'ServerSpan LogicBoxes Tools',
            'description' => 'Secure LogicBoxes/ResellerClub customer, domain, pricing, promotion, transfer, SSO and automation toolkit with dry-run jobs, audit snapshots and rollback.',
            'author' => 'ServerSpan SysAdmin Team',
            'language' => 'english',
            'version' => '1.0.0-beta.1',
            'fields' => [],
        ];
    }
}

if (!function_exists('serverspanlogicboxestools_activate')) {
    function serverspanlogicboxestools_activate(): array
    {
        try {
            \ServerSpan\LogicBoxesTools\Schema::install();
            return ['status' => 'success', 'description' => 'ServerSpan LogicBoxes Tools database schema installed.'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('serverspanlogicboxestools_deactivate')) {
    function serverspanlogicboxestools_deactivate(): array
    {
        return [
            'status' => 'success',
            'description' => 'Module deactivated. Mappings, audit history, pricing snapshots and rollback data are intentionally retained.',
        ];
    }
}

if (!function_exists('serverspanlogicboxestools_upgrade')) {
    function serverspanlogicboxestools_upgrade(array $vars): void
    {
        // Schema::install() is deliberately idempotent and is the migration safety net for beta upgrades.
        \ServerSpan\LogicBoxesTools\Schema::install();
    }
}

if (!function_exists('serverspanlogicboxestools_output')) {
    function serverspanlogicboxestools_output(array $vars): void
    {
        echo (new \ServerSpan\LogicBoxesTools\Controller())->output($vars);
    }
}

if (!function_exists('serverspanlogicboxestools_clientarea')) {
    function serverspanlogicboxestools_clientarea(array $vars): array
    {
        return (new \ServerSpan\LogicBoxesTools\ClientController())->page($vars);
    }
}
