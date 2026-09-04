<?php
// Requiring hooks twice must not register duplicate hooks or redeclare the addon entrypoint.
if (!defined('WHMCS')) {
    define('WHMCS', true);
}

if (!function_exists('add_hook')) {
    function add_hook($name, $priority, $callback): void
    {
        $GLOBALS['serverspan_logicboxes_test_hooks'][] = [$name, $priority];
    }
}

$hooks = dirname(__DIR__) . '/modules/addons/serverspanlogicboxestools/hooks.php';
require $hooks;
require $hooks;

$count = count($GLOBALS['serverspan_logicboxes_test_hooks'] ?? []);
if ($count !== 4) {
    fwrite(STDERR, "FAIL - expected 4 hook registrations, got {$count}\n");
    exit(1);
}

echo "OK - hook registration is re-include safe\n";
