<?php

declare(strict_types=1);

namespace ServerSpan\WHMCS\ColeteOnline;

use WHMCS\Database\Capsule;

final class OrderRepository
{
    public function find(int $orderId): ?array
    {
        $order = Capsule::table('tblorders as o')
            ->join('tblclients as c', 'c.id', '=', 'o.userid')
            ->leftJoin('tblcurrencies as cur', 'cur.id', '=', 'c.currency')
            ->select([
                'o.id', 'o.ordernum', 'o.userid', 'o.contactid', 'o.invoiceid', 'o.date', 'o.amount', 'o.status',
                'c.firstname', 'c.lastname', 'c.companyname', 'c.email', 'c.phonenumber',
                'c.address1', 'c.address2', 'c.city', 'c.state', 'c.postcode', 'c.country',
                'cur.code as currency_code',
            ])
            ->where('o.id', $orderId)
            ->first();
        if (!$order) {
            return null;
        }
        $data = (array) $order;

        if ((int) $data['contactid'] > 0) {
            $contact = Capsule::table('tblcontacts')
                ->where('id', (int) $data['contactid'])
                ->where('userid', (int) $data['userid'])
                ->first();
            if ($contact) {
                foreach (['firstname','lastname','companyname','email','phonenumber','address1','address2','city','state','postcode','country'] as $field) {
                    $value = $contact->{$field} ?? null;
                    if ($value !== null && trim((string) $value) !== '') {
                        $data[$field] = (string) $value;
                    }
                }
            }
        }

        $data['recipient_name'] = trim((string) $data['firstname'] . ' ' . (string) $data['lastname']);
        if ($data['recipient_name'] === '') {
            $data['recipient_name'] = (string) ($data['companyname'] ?: 'WHMCS client #' . $data['userid']);
        }
        [$street, $number] = self::splitStreetAndNumber((string) $data['address1']);
        $data['street'] = $street;
        $data['number'] = $number;
        $data['currency_code'] = strtoupper((string) ($data['currency_code'] ?: 'RON'));
        return $data;
    }

    public function recent(int $limit = 50): array
    {
        return array_map(static fn($row): array => (array) $row, Capsule::table('tblorders as o')
            ->join('tblclients as c', 'c.id', '=', 'o.userid')
            ->leftJoin(ShipmentRepository::TABLE . ' as s', 's.order_id', '=', 'o.id')
            ->selectRaw('o.id, o.ordernum, o.date, o.amount, o.status, o.userid, c.firstname, c.lastname, c.companyname, COUNT(s.id) AS shipment_count')
            ->groupBy('o.id', 'o.ordernum', 'o.date', 'o.amount', 'o.status', 'o.userid', 'c.firstname', 'c.lastname', 'c.companyname')
            ->orderByDesc('o.id')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->all());
    }

    public static function splitStreetAndNumber(string $address): array
    {
        $address = trim(preg_replace('/\s+/', ' ', $address) ?? $address);
        if ($address === '') {
            return ['', ''];
        }

        if (preg_match('/^(.*?)(?:\s+(?:nr\.?\s*)?)(\d+[A-Za-z0-9\/-]*)$/iu', $address, $m)) {
            return [trim($m[1], " ,"), trim($m[2])];
        }
        if (preg_match('/^(.*?)[,]\s*(?:nr\.?\s*)?(\d+[A-Za-z0-9\/-]*)$/iu', $address, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return [$address, ''];
    }
}
