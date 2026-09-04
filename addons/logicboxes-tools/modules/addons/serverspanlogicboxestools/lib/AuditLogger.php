<?php
namespace ServerSpan\LogicBoxesTools;

use WHMCS\Database\Capsule;

final class AuditLogger
{
    public function write(
        string $action,
        ?int $accountId = null,
        ?string $entityType = null,
        ?string $entityKey = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        ?int $adminId = null,
        string $actor = 'system'
    ): void {
        Capsule::table(Schema::TABLE_AUDIT)->insert([
            'account_id' => $accountId,
            'admin_id' => $adminId,
            'actor' => $actor,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'before_json' => $before === null ? null : Support::json(Support::redact($before)),
            'after_json' => $after === null ? null : Support::json(Support::redact($after)),
            'metadata' => $metadata === [] ? null : Support::json(Support::redact($metadata)),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
