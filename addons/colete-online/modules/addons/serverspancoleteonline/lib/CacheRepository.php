<?php

declare(strict_types=1);

namespace ServerSpan\WHMCS\ColeteOnline;

use DateTimeImmutable;
use WHMCS\Database\Capsule;

final class CacheRepository
{
    private const TABLE = 'mod_serverspan_coleteonline_cache';

    public function getToken(): ?array
    {
        $row = Capsule::table(self::TABLE)->where('cache_key', 'access_token')->first();
        if (!$row || empty($row->cache_value) || empty($row->expires_at)) {
            return null;
        }

        try {
            $expires = new DateTimeImmutable((string) $row->expires_at);
        } catch (\Throwable) {
            return null;
        }
        if ($expires->getTimestamp() <= time() + 60) {
            $this->forgetToken();
            return null;
        }

        try {
            $token = TokenCodec::decode((string) $row->cache_value);
        } catch (\Throwable) {
            $this->forgetToken();
            return null;
        }

        return ['token' => $token, 'expires_at' => $expires];
    }

    public function putToken(string $token, int $expiresIn): void
    {
        $expires = (new DateTimeImmutable())->modify('+' . max(60, $expiresIn - 60) . ' seconds');
        Capsule::table(self::TABLE)->updateOrInsert(
            ['cache_key' => 'access_token'],
            [
                'cache_value' => TokenCodec::encode($token),
                'expires_at' => $expires->format('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );
    }

    public function forgetToken(): void
    {
        Capsule::table(self::TABLE)->where('cache_key', 'access_token')->delete();
    }
}
