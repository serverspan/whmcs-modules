<?php

declare(strict_types=1);

namespace ServerSpan\WHMCS\SalesTracker;

use DateTimeImmutable;

final class SalesAnalytics
{
    public const DEFAULT_STATUSES = ['Active', 'Pending'];

    /**
     * @return array{from:string,to:string}
     */
    public static function normalizeDateRange(
        ?string $from,
        ?string $to,
        int $defaultDays = 30,
        ?DateTimeImmutable $today = null
    ): array {
        $today = $today ?: new DateTimeImmutable('today');
        $defaultDays = max(1, min(3660, $defaultDays));

        $fromDate = self::parseDate($from);
        $toDate = self::parseDate($to);

        if (!$toDate) {
            $toDate = $today;
        }
        if (!$fromDate) {
            $fromDate = $toDate->modify('-' . ($defaultDays - 1) . ' days');
        }

        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        return [
            'from' => $fromDate->format('Y-m-d'),
            'to' => $toDate->format('Y-m-d'),
        ];
    }

    public static function parseDate(?string $value): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$date) {
            return null;
        }
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $date : null;
    }

    public static function chooseBucket(string $from, string $to): string
    {
        $fromDate = self::parseDate($from);
        $toDate = self::parseDate($to);
        if (!$fromDate || !$toDate) {
            return 'day';
        }

        $days = (int) $fromDate->diff($toDate)->format('%a') + 1;
        if ($days <= 45) {
            return 'day';
        }
        if ($days <= 180) {
            return 'week';
        }
        return 'month';
    }

    public static function bucketKey(DateTimeImmutable $date, string $bucket): string
    {
        if ($bucket === 'month') {
            return $date->format('Y-m');
        }
        if ($bucket === 'week') {
            return $date->format('o-\\WW');
        }
        return $date->format('Y-m-d');
    }

    /**
     * @param array<int|string,mixed> $requested
     * @param string[] $available
     * @param string[] $defaults
     * @return string[]
     */
    public static function sanitizeStatuses(array $requested, array $available, array $defaults = self::DEFAULT_STATUSES): array
    {
        $availableMap = array_fill_keys($available, true);
        $clean = [];

        foreach ($requested as $status) {
            if (!is_scalar($status)) {
                continue;
            }
            $status = trim((string) $status);
            if ($status !== '' && isset($availableMap[$status])) {
                $clean[$status] = true;
            }
        }

        if (!$clean) {
            foreach ($defaults as $status) {
                if (isset($availableMap[$status])) {
                    $clean[$status] = true;
                }
            }
        }

        if (!$clean && $available) {
            $clean[$available[0]] = true;
        }

        return array_keys($clean);
    }

    /**
     * @param int[] $availableIds
     */
    public static function normalizeCurrencyId($requested, array $availableIds, int $defaultId): int
    {
        $requested = filter_var($requested, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($requested !== false && in_array((int) $requested, $availableIds, true)) {
            return (int) $requested;
        }

        return in_array($defaultId, $availableIds, true)
            ? $defaultId
            : (int) ($availableIds[0] ?? 0);
    }

    public static function classifyOrder($adminRequestorId): string
    {
        return (int) $adminRequestorId > 0 ? 'agent' : 'self';
    }

    public static function percent(float $part, float $whole): float
    {
        return $whole > 0.0 ? ($part / $whole) * 100.0 : 0.0;
    }

    /**
     * Fill missing chart buckets with zero values so quiet periods remain visible.
     *
     * @param array<int,array{bucket:string,orders:int,value:float}> $rows
     * @return array<int,array{bucket:string,orders:int,value:float}>
     */
    public static function fillTrend(array $rows, string $from, string $to, string $bucket): array
    {
        $fromDate = self::parseDate($from);
        $toDate = self::parseDate($to);
        if (!$fromDate || !$toDate) {
            return $rows;
        }

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['bucket']] = [
                'bucket' => (string) $row['bucket'],
                'orders' => (int) $row['orders'],
                'value' => (float) $row['value'],
            ];
        }

        $keys = [];
        if ($bucket === 'month') {
            $cursor = $fromDate->modify('first day of this month');
            $end = $toDate->modify('first day of this month');
            while ($cursor <= $end) {
                $keys[] = self::bucketKey($cursor, 'month');
                $cursor = $cursor->modify('+1 month');
            }
        } elseif ($bucket === 'week') {
            $cursor = $fromDate;
            $seen = [];
            while ($cursor <= $toDate) {
                $key = self::bucketKey($cursor, 'week');
                if (!isset($seen[$key])) {
                    $keys[] = $key;
                    $seen[$key] = true;
                }
                $cursor = $cursor->modify('+1 day');
            }
        } else {
            $cursor = $fromDate;
            while ($cursor <= $toDate) {
                $keys[] = self::bucketKey($cursor, 'day');
                $cursor = $cursor->modify('+1 day');
            }
        }

        $filled = [];
        foreach ($keys as $key) {
            $filled[] = $indexed[$key] ?? ['bucket' => $key, 'orders' => 0, 'value' => 0.0];
        }
        return $filled;
    }

    /**
     * Pure helper used by tests and small derived views.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{orders:int,value:float,average:float,agent_orders:int,self_orders:int}
     */
    public static function summarizeRows(array $rows): array
    {
        $orders = count($rows);
        $value = 0.0;
        $agentOrders = 0;

        foreach ($rows as $row) {
            $value += (float) ($row['amount'] ?? 0);
            if (self::classifyOrder($row['admin_requestor_id'] ?? 0) === 'agent') {
                $agentOrders++;
            }
        }

        return [
            'orders' => $orders,
            'value' => $value,
            'average' => $orders > 0 ? $value / $orders : 0.0,
            'agent_orders' => $agentOrders,
            'self_orders' => $orders - $agentOrders,
        ];
    }
}
