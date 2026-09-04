<?php

declare(strict_types=1);

namespace ServerSpan\WHMCS\ColeteOnline;

use WHMCS\Database\Capsule;

final class ShipmentRepository
{
    public const TABLE = 'mod_serverspan_coleteonline_shipments';
    public const CACHE_TABLE = 'mod_serverspan_coleteonline_cache';

    public static function install(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable(self::CACHE_TABLE)) {
            $schema->create(self::CACHE_TABLE, static function ($table): void {
                $table->string('cache_key', 100)->primary();
                $table->text('cache_value');
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, static function ($table): void {
                $table->increments('id');
                $table->unsignedInteger('order_id')->index();
                $table->unsignedInteger('client_id')->index();
                $table->unsignedInteger('invoice_id')->nullable()->index();
                $table->string('unique_id', 100)->unique();
                $table->string('awb', 100)->nullable()->index();
                $table->string('service_id', 100)->nullable();
                $table->string('courier_name', 150)->nullable();
                $table->string('service_name', 200)->nullable();
                $table->decimal('price_total', 14, 2)->nullable();
                $table->decimal('price_no_vat', 14, 2)->nullable();
                $table->string('price_currency', 3)->default('RON');
                $table->string('estimated_pickup_date', 64)->nullable();
                $table->string('last_status_code', 100)->nullable();
                $table->string('last_status_text', 255)->nullable();
                $table->dateTime('last_status_at')->nullable();
                $table->mediumText('tracking_json')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
        }
    }

    public static function uninstallCache(): void
    {
        Capsule::schema()->dropIfExists(self::CACHE_TABLE);
    }

    public function createFromApi(int $orderId, int $clientId, ?int $invoiceId, array $response): int
    {
        $parsed = self::parseCreateResponse($response);
        if ($parsed['unique_id'] === '') {
            throw new \RuntimeException('Colete-Online create-order response did not include uniqueId.');
        }

        $existing = Capsule::table(self::TABLE)->where('unique_id', $parsed['unique_id'])->first();
        if ($existing) {
            return (int) $existing->id;
        }

        return (int) Capsule::table(self::TABLE)->insertGetId([
            'order_id' => $orderId,
            'client_id' => $clientId,
            'invoice_id' => $invoiceId ?: null,
            'unique_id' => $parsed['unique_id'],
            'awb' => $parsed['awb'] ?: null,
            'service_id' => $parsed['service_id'] ?: null,
            'courier_name' => $parsed['courier_name'] ?: null,
            'service_name' => $parsed['service_name'] ?: null,
            'price_total' => $parsed['price_total'],
            'price_no_vat' => $parsed['price_no_vat'],
            'price_currency' => 'RON',
            'estimated_pickup_date' => $parsed['estimated_pickup_date'] ?: null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function updateStatus(int $shipmentId, array $status): void
    {
        $parsed = StatusParser::latest($status);
        Capsule::table(self::TABLE)->where('id', $shipmentId)->update([
            'last_status_code' => $parsed['code'] ?: null,
            'last_status_text' => $parsed['text'] ?: null,
            'last_status_at' => $parsed['date_time'] ?: date('Y-m-d H:i:s'),
            'tracking_json' => Support::safeJson($status),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function find(int $id): ?array
    {
        $row = Capsule::table(self::TABLE)->where('id', $id)->first();
        return $row ? (array) $row : null;
    }

    public function forOrder(int $orderId): array
    {
        return array_map(static fn($row): array => (array) $row, Capsule::table(self::TABLE)
            ->where('order_id', $orderId)
            ->orderByDesc('id')
            ->get()
            ->all());
    }

    public function recent(int $limit = 50): array
    {
        return array_map(static fn($row): array => (array) $row, Capsule::table(self::TABLE . ' as s')
            ->leftJoin('tblorders as o', 'o.id', '=', 's.order_id')
            ->leftJoin('tblclients as c', 'c.id', '=', 's.client_id')
            ->select([
                's.*', 'o.ordernum', 'c.firstname', 'c.lastname', 'c.companyname',
            ])
            ->orderByDesc('s.id')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->all());
    }

    public function countForOrder(int $orderId): int
    {
        return (int) Capsule::table(self::TABLE)->where('order_id', $orderId)->count();
    }

    public static function parseCreateResponse(array $response): array
    {
        if (!isset($response['uniqueId']) && isset($response['data']) && is_array($response['data'])) {
            $response = $response['data'];
        }
        $container = [];
        if (isset($response['curierService']) && is_array($response['curierService'])) {
            $container = $response['curierService'];
        }
        $service = [];
        $price = [];
        if (isset($container['service']) && is_array($container['service'])) {
            $service = $container['service'];
        } elseif (isset($response['service']) && is_array($response['service'])) {
            $service = $response['service'];
        }
        if (isset($container['price']) && is_array($container['price'])) {
            $price = $container['price'];
        } elseif (isset($response['price']) && is_array($response['price'])) {
            $price = $response['price'];
        }

        return [
            'unique_id' => (string) ($response['uniqueId'] ?? ''),
            'awb' => (string) ($response['awb'] ?? ''),
            'service_id' => (string) ($service['id'] ?? $service['activationId'] ?? ''),
            'courier_name' => (string) ($service['courierName'] ?? $service['courier'] ?? ''),
            'service_name' => (string) ($service['displayName'] ?? $service['name'] ?? ''),
            'price_total' => self::nullableFloat($price['total'] ?? null),
            'price_no_vat' => self::nullableFloat($price['noVat'] ?? $price['no_vat'] ?? null),
            'estimated_pickup_date' => (string) ($response['estimatedPickUpDate'] ?? $response['estimatedPickupDate'] ?? ''),
        ];
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
