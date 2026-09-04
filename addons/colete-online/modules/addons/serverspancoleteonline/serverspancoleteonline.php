<?php

declare(strict_types=1);

use ServerSpan\WHMCS\ColeteOnline\Controller;
use ServerSpan\WHMCS\ColeteOnline\Renderer;
use ServerSpan\WHMCS\ColeteOnline\ShipmentRepository;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

foreach (['ApiException','Support','TokenCodec','CacheRepository','ApiClient','ShipmentRepository','StatusParser','OrderRepository','PayloadBuilder','Renderer','Controller'] as $file) {
    require_once __DIR__ . '/lib/' . $file . '.php';
}

function serverspancoleteonline_config(): array
{
    return [
        'name' => 'ServerSpan Colete Online',
        'description' => 'Create Colete-Online courier shipments and AWBs from WHMCS orders, compare live courier prices, and track delivery status.',
        'author' => 'ServerSpan',
        'language' => 'english',
        'version' => '1.0.0-beta.1',
        'fields' => [
            'clientId' => [
                'FriendlyName' => 'API Client ID', 'Type' => 'text', 'Size' => '50',
                'Description' => 'Colete-Online OAuth client ID.',
            ],
            'clientSecret' => [
                'FriendlyName' => 'API Client Secret', 'Type' => 'password', 'Size' => '50',
                'Description' => 'Colete-Online OAuth client secret. Entered through a WHMCS password-type addon setting; at-rest handling follows the installed WHMCS version.',
            ],
            'staging' => [
                'FriendlyName' => 'Staging Mode', 'Type' => 'yesno', 'Default' => 'on',
                'Description' => 'Use https://api.colete-online.ro/v1/staging/ instead of production. Keep enabled while testing.',
            ],
            'defaultSenderAddressId' => [
                'FriendlyName' => 'Default Sender Address ID', 'Type' => 'text', 'Size' => '15',
                'Description' => 'Optional saved Colete-Online address/location ID selected by default.',
            ],
            'validationStrategy' => [
                'FriendlyName' => 'Recipient Validation', 'Type' => 'dropdown',
                'Options' => ['minimal' => 'minimal', 'priceMinimal' => 'priceMinimal'], 'Default' => 'minimal',
                'Description' => 'Colete-Online address validation strategy used for new shipments.',
            ],
            'defaultPackageType' => [
                'FriendlyName' => 'Default Package Type', 'Type' => 'dropdown',
                'Options' => ['2' => 'Parcel / Box', '1' => 'Envelope'], 'Default' => '2',
            ],
            'defaultWeight' => ['FriendlyName' => 'Default Weight (kg)', 'Type' => 'text', 'Size' => '8', 'Default' => '1'],
            'defaultHeight' => ['FriendlyName' => 'Default Height (cm)', 'Type' => 'text', 'Size' => '8', 'Default' => '10'],
            'defaultWidth' => ['FriendlyName' => 'Default Width (cm)', 'Type' => 'text', 'Size' => '8', 'Default' => '10'],
            'defaultLength' => ['FriendlyName' => 'Default Length (cm)', 'Type' => 'text', 'Size' => '8', 'Default' => '10'],
            'defaultContent' => ['FriendlyName' => 'Default Contents', 'Type' => 'text', 'Size' => '40', 'Default' => 'Products'],
            'recentOrders' => [
                'FriendlyName' => 'Recent Orders', 'Type' => 'dropdown',
                'Options' => ['25' => '25', '50' => '50', '100' => '100', '200' => '200'], 'Default' => '50',
            ],
            'apiDebug' => [
                'FriendlyName' => 'API Debug Metadata', 'Type' => 'yesno',
                'Description' => 'Log only HTTP method, API path and status to WHMCS Module Log. Payloads, credentials and tokens are never logged.',
            ],
        ],
    ];
}

function serverspancoleteonline_activate(): array
{
    try {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP cURL extension is required.');
        }
        ShipmentRepository::install();
        return [
            'status' => 'success',
            'description' => 'Colete Online activated. Shipment metadata and an encrypted short-lived OAuth token cache will be stored in dedicated module tables.',
        ];
    } catch (Throwable $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

function serverspancoleteonline_deactivate(): array
{
    try {
        ShipmentRepository::uninstallCache();
        return [
            'status' => 'success',
            'description' => 'Colete Online deactivated. OAuth cache removed. Shipment history is intentionally retained in mod_serverspan_coleteonline_shipments to avoid destroying AWB references.',
        ];
    } catch (Throwable $e) {
        return ['status' => 'error', 'description' => 'Deactivation cleanup failed: ' . $e->getMessage()];
    }
}

function serverspancoleteonline_output(array $vars): void
{
    try {
        echo (new Controller($vars))->dispatch();
    } catch (Throwable $e) {
        if (function_exists('logModuleCall')) {
            logModuleCall('serverspancoleteonline', 'admin', [], ['error' => get_class($e)]);
        }
        echo Renderer::error('Colete Online error: ' . $e->getMessage(), (string) ($vars['modulelink'] ?? 'addonmodules.php?module=serverspancoleteonline'));
    }
}
