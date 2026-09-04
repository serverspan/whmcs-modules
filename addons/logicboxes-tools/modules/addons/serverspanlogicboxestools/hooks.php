<?php
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!function_exists('serverspanlogicboxestools_bootstrap')) {
    require_once __DIR__ . '/serverspanlogicboxestools.php';
}

use ServerSpan\LogicBoxesTools\AccountRepository;
use ServerSpan\LogicBoxesTools\AuditLogger;
use ServerSpan\LogicBoxesTools\AutomationService;
use ServerSpan\LogicBoxesTools\CustomerService;

add_hook('ClientAdd', 50, static function (array $vars): void {
    $clientId = (int)($vars['userid'] ?? $vars['clientid'] ?? 0);
    if ($clientId <= 0) return;
    try {
        $accounts = new AccountRepository();
        (new CustomerService($accounts, new AuditLogger()))->autoSignupClient($clientId);
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) logActivity('ServerSpan LogicBoxes auto customer signup failed for client #' . $clientId . ': ' . $e->getMessage());
    }
});

add_hook('ClientEdit', 50, static function (array $vars): void {
    $clientId = (int)($vars['userid'] ?? $vars['clientid'] ?? 0);
    if ($clientId <= 0) return;
    try {
        $accounts = new AccountRepository();
        (new CustomerService($accounts, new AuditLogger()))->modifyMappedClient($clientId, 'hook');
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) logActivity('ServerSpan LogicBoxes customer modify hook failed for client #' . $clientId . ': ' . $e->getMessage());
    }
});

add_hook('PreDeleteClient', 20, static function (array $vars): void {
    $clientId = (int)($vars['userid'] ?? $vars['clientid'] ?? 0);
    if ($clientId <= 0) return;
    try {
        $accounts = new AccountRepository();
        (new CustomerService($accounts, new AuditLogger()))->deleteMappedClient($clientId);
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) logActivity('ServerSpan LogicBoxes pre-delete customer cleanup failed for client #' . $clientId . ': ' . $e->getMessage());
    }
});

add_hook('DailyCronJob', 50, static function (): void {
    try {
        (new AutomationService())->runDaily();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) logActivity('ServerSpan LogicBoxes daily automation failed: ' . $e->getMessage());
    }
});
