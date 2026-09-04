<?php
namespace ServerSpan\LogicBoxesTools;

use WHMCS\Database\Capsule;

final class JobRepository
{
    public function create(string $type, string $mode, ?int $accountId, array $context = [], ?int $adminId = null): string
    {
        $id = self::uuidV4();
        Capsule::table(Schema::TABLE_JOBS)->insert([
            'id' => $id,
            'account_id' => $accountId,
            'type' => $type,
            'mode' => $mode,
            'status' => 'queued',
            'created_by_admin_id' => $adminId,
            'context' => Support::json($context),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    public function addItem(string $jobId, string $entityType, string $entityKey, ?array $before, array $proposed): void
    {
        $now = date('Y-m-d H:i:s');
        Capsule::table(Schema::TABLE_JOB_ITEMS)->updateOrInsert(
            ['job_id' => $jobId, 'entity_type' => $entityType, 'entity_key' => $entityKey],
            [
                'status' => 'pending',
                'before_json' => $before === null ? null : Support::json($before),
                'proposed_json' => Support::json($proposed),
                'applied_json' => null,
                'error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function start(string $jobId): void
    {
        Capsule::table(Schema::TABLE_JOBS)->where('id', $jobId)->update([
            'status' => 'running', 'started_at' => date('Y-m-d H:i:s'), 'last_error' => null,
        ]);
    }

    public function itemApplied(int $itemId, array $applied): void
    {
        Capsule::table(Schema::TABLE_JOB_ITEMS)->where('id', $itemId)->update([
            'status' => 'applied',
            'applied_json' => Support::json($applied),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function itemFailed(int $itemId, string $error): void
    {
        Capsule::table(Schema::TABLE_JOB_ITEMS)->where('id', $itemId)->update([
            'status' => 'failed',
            'error' => substr($error, 0, 4000),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function pendingItems(string $jobId, int $limit = 100): array
    {
        return array_map(fn($r) => (array) $r, Capsule::table(Schema::TABLE_JOB_ITEMS)
            ->where('job_id', $jobId)->where('status', 'pending')->orderBy('id')->limit($limit)->get()->all());
    }

    public function recalculate(string $jobId): void
    {
        $total = Capsule::table(Schema::TABLE_JOB_ITEMS)->where('job_id', $jobId)->count();
        $applied = Capsule::table(Schema::TABLE_JOB_ITEMS)->where('job_id', $jobId)->where('status', 'applied')->count();
        $failed = Capsule::table(Schema::TABLE_JOB_ITEMS)->where('job_id', $jobId)->where('status', 'failed')->count();
        $pending = Capsule::table(Schema::TABLE_JOB_ITEMS)->where('job_id', $jobId)->where('status', 'pending')->count();
        $status = $pending > 0 ? 'running' : ($failed > 0 ? ($applied > 0 ? 'partial' : 'failed') : 'completed');
        Capsule::table(Schema::TABLE_JOBS)->where('id', $jobId)->update([
            'total_items' => $total,
            'processed_items' => $applied + $failed,
            'changed_items' => $applied,
            'failed_items' => $failed,
            'status' => $status,
            'completed_at' => $pending === 0 ? date('Y-m-d H:i:s') : null,
        ]);
    }

    public function get(string $jobId): ?array
    {
        $row = Capsule::table(Schema::TABLE_JOBS)->where('id', $jobId)->first();
        if (!$row) {
            return null;
        }
        $job = (array) $row;
        $job['context'] = Support::fromJson($job['context'] ?? null);
        return $job;
    }

    public function recent(int $limit = 30): array
    {
        return array_map(fn($r) => (array) $r, Capsule::table(Schema::TABLE_JOBS)->orderByDesc('created_at')->limit($limit)->get()->all());
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
