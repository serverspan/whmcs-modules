<?php

declare(strict_types=1);

namespace ServerSpan\WHMCS\SalesTracker;

use RuntimeException;
use WHMCS\Database\Capsule;

final class SalesRepository
{
    public function assertCompatible(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasColumn('tblorders', 'admin_requestor_id')) {
            throw new RuntimeException(
                'The WHMCS orders schema does not expose admin_requestor_id. '
                . 'ServerSpan Sales Tracker requires a modern WHMCS order schema.'
            );
        }
        if (!$schema->hasColumn('tblhosting', 'qty')) {
            throw new RuntimeException(
                'The WHMCS services schema does not expose qty. '
                . 'ServerSpan Sales Tracker requires a modern WHMCS service schema.'
            );
        }
    }

    /**
     * @return array<int,array{id:int,code:string,prefix:string,suffix:string,default:bool}>
     */
    public function currencies(): array
    {
        $rows = Capsule::table('tblcurrencies')
            ->select(['id', 'code', 'prefix', 'suffix', 'default'])
            ->orderBy('default', 'desc')
            ->orderBy('code', 'asc')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'prefix' => (string) $row->prefix,
                'suffix' => (string) $row->suffix,
                'default' => (bool) $row->default,
            ];
        }
        return $out;
    }

    /** @return string[] */
    public function orderStatuses(): array
    {
        $rows = Capsule::table('tblorderstatuses')
            ->select('title')
            ->orderBy('sortorder', 'asc')
            ->get();

        $statuses = [];
        foreach ($rows as $row) {
            $title = trim((string) $row->title);
            if ($title !== '') {
                $statuses[] = $title;
            }
        }
        return $statuses ?: ['Active', 'Pending', 'Cancelled', 'Fraud'];
    }

    /**
     * @param array{from:string,to:string,currency_id:int,statuses:string[],paid_only:bool,agent_id?:int} $filter
     * @return array{orders:int,value:float,average:float,agent_orders:int,self_orders:int}
     */
    public function summary(array $filter): array
    {
        $row = $this->baseOrderQuery($filter)
            ->selectRaw(
                'COUNT(*) AS orders, '
                . 'COALESCE(SUM(o.amount), 0) AS value, '
                . 'COALESCE(AVG(o.amount), 0) AS average, '
                . 'COALESCE(SUM(CASE WHEN o.admin_requestor_id > 0 THEN 1 ELSE 0 END), 0) AS agent_orders, '
                . 'COALESCE(SUM(CASE WHEN o.admin_requestor_id IS NULL OR o.admin_requestor_id = 0 THEN 1 ELSE 0 END), 0) AS self_orders'
            )
            ->first();

        return [
            'orders' => (int) ($row->orders ?? 0),
            'value' => (float) ($row->value ?? 0),
            'average' => (float) ($row->average ?? 0),
            'agent_orders' => (int) ($row->agent_orders ?? 0),
            'self_orders' => (int) ($row->self_orders ?? 0),
        ];
    }

    /**
     * @param array{from:string,to:string,currency_id:int,statuses:string[],paid_only:bool,agent_id?:int} $filter
     * @return array<int,array{bucket:string,orders:int,value:float}>
     */
    public function trend(array $filter, string $bucket): array
    {
        $expr = $this->bucketExpression($bucket);
        $rows = $this->baseOrderQuery($filter)
            ->selectRaw($expr . ' AS bucket, COUNT(*) AS orders, COALESCE(SUM(o.amount), 0) AS value')
            ->groupBy(Capsule::raw($expr))
            ->orderByRaw('MIN(o.date) ASC')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'bucket' => (string) $row->bucket,
                'orders' => (int) $row->orders,
                'value' => (float) $row->value,
            ];
        }
        return $out;
    }

    /**
     * @param array{from:string,to:string,currency_id:int,statuses:string[],paid_only:bool,agent_id?:int} $filter
     * @return array<int,array{id:int,name:string,username:string,orders:int,value:float,average:float}>
     */
    public function agents(array $filter, int $limit = 50): array
    {
        $rows = $this->baseOrderQuery($filter)
            ->leftJoin('tbladmins as a', 'o.admin_requestor_id', '=', 'a.id')
            ->where('o.admin_requestor_id', '>', 0)
            ->selectRaw(
                "o.admin_requestor_id AS id, "
                . "TRIM(CONCAT(COALESCE(a.firstname, ''), ' ', COALESCE(a.lastname, ''))) AS name, "
                . "COALESCE(a.username, '') AS username, "
                . 'COUNT(*) AS orders, COALESCE(SUM(o.amount), 0) AS value, COALESCE(AVG(o.amount), 0) AS average'
            )
            ->groupBy('o.admin_requestor_id', 'a.firstname', 'a.lastname', 'a.username')
            ->orderBy('value', 'desc')
            ->limit(max(1, min(200, $limit)))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $name = trim((string) $row->name);
            $out[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : 'Admin #' . $id,
                'username' => (string) $row->username,
                'orders' => (int) $row->orders,
                'value' => (float) $row->value,
                'average' => (float) $row->average,
            ];
        }
        return $out;
    }

    /**
     * @param array{from:string,to:string,currency_id:int,statuses:string[],paid_only:bool,agent_id?:int} $filter
     * @return array<int,array{id:int,name:string,group:string,units:int,orders:int}>
     */
    public function topProducts(array $filter, int $limit = 10): array
    {
        $query = Capsule::table('tblhosting as h')
            ->join('tblorders as o', 'h.orderid', '=', 'o.id')
            ->join('tblclients as c', 'o.userid', '=', 'c.id')
            ->leftJoin('tblinvoices as i', 'o.invoiceid', '=', 'i.id')
            ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
            ->leftJoin('tblproductgroups as pg', 'p.gid', '=', 'pg.id');

        $this->applyOrderFilters($query, $filter);

        $rows = $query
            ->selectRaw(
                "p.id AS id, p.name AS name, COALESCE(pg.name, '') AS product_group, "
                . 'COALESCE(SUM(CASE WHEN h.qty IS NULL OR h.qty < 1 THEN 1 ELSE h.qty END), 0) AS units, '
                . 'COUNT(DISTINCT o.id) AS orders'
            )
            ->groupBy('p.id', 'p.name', 'pg.name')
            ->orderBy('units', 'desc')
            ->orderBy('orders', 'desc')
            ->limit(max(1, min(100, $limit)))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'group' => (string) $row->product_group,
                'units' => (int) $row->units,
                'orders' => (int) $row->orders,
            ];
        }
        return $out;
    }

    /**
     * @param array{from:string,to:string,currency_id:int,statuses:string[],paid_only:bool,agent_id?:int} $filter
     */
    public function totalProductUnits(array $filter): int
    {
        $query = Capsule::table('tblhosting as h')
            ->join('tblorders as o', 'h.orderid', '=', 'o.id')
            ->join('tblclients as c', 'o.userid', '=', 'c.id')
            ->leftJoin('tblinvoices as i', 'o.invoiceid', '=', 'i.id');

        $this->applyOrderFilters($query, $filter);
        $row = $query
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN h.qty IS NULL OR h.qty < 1 THEN 1 ELSE h.qty END), 0) AS units'
            )
            ->first();

        return (int) ($row->units ?? 0);
    }

    /**
     * @param array{from:string,to:string,currency_id:int,statuses:string[],paid_only:bool,agent_id?:int} $filter
     * @return array<int,array<string,mixed>>
     */
    public function recentOrders(array $filter, int $limit = 100): array
    {
        $rows = $this->baseOrderQuery($filter)
            ->leftJoin('tbladmins as a', 'o.admin_requestor_id', '=', 'a.id')
            ->selectRaw(
                "o.id, o.ordernum, o.date, o.amount, o.status, o.invoiceid, "
                . "COALESCE(i.status, '') AS invoice_status, "
                . "CONCAT(COALESCE(c.firstname, ''), ' ', COALESCE(c.lastname, '')) AS client_name, "
                . "o.admin_requestor_id, TRIM(CONCAT(COALESCE(a.firstname, ''), ' ', COALESCE(a.lastname, ''))) AS agent_name"
            )
            ->orderBy('o.date', 'desc')
            ->limit(max(1, min(500, $limit)))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $agentId = (int) ($row->admin_requestor_id ?? 0);
            $agentName = trim((string) ($row->agent_name ?? ''));
            $out[] = [
                'id' => (int) $row->id,
                'ordernum' => (string) $row->ordernum,
                'date' => (string) $row->date,
                'amount' => (float) $row->amount,
                'status' => (string) $row->status,
                'invoice_id' => (int) $row->invoiceid,
                'invoice_status' => (string) $row->invoice_status,
                'client_name' => trim((string) $row->client_name),
                'admin_requestor_id' => $agentId,
                'agent_name' => $agentName !== '' ? $agentName : ($agentId > 0 ? 'Admin #' . $agentId : ''),
            ];
        }
        return $out;
    }

    /**
     * @param array{from:string,to:string,currency_id:int,statuses:string[],paid_only:bool,agent_id?:int} $filter
     */
    private function baseOrderQuery(array $filter)
    {
        $query = Capsule::table('tblorders as o')
            ->join('tblclients as c', 'o.userid', '=', 'c.id')
            ->leftJoin('tblinvoices as i', 'o.invoiceid', '=', 'i.id');

        $this->applyOrderFilters($query, $filter);
        return $query;
    }

    /**
     * @param mixed $query
     * @param array{from:string,to:string,currency_id:int,statuses:string[],paid_only:bool,agent_id?:int} $filter
     */
    private function applyOrderFilters($query, array $filter): void
    {
        $query
            ->where('o.date', '>=', $filter['from'] . ' 00:00:00')
            ->where('o.date', '<=', $filter['to'] . ' 23:59:59')
            ->where('c.currency', '=', (int) $filter['currency_id'])
            ->whereIn('o.status', $filter['statuses']);

        if (!empty($filter['paid_only'])) {
            $query->where('i.status', '=', 'Paid');
        }
        if (!empty($filter['agent_id'])) {
            $query->where('o.admin_requestor_id', '=', (int) $filter['agent_id']);
        }
    }

    private function bucketExpression(string $bucket): string
    {
        if ($bucket === 'month') {
            return "DATE_FORMAT(o.date, '%Y-%m')";
        }
        if ($bucket === 'week') {
            return "DATE_FORMAT(o.date, '%x-W%v')";
        }
        return 'DATE(o.date)';
    }
}
