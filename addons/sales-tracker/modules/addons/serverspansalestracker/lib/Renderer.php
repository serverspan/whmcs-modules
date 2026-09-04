<?php

declare(strict_types=1);

namespace ServerSpan\WHMCS\SalesTracker;

final class Renderer
{
    /** @param array<string,mixed> $ctx */
    public static function dashboard(array $ctx): string
    {
        $css = @file_get_contents(__DIR__ . '/../assets/admin.css') ?: '';
        $summary = $ctx['summary'];
        $currency = $ctx['currency'];
        $filter = $ctx['filter'];
        $agent = $ctx['agent'] ?? null;
        $agentPct = SalesAnalytics::percent((float) $summary['agent_orders'], (float) $summary['orders']);

        ob_start();
        echo '<style>' . $css . '</style>';
        echo '<div class="sst">';
        echo '<div class="sst-head">';
        echo '<div><h2>' . ($agent ? 'Agent Performance' : 'Sales Tracker') . '</h2>';
        echo '<p>Order analytics from WHMCS, grouped by the administrator who placed each order.</p></div>';
        if ($agent) {
            echo '<a class="sst-btn sst-btn-secondary" href="' . self::e(self::url($ctx['modulelink'], self::filterParams($filter))) . '">Back to all sales</a>';
        }
        echo '</div>';

        if ($agent) {
            echo '<div class="sst-agent-title"><strong>' . self::e($agent['name']) . '</strong>';
            if (!empty($agent['username'])) {
                echo '<span>@' . self::e($agent['username']) . '</span>';
            }
            echo '</div>';
        }

        echo self::filterForm($ctx);

        echo '<div class="sst-grid sst-grid-4">';
        echo self::metric('Orders', number_format((int) $summary['orders']), $filter['paid_only'] ? 'Paid invoices only' : 'Matching order records');
        echo self::metric('Sales value', self::money((float) $summary['value'], $currency), 'WHMCS order amount');
        echo self::metric('Average order', self::money((float) $summary['average'], $currency), 'Sales value / orders');
        if ($agent) {
            echo self::metric('Attributed to agent', number_format((int) $summary['agent_orders']), 'Orders placed by this admin');
        } else {
            echo self::metric('Agent-driven share', number_format($agentPct, 1) . '%', number_format((int) $summary['agent_orders']) . ' agent / ' . number_format((int) $summary['self_orders']) . ' self');
        }
        echo '</div>';

        echo '<div class="sst-grid sst-grid-2">';
        echo '<section class="sst-card"><div class="sst-card-head"><h3>Sales value trend</h3><span>' . self::e(ucfirst($ctx['bucket'])) . ' buckets</span></div>';
        echo self::lineChart($ctx['trend'], 'value', $currency);
        echo '</section>';
        echo '<section class="sst-card"><div class="sst-card-head"><h3>Order trend</h3><span>Orders per ' . self::e($ctx['bucket']) . '</span></div>';
        echo self::barChart($ctx['trend'], 'orders');
        echo '</section>';
        echo '</div>';

        if (!$agent) {
            echo '<div class="sst-grid sst-grid-2">';
            echo '<section class="sst-card"><div class="sst-card-head"><h3>Self vs. agent orders</h3><span>Order origin</span></div>';
            echo self::originChart((int) $summary['self_orders'], (int) $summary['agent_orders']);
            echo '</section>';
            echo '<section class="sst-card"><div class="sst-card-head"><h3>Top sales agents</h3><span>Ranked by sales value</span></div>';
            echo self::agentsTable($ctx['agents'], $ctx);
            echo '</section>';
            echo '</div>';
        }

        echo '<div class="sst-grid sst-grid-2">';
        echo '<section class="sst-card"><div class="sst-card-head"><h3>Top sales products</h3><span>Ranked by units sold</span></div>';
        echo self::productsTable($ctx['products'], (int) $ctx['product_units_total']);
        echo '</section>';
        echo '<section class="sst-card"><div class="sst-card-head"><h3>Recent matching orders</h3><span>Newest first</span></div>';
        echo self::ordersTable($ctx['orders'], $currency);
        echo '</section>';
        echo '</div>';

        echo '<div class="sst-note"><strong>Metric definition:</strong> sales value uses <code>tblorders.amount</code> on the order date. '
            . 'An order is agent-driven when WHMCS records <code>admin_requestor_id &gt; 0</code>; all other matching orders are reported as self/web. '
            . 'Currencies are never mixed in the same revenue total.</div>';

        echo '</div>';
        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $ctx */
    public static function error(string $message): string
    {
        return '<div class="alert alert-danger"><strong>Sales Tracker:</strong> ' . self::e($message) . '</div>';
    }

    /** @param array<string,mixed> $ctx */
    private static function filterForm(array $ctx): string
    {
        $filter = $ctx['filter'];
        $selectedStatuses = array_fill_keys($filter['statuses'], true);
        $agent = $ctx['agent'] ?? null;

        ob_start();
        echo '<form class="sst-filter" method="get" action="addonmodules.php">';
        echo '<input type="hidden" name="module" value="serverspansalestracker">';
        if ($agent) {
            echo '<input type="hidden" name="agent" value="' . (int) $agent['id'] . '">';
        }
        echo '<label><span>From</span><input type="date" name="from" value="' . self::e($filter['from']) . '"></label>';
        echo '<label><span>To</span><input type="date" name="to" value="' . self::e($filter['to']) . '"></label>';
        echo '<label><span>Currency</span><select name="currency">';
        foreach ($ctx['currencies'] as $currency) {
            $selected = (int) $currency['id'] === (int) $filter['currency_id'] ? ' selected' : '';
            echo '<option value="' . (int) $currency['id'] . '"' . $selected . '>' . self::e($currency['code']) . '</option>';
        }
        echo '</select></label>';

        echo '<div class="sst-status"><span>Order status</span><div class="sst-statuses">';
        foreach ($ctx['available_statuses'] as $status) {
            $checked = isset($selectedStatuses[$status]) ? ' checked' : '';
            echo '<label><input type="checkbox" name="status[]" value="' . self::e($status) . '"' . $checked . '> ' . self::e($status) . '</label>';
        }
        echo '</div></div>';
        echo '<label class="sst-check"><input type="checkbox" name="paid" value="1"' . ($filter['paid_only'] ? ' checked' : '') . '> Paid invoices only</label>';
        echo '<div class="sst-filter-actions"><button class="sst-btn" type="submit">Apply filters</button>';
        echo '<a class="sst-btn sst-btn-secondary" href="' . self::e($ctx['modulelink']) . '">Reset</a></div>';
        echo '</form>';
        return (string) ob_get_clean();
    }

    private static function metric(string $label, string $value, string $hint): string
    {
        return '<section class="sst-metric"><span>' . self::e($label) . '</span><strong>' . self::e($value) . '</strong><small>' . self::e($hint) . '</small></section>';
    }

    /** @param array<int,array{bucket:string,orders:int,value:float}> $rows */
    private static function lineChart(array $rows, string $field, array $currency): string
    {
        if (!$rows) {
            return self::emptyState('No matching sales in this period.');
        }

        $width = 900.0;
        $height = 230.0;
        $left = 48.0;
        $right = 18.0;
        $top = 18.0;
        $bottom = 42.0;
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;
        $values = array_map(static fn(array $r): float => (float) $r[$field], $rows);
        $max = max($values) ?: 1.0;
        $count = count($rows);
        $points = [];

        foreach ($rows as $i => $row) {
            $x = $left + ($count === 1 ? $plotW / 2 : ($i / ($count - 1)) * $plotW);
            $y = $top + $plotH - (((float) $row[$field] / $max) * $plotH);
            $points[] = [$x, $y, $row];
        }

        ob_start();
        echo '<svg class="sst-chart" viewBox="0 0 900 230" role="img" aria-label="Sales value trend">';
        for ($i = 0; $i <= 4; $i++) {
            $y = $top + ($plotH / 4) * $i;
            echo '<line class="sst-gridline" x1="' . $left . '" y1="' . $y . '" x2="' . ($width - $right) . '" y2="' . $y . '"></line>';
        }
        echo '<polyline class="sst-line" points="';
        foreach ($points as [$x, $y]) {
            echo round($x, 2) . ',' . round($y, 2) . ' ';
        }
        echo '"></polyline>';
        foreach ($points as [$x, $y, $row]) {
            echo '<circle class="sst-point" cx="' . round($x, 2) . '" cy="' . round($y, 2) . '" r="4">';
            echo '<title>' . self::e($row['bucket'] . ': ' . self::money((float) $row[$field], $currency) . ' / ' . $row['orders'] . ' orders') . '</title></circle>';
        }
        echo self::axisLabels($rows, $left, $plotW, $top + $plotH + 22);
        echo '</svg>';
        return (string) ob_get_clean();
    }

    /** @param array<int,array{bucket:string,orders:int,value:float}> $rows */
    private static function barChart(array $rows, string $field): string
    {
        if (!$rows) {
            return self::emptyState('No matching orders in this period.');
        }

        $width = 900.0;
        $height = 230.0;
        $left = 48.0;
        $right = 18.0;
        $top = 18.0;
        $bottom = 42.0;
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;
        $values = array_map(static fn(array $r): float => (float) $r[$field], $rows);
        $max = max($values) ?: 1.0;
        $count = count($rows);
        $slot = $plotW / max(1, $count);
        $barW = max(2.0, min(28.0, $slot * 0.7));

        ob_start();
        echo '<svg class="sst-chart" viewBox="0 0 900 230" role="img" aria-label="Order trend">';
        for ($i = 0; $i <= 4; $i++) {
            $y = $top + ($plotH / 4) * $i;
            echo '<line class="sst-gridline" x1="' . $left . '" y1="' . $y . '" x2="' . ($width - $right) . '" y2="' . $y . '"></line>';
        }
        foreach ($rows as $i => $row) {
            $value = (float) $row[$field];
            $h = ($value / $max) * $plotH;
            $x = $left + ($i * $slot) + (($slot - $barW) / 2);
            $y = $top + $plotH - $h;
            echo '<rect class="sst-bar" x="' . round($x, 2) . '" y="' . round($y, 2) . '" width="' . round($barW, 2) . '" height="' . round($h, 2) . '" rx="2">';
            echo '<title>' . self::e($row['bucket'] . ': ' . (int) $row[$field] . ' orders') . '</title></rect>';
        }
        echo self::axisLabels($rows, $left, $plotW, $top + $plotH + 22);
        echo '</svg>';
        return (string) ob_get_clean();
    }

    /** @param array<int,array{bucket:string,orders:int,value:float}> $rows */
    private static function axisLabels(array $rows, float $left, float $plotW, float $y): string
    {
        $count = count($rows);
        if ($count === 0) {
            return '';
        }
        $indexes = array_values(array_unique([0, (int) floor(($count - 1) / 2), $count - 1]));
        $html = '';
        foreach ($indexes as $i) {
            $x = $left + ($count === 1 ? $plotW / 2 : ($i / ($count - 1)) * $plotW);
            $anchor = $i === 0 ? 'start' : ($i === $count - 1 ? 'end' : 'middle');
            $html .= '<text class="sst-axis" x="' . round($x, 2) . '" y="' . round($y, 2) . '" text-anchor="' . $anchor . '">' . self::e($rows[$i]['bucket']) . '</text>';
        }
        return $html;
    }

    private static function originChart(int $selfOrders, int $agentOrders): string
    {
        $total = $selfOrders + $agentOrders;
        if ($total === 0) {
            return self::emptyState('No matching orders in this period.');
        }
        $agentPct = SalesAnalytics::percent($agentOrders, $total);
        return '<div class="sst-origin">'
            . '<div class="sst-donut" style="--agent-pct:' . number_format($agentPct, 2, '.', '') . '%"><div><strong>' . number_format($total) . '</strong><span>orders</span></div></div>'
            . '<div class="sst-legend">'
            . '<div><i class="sst-dot sst-dot-self"></i><span>Self / web</span><strong>' . number_format($selfOrders) . '</strong></div>'
            . '<div><i class="sst-dot sst-dot-agent"></i><span>Agent</span><strong>' . number_format($agentOrders) . '</strong></div>'
            . '</div></div>';
    }

    /** @param array<int,array<string,mixed>> $rows @param array<string,mixed> $ctx */
    private static function agentsTable(array $rows, array $ctx): string
    {
        if (!$rows) {
            return self::emptyState('No agent-driven orders matched the filters.');
        }
        $total = max(1.0, (float) $ctx['summary']['value']);
        $html = '<div class="sst-table-wrap"><table class="sst-table"><thead><tr><th>Agent</th><th>Orders</th><th>Sales value</th><th>Share</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $params = self::filterParams($ctx['filter']);
            $params['agent'] = (int) $row['id'];
            $share = SalesAnalytics::percent((float) $row['value'], $total);
            $html .= '<tr><td><strong>' . self::e($row['name']) . '</strong>';
            if ($row['username'] !== '') {
                $html .= '<small>@' . self::e($row['username']) . '</small>';
            }
            $html .= '</td><td>' . number_format((int) $row['orders']) . '</td><td>' . self::e(self::money((float) $row['value'], $ctx['currency'])) . '</td>';
            $html .= '<td><div class="sst-share"><span style="width:' . number_format(min(100.0, $share), 2, '.', '') . '%"></span></div><small>' . number_format($share, 1) . '%</small></td>';
            $html .= '<td><a href="' . self::e(self::url($ctx['modulelink'], $params)) . '">Details</a></td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function productsTable(array $rows, int $totalUnits): string
    {
        if (!$rows) {
            return self::emptyState('No hosting products matched the filters.');
        }
        $units = max(1, $totalUnits);
        $html = '<div class="sst-table-wrap"><table class="sst-table"><thead><tr><th>Product</th><th>Units</th><th>Orders</th><th>Share</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $share = SalesAnalytics::percent((float) $row['units'], (float) max(1, $units));
            $html .= '<tr><td><strong>' . self::e($row['name']) . '</strong>';
            if ($row['group'] !== '') {
                $html .= '<small>' . self::e($row['group']) . '</small>';
            }
            $html .= '</td><td>' . number_format((int) $row['units']) . '</td><td>' . number_format((int) $row['orders']) . '</td>';
            $html .= '<td><div class="sst-share"><span style="width:' . number_format(min(100.0, $share), 2, '.', '') . '%"></span></div><small>' . number_format($share, 1) . '%</small></td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function ordersTable(array $rows, array $currency): string
    {
        if (!$rows) {
            return self::emptyState('No matching orders.');
        }
        $html = '<div class="sst-table-wrap sst-orders"><table class="sst-table"><thead><tr><th>Order</th><th>Date</th><th>Client</th><th>Origin</th><th>Value</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $origin = (int) $row['admin_requestor_id'] > 0 ? $row['agent_name'] : 'Self / web';
            $html .= '<tr><td><strong>#' . self::e($row['ordernum']) . '</strong><small>' . self::e($row['status']) . ($row['invoice_status'] ? ' / ' . self::e($row['invoice_status']) : '') . '</small></td>';
            $html .= '<td>' . self::e(substr((string) $row['date'], 0, 16)) . '</td><td>' . self::e($row['client_name']) . '</td><td>' . self::e($origin) . '</td><td>' . self::e(self::money((float) $row['amount'], $currency)) . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private static function emptyState(string $message): string
    {
        return '<div class="sst-empty">' . self::e($message) . '</div>';
    }

    /** @param array{id:int,code:string,prefix:string,suffix:string,default:bool} $currency */
    private static function money(float $amount, array $currency): string
    {
        $formatted = number_format($amount, 2, '.', ',');
        $prefix = trim($currency['prefix']);
        $suffix = trim($currency['suffix']);
        if ($prefix !== '') {
            return $prefix . $formatted . ($suffix !== '' ? ' ' . $suffix : '');
        }
        return $formatted . ' ' . ($suffix !== '' ? $suffix : $currency['code']);
    }

    /** @param array<string,mixed> $filter @return array<string,mixed> */
    private static function filterParams(array $filter): array
    {
        $params = [
            'from' => $filter['from'],
            'to' => $filter['to'],
            'currency' => $filter['currency_id'],
            'status' => $filter['statuses'],
        ];
        if ($filter['paid_only']) {
            $params['paid'] = 1;
        }
        return $params;
    }

    /** @param array<string,mixed> $params */
    private static function url(string $moduleLink, array $params): string
    {
        $separator = str_contains($moduleLink, '?') ? '&' : '?';
        return $moduleLink . ($params ? $separator . http_build_query($params) : '');
    }

    private static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
