<?php
namespace ServerSpan\LogicBoxesTools;

use WHMCS\Database\Capsule;

final class LockRepository
{
    public function acquire(string $key, string $owner, int $ttlSeconds = 900): bool
    {
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + max(30, $ttlSeconds));
        Capsule::table(Schema::TABLE_LOCKS)->where('lock_key', $key)->where('expires_at', '<', $now)->delete();
        try {
            Capsule::table(Schema::TABLE_LOCKS)->insert([
                'lock_key' => $key,
                'owner' => $owner,
                'expires_at' => $expires,
                'created_at' => $now,
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function release(string $key, string $owner): void
    {
        Capsule::table(Schema::TABLE_LOCKS)->where('lock_key', $key)->where('owner', $owner)->delete();
    }
}
