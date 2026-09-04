<?php

declare(strict_types=1);

use ServerSpan\WHMCS\SalesTracker\Renderer;
use ServerSpan\WHMCS\SalesTracker\SalesAnalytics;
use ServerSpan\WHMCS\SalesTracker\SalesRepository;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/SalesAnalytics.php';
require_once __DIR__ . '/lib/SalesRepository.php';
require_once __DIR__ . '/lib/Renderer.php';

function serverspansalestracker_config(): array
{
    return [
        'name' => 'ServerSpan Sales Tracker',
        'description' => 'Read-only WHMCS sales, product and administrator performance analytics with date filtering and self-vs-agent attribution.',
        'author' => 'ServerSpan',
        'language' => 'english',
        'version' => '1.0.0-beta.1',
        'fields' => [
            'defaultRange' => [
                'FriendlyName' => 'Default Date Range',
                'Type' => 'dropdown',
                'Options' => [
                    '7' => 'Last 7 days',
                    '30' => 'Last 30 days',
                    '90' => 'Last 90 days',
                    '365' => 'Last 365 days',
                ],
                'Default' => '30',
                'Description' => 'Initial reporting window when no custom date filter is supplied.',
            ],
            'paidOnlyDefault' => [
                'FriendlyName' => 'Paid Only by Default',
                'Type' => 'yesno',
                'Description' => 'Default the dashboard to orders whose linked invoice is Paid.',
            ],
            'topLimit' => [
                'FriendlyName' => 'Top Results',
                'Type' => 'dropdown',
                'Options' => [
                    '5' => '5',
                    '10' => '10',
                    '20' => '20',
                    '50' => '50',
                ],
                'Default' => '10',
                'Description' => 'Maximum products and agents displayed in ranking tables.',
            ],
        ],
    ];
}

function serverspansalestracker_activate(): array
{
    try {
        (new SalesRepository())->assertCompatible();
        return [
            'status' => 'success',
            'description' => 'Sales Tracker activated. No custom database tables are created; the module reads existing WHMCS reporting data only.',
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'description' => 'Sales Tracker requires a modern WHMCS order schema with administrator requestor attribution.',
        ];
    }
}

function serverspansalestracker_deactivate(): array
{
    return [
        'status' => 'success',
        'description' => 'Sales Tracker deactivated. No module data or WHMCS records required cleanup.',
    ];
}

function serverspansalestracker_output(array $vars): void
{
    try {
        $repo = new SalesRepository();
        $repo->assertCompatible();

        $defaultDays = max(1, (int) ($vars['defaultRange'] ?? 30));
        $dates = SalesAnalytics::normalizeDateRange(
            isset($_GET['from']) ? (string) $_GET['from'] : null,
            isset($_GET['to']) ? (string) $_GET['to'] : null,
            $defaultDays
        );

        $currencies = $repo->currencies();
        if (!$currencies) {
            throw new RuntimeException('WHMCS has no configured currencies.');
        }
        $currencyIds = array_map(static fn(array $c): int => $c['id'], $currencies);
        $defaultCurrencyId = $currencies[0]['id'];
        foreach ($currencies as $c) {
            if ($c['default']) {
                $defaultCurrencyId = $c['id'];
                break;
            }
        }
        $currencyId = SalesAnalytics::normalizeCurrencyId($_GET['currency'] ?? null, $currencyIds, $defaultCurrencyId);
        $currency = $currencies[0];
        foreach ($currencies as $candidate) {
            if ($candidate['id'] === $currencyId) {
                $currency = $candidate;
                break;
            }
        }

        $availableStatuses = $repo->orderStatuses();
        $requestedStatuses = isset($_GET['status']) && is_array($_GET['status']) ? $_GET['status'] : [];
        $statuses = SalesAnalytics::sanitizeStatuses($requestedStatuses, $availableStatuses);

        $paidOnly = array_key_exists('paid', $_GET)
            ? ((string) $_GET['paid'] === '1')
            : (($vars['paidOnlyDefault'] ?? '') === 'on');

        $agentId = filter_var($_GET['agent'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $agentId = $agentId === false || $agentId === null ? 0 : (int) $agentId;

        $filter = [
            'from' => $dates['from'],
            'to' => $dates['to'],
            'currency_id' => $currencyId,
            'statuses' => $statuses,
            'paid_only' => $paidOnly,
        ];
        if ($agentId > 0) {
            $filter['agent_id'] = $agentId;
        }

        $bucket = SalesAnalytics::chooseBucket($filter['from'], $filter['to']);
        $topLimit = max(5, min(50, (int) ($vars['topLimit'] ?? 10)));
        $summary = $repo->summary($filter);
        $trend = SalesAnalytics::fillTrend($repo->trend($filter, $bucket), $filter['from'], $filter['to'], $bucket);
        $products = $repo->topProducts($filter, $topLimit);
        $productUnitsTotal = $repo->totalProductUnits($filter);
        $orders = $repo->recentOrders($filter, 100);

        $agents = [];
        $agent = null;
        if ($agentId > 0) {
            $allAgentsFilter = $filter;
            unset($allAgentsFilter['agent_id']);
            foreach ($repo->agents($allAgentsFilter, 200) as $candidate) {
                if ((int) $candidate['id'] === $agentId) {
                    $agent = $candidate;
                    break;
                }
            }
            if (!$agent) {
                $agent = ['id' => $agentId, 'name' => 'Admin #' . $agentId, 'username' => ''];
            }
        } else {
            $agents = $repo->agents($filter, $topLimit);
        }

        echo Renderer::dashboard([
            'modulelink' => (string) $vars['modulelink'],
            'filter' => $filter,
            'currency' => $currency,
            'currencies' => $currencies,
            'available_statuses' => $availableStatuses,
            'summary' => $summary,
            'trend' => $trend,
            'bucket' => $bucket,
            'products' => $products,
            'product_units_total' => $productUnitsTotal,
            'agents' => $agents,
            'agent' => $agent,
            'orders' => $orders,
        ]);
    } catch (Throwable $e) {
        logModuleCall('serverspansalestracker', 'dashboard', [], $e->getMessage());
        echo Renderer::error('Unable to build the report. Check Module Log for the sanitized error message.');
    }
}
