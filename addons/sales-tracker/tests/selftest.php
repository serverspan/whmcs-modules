<?php

declare(strict_types=1);

use ServerSpan\WHMCS\SalesTracker\SalesAnalytics;

require_once __DIR__ . '/../modules/addons/serverspansalestracker/lib/SalesAnalytics.php';

$tests = 0;
$failures = 0;

function check(bool $condition, string $message): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$today = new DateTimeImmutable('2026-09-04');
$range = SalesAnalytics::normalizeDateRange(null, null, 30, $today);
check($range === ['from' => '2026-08-06', 'to' => '2026-09-04'], '30-day default range is inclusive');

$range = SalesAnalytics::normalizeDateRange('2026-09-04', '2026-09-01', 30, $today);
check($range === ['from' => '2026-09-01', 'to' => '2026-09-04'], 'reversed dates are normalized');

$range = SalesAnalytics::normalizeDateRange('nope', '2026-09-04', 7, $today);
check($range === ['from' => '2026-08-29', 'to' => '2026-09-04'], 'invalid start falls back from valid end');

check(SalesAnalytics::parseDate('2026-02-29') === null, 'invalid leap-day rejected');
check(SalesAnalytics::parseDate('2028-02-29') instanceof DateTimeImmutable, 'valid leap-day accepted');
check(SalesAnalytics::parseDate('04-09-2026') === null, 'non-ISO date rejected');

check(SalesAnalytics::chooseBucket('2026-09-01', '2026-09-30') === 'day', '30 days uses day buckets');
check(SalesAnalytics::chooseBucket('2026-01-01', '2026-04-30') === 'week', '120 days uses week buckets');
check(SalesAnalytics::chooseBucket('2025-01-01', '2026-09-04') === 'month', 'long range uses month buckets');

check(SalesAnalytics::bucketKey(new DateTimeImmutable('2026-09-04'), 'day') === '2026-09-04', 'day bucket key');
check(SalesAnalytics::bucketKey(new DateTimeImmutable('2026-09-04'), 'month') === '2026-09', 'month bucket key');
check(SalesAnalytics::bucketKey(new DateTimeImmutable('2026-09-04'), 'week') === '2026-W36', 'ISO week bucket key');

$available = ['Pending', 'Active', 'Cancelled', 'Fraud'];
check(SalesAnalytics::sanitizeStatuses(['Active', 'NOPE', 'Active'], $available) === ['Active'], 'statuses whitelisted and deduplicated');
check(SalesAnalytics::sanitizeStatuses([], $available) === ['Active', 'Pending'], 'default statuses applied in canonical default order');
check(SalesAnalytics::sanitizeStatuses([], ['Fraud']) === ['Fraud'], 'fallback uses first available status');

check(SalesAnalytics::normalizeCurrencyId('2', [1, 2, 3], 1) === 2, 'valid requested currency accepted');
check(SalesAnalytics::normalizeCurrencyId('99', [1, 2, 3], 1) === 1, 'invalid currency falls back to default');
check(SalesAnalytics::normalizeCurrencyId(null, [4, 5], 1) === 4, 'missing default falls back to first available currency');

check(SalesAnalytics::classifyOrder(7) === 'agent', 'admin requestor means agent');
check(SalesAnalytics::classifyOrder(0) === 'self', 'zero admin requestor means self');
check(SalesAnalytics::classifyOrder(null) === 'self', 'null admin requestor means self');

check(abs(SalesAnalytics::percent(25, 100) - 25.0) < 0.0001, 'percent calculation');
check(SalesAnalytics::percent(10, 0) === 0.0, 'zero denominator is safe');

$filled = SalesAnalytics::fillTrend([
    ['bucket' => '2026-09-01', 'orders' => 2, 'value' => 20.0],
    ['bucket' => '2026-09-03', 'orders' => 1, 'value' => 5.0],
], '2026-09-01', '2026-09-03', 'day');
check(count($filled) === 3, 'daily trend fills missing buckets');
check($filled[1] === ['bucket' => '2026-09-02', 'orders' => 0, 'value' => 0.0], 'missing day becomes zero');

$filledWeeks = SalesAnalytics::fillTrend([], '2026-08-31', '2026-09-13', 'week');
check(array_column($filledWeeks, 'bucket') === ['2026-W36', '2026-W37'], 'weekly trend enumerates ISO weeks');

$filledMonths = SalesAnalytics::fillTrend([], '2026-07-20', '2026-09-04', 'month');
check(array_column($filledMonths, 'bucket') === ['2026-07', '2026-08', '2026-09'], 'monthly trend enumerates calendar months');

$summary = SalesAnalytics::summarizeRows([
    ['amount' => '10.00', 'admin_requestor_id' => 0],
    ['amount' => '20.50', 'admin_requestor_id' => 4],
    ['amount' => '9.50', 'admin_requestor_id' => 4],
]);
check($summary['orders'] === 3, 'summary order count');
check(abs($summary['value'] - 40.0) < 0.0001, 'summary sales value');
check(abs($summary['average'] - (40 / 3)) < 0.0001, 'summary average order');
check($summary['agent_orders'] === 2, 'summary agent orders');
check($summary['self_orders'] === 1, 'summary self orders');

// Module config smoke test: function declarations should load without a WHMCS runtime.
define('WHMCS', true);
require_once __DIR__ . '/../modules/addons/serverspansalestracker/serverspansalestracker.php';
$config = serverspansalestracker_config();
check(($config['name'] ?? '') === 'ServerSpan Sales Tracker', 'module config name');
check(($config['version'] ?? '') === '1.0.0-beta.1', 'module config version');
check(isset($config['fields']['defaultRange'], $config['fields']['paidOnlyDefault'], $config['fields']['topLimit']), 'module config fields');

if ($failures > 0) {
    fwrite(STDERR, "{$failures}/{$tests} tests failed.\n");
    exit(1);
}

echo "{$tests}/{$tests} tests passed.\n";
