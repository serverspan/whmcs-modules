<?php
// This file intentionally includes the addon entrypoint twice to catch WHMCS-style repeated includes.
if (!defined('WHMCS')) {
    define('WHMCS', true);
}

$entry = dirname(__DIR__) . '/modules/addons/serverspanlogicboxestools/serverspanlogicboxestools.php';
require $entry;
require $entry;

echo "OK - addon entrypoint is re-include safe\n";
