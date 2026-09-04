<?php
/**
 * ServerSpan PowerDNS DNS Hosting - provisioning module
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/servers/pdnshosting/pdnshosting.php
 *
 * Sells DNS hosting as a product: each service gets a PowerDNS zone for its
 * domain. Pairs with the ServerSpan PowerDNS Manager addon for client-facing
 * record management and zone templates.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/lib/Psrv.php';

function pdnshosting_MetaData()
{
    return [
        'DisplayName'    => 'ServerSpan PowerDNS DNS Hosting',
        'APIVersion'     => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '8081',
        'ListAccountsUniqueIdentifierDisplayName' => 'Domain',
        'ListAccountsUniqueIdentifierField'       => 'domain',
        'ListAccountsProductField'                => 'configoption1',
    ];
}

function pdnshosting_ConfigOptions()
{
    return [
        'Zone Template ID' => [
            'Type' => 'text', 'Size' => '10', 'Default' => '',
            'Description' => 'Template ID from the PowerDNS Manager addon (optional).',
        ],
        'Zone Type' => [
            'Type' => 'dropdown', 'Options' => 'Native,Master', 'Default' => 'Native',
        ],
        'Creation Method' => [
            'Type' => 'dropdown', 'Options' => 'rrsets,nameservers', 'Default' => 'rrsets',
            'Description' => 'rrsets = PowerDNS 4.3+. nameservers = legacy 4.2 and below.',
        ],
        'Rectify Mode' => [
            'Type' => 'dropdown', 'Options' => 'auto,post,put,none', 'Default' => 'auto',
        ],
        'PowerDNS Server ID' => [
            'Type' => 'text', 'Size' => '20', 'Default' => 'localhost',
        ],
    ];
}

function pdnshosting_TestConnection(array $params)
{
    $serverId = isset($params['configoption5']) && $params['configoption5'] !== ''
        ? $params['configoption5'] : 'localhost';
    $base = rtrim(trim((string) $params['serverhostname']), '/');
    $ch = curl_init($base . '/api/v1/servers/' . rawurlencode($serverId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['X-API-Key: ' . (string) $params['serveraccesshash']],
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || $code !== 200) {
        return ['success' => false, 'error' => $err ?: 'HTTP ' . $code];
    }
    return ['success' => true];
}

function pdnshosting_CreateAccount(array $params)
{
    $domain = isset($params['domain']) ? strtolower(trim($params['domain'])) : '';
    if (!$domain || strpos($domain, '.') === false) {
        return 'A valid domain is required on the service.';
    }
    list($ok, $err) = psrv_create_zone($params, $domain);
    if (!$ok) {
        return 'Zone creation failed: ' . $err;
    }
    list($ok, $err) = psrv_apply_template($params, $domain);
    if (!$ok) {
        // Zone exists; template failure is logged but does not fail provisioning.
        logModuleCall('pdnshosting', 'apply_template', $domain, $err);
    }
    return 'success';
}

function pdnshosting_TerminateAccount(array $params)
{
    $domain = isset($params['domain']) ? strtolower(trim($params['domain'])) : '';
    if (!$domain) {
        return 'success';
    }
    list($ok, $err) = psrv_delete_zone($params, $domain);
    if (!$ok) {
        return 'Zone deletion failed: ' . $err;
    }
    return 'success';
}

function pdnshosting_SuspendAccount(array $params)
{
    // PowerDNS has no zone-suspension concept; the zone stays resolvable.
    // Override here if you want suspension to replace the zone's NS/A records.
    logModuleCall('pdnshosting', 'suspend', isset($params['domain']) ? $params['domain'] : '', 'no-op');
    return 'success';
}

function pdnshosting_UnsuspendAccount(array $params)
{
    return 'success';
}

function pdnshosting_ClientArea(array $params)
{
    $domain = isset($params['domain']) ? strtolower(trim($params['domain'])) : '';
    return [
        'templatefile' => 'overview',
        'vars'         => [
            'domain'     => $domain,
            'managerUrl' => 'index.php?m=pdnsmanager&domain=' . urlencode($domain),
        ],
    ];
}
