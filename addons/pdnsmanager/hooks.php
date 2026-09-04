<?php
/**
 * ServerSpan PowerDNS Manager - hooks
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/pdnsmanager/hooks.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Functions.php';

function pdns_hook_domain($vars)
{
    // Registrar hooks carry params.sld/params.tld; some carry a plain domain.
    if (!empty($vars['params']['sld']) && !empty($vars['params']['tld'])) {
        $tld = strtolower(trim((string) $vars['params']['tld']));
        // IDN TLDs arrive punycode-encoded already; just join.
        return strtolower(trim((string) $vars['params']['sld'])) . '.' . $tld;
    }
    if (!empty($vars['domain'])) {
        return strtolower(trim((string) $vars['domain']));
    }
    if (!empty($vars['params']['domain'])) {
        return strtolower(trim((string) $vars['params']['domain']));
    }
    return '';
}

function pdns_lifecycle_zone($vars, $event)
{
    if (pdns_setting('auto_create_on_' . $event, '') !== 'on') {
        return;
    }
    $domain = pdns_hook_domain($vars);
    if (!$domain) {
        return;
    }
    if (!pdns_zone_exists($domain)) {
        list($ok, $err) = pdns_create_zone($domain);
        if (!$ok) {
            pdns_log('zone_create_failed', $domain, $err, 'system');
            return;
        }
        pdns_log('zone_created', $domain, $event, 'system');
    }
    Capsule::table('mod_pdns_zones')->updateOrInsert(
        ['domain' => $domain],
        ['clientid' => pdns_domain_owner($domain), 'created_at' => date('Y-m-d H:i:s')]
    );

    $templateId = pdns_template_for($domain);
    if ($templateId) {
        list($ok, $err) = pdns_apply_template($templateId, $domain, [
            '{client.id}' => pdns_domain_owner($domain),
        ]);
        pdns_log($ok ? 'template_applied' : 'template_failed', $domain, $ok ? '#' . $templateId : $err, 'system');
    }
}

add_hook('AfterRegistrarRegister', 1, function ($vars) {
    pdns_lifecycle_zone($vars, 'register');
});

add_hook('AfterRegistrarTransfer', 1, function ($vars) {
    pdns_lifecycle_zone($vars, 'transfer');
});

add_hook('DomainDeleted', 1, function ($vars) {
    if (pdns_setting('delete_on_termination', '') !== 'on') {
        return;
    }
    $domain = '';
    if (!empty($vars['domain'])) {
        $domain = strtolower(trim((string) $vars['domain']));
    }
    if (!$domain) {
        return;
    }
    list($ok, $err) = pdns_delete_zone($domain);
    pdns_log($ok ? 'zone_deleted' : 'zone_delete_failed', $domain, $ok ? 'domain deleted' : $err, 'system');
});

/**
 * Product-matched zone templates: when a hosting service is created, apply
 * the template assigned to its product to the service domain.
 */
add_hook('AfterModuleCreate', 1, function ($vars) {
    $params = isset($vars['params']) ? $vars['params'] : [];
    $domain = isset($params['domain']) ? strtolower(trim($params['domain'])) : '';
    $pid    = isset($params['pid']) ? (int) $params['pid'] : 0;
    if (!$domain || !$pid || strpos($domain, '.') === false) {
        return;
    }
    $templateId = pdns_template_for($domain, $pid);
    if (!$templateId) {
        return;
    }
    if (!pdns_zone_exists($domain)) {
        list($ok, $err) = pdns_create_zone($domain);
        if (!$ok) {
            pdns_log('zone_create_failed', $domain, $err, 'system');
            return;
        }
    }
    $assignedIp = '';
    if (!empty($params['assignedips'])) {
        $ips = explode(',', (string) $params['assignedips']);
        $assignedIp = trim($ips[0]);
    }
    list($ok, $err) = pdns_apply_template($templateId, $domain, [
        '{client.id}'              => isset($params['userid']) ? (int) $params['userid'] : 0,
        '{service.dedicated_ip}'   => isset($params['dedicatedip']) ? trim($params['dedicatedip']) : '',
        '{service.assigned_ip}'    => $assignedIp,
        '{server.ip}'              => isset($params['serverip']) ? trim($params['serverip']) : '',
        '{server.hostname}'        => isset($params['serverhostname']) ? trim($params['serverhostname']) : '',
    ]);
    Capsule::table('mod_pdns_zones')->updateOrInsert(
        ['domain' => $domain],
        ['clientid' => isset($params['userid']) ? (int) $params['userid'] : 0,
         'created_at' => date('Y-m-d H:i:s')]
    );
    pdns_log($ok ? 'template_applied' : 'template_failed', $domain, $ok ? 'product #' . $pid : $err, 'system');
});

/* --------------------------------------- sidebar link for DNS management */

add_hook('ClientAreaPrimarySidebar', 1, function ($sidebar) {
    if (!$sidebar) {
        return;
    }
    $panel = $sidebar->getChild('DNS Manager');
    if (!$panel) {
        $panel = $sidebar->addChild('DNS Manager', [
            'label' => 'DNS Manager',
            'icon'  => 'fa-globe',
            'order' => 92,
        ]);
    }
    if ($panel && !$panel->getChild('Manage DNS Zones')) {
        $panel->addChild('Manage DNS Zones', [
            'label' => 'Manage DNS Zones',
            'uri'   => 'index.php?m=pdnsmanager',
            'icon'  => 'fa-server',
        ]);
    }
});

/* --------------------------------------------------------- log retention */

add_hook('DailyCronJob', 1, function () {
    Capsule::table('mod_pdns_log')
        ->where('created_at', '<=', date('Y-m-d H:i:s', strtotime('-90 days')))->delete();
});
