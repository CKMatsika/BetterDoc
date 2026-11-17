<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditCheckpoint;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ImmutableAuditLogger
{
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Append a hash-chained immutable event to the audit ledger.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $tenantId,
        string $eventType,
        string $resourceType,
        ?string $resourceId = null,
        ?User $actor = null,
        int $classificationRank = 0,
        ?array $before = null,
        ?array $after = null,
        ?Request $request = null,
    ): AuditEvent {
        return DB::transaction(function () use (
            $tenantId,
            $eventType,
            $resourceType,
            $resourceId,
            $actor,
            $classificationRank,
            $before,
            $after,
            $request,
        ): AuditEvent {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $lockKey = $this->tenantLockKey($tenantId);
                DB::statement('SELECT pg_advisory_xact_lock(?)', [$lockKey]);
            }

            /** @var string|null $prevHash */
            $prevHash = AuditEvent::query()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('seq_id')
                ->value('entry_hash');

            $prevHash ??= self::GENESIS_HASH;
            $occurredAt = Carbon::now('UTC');
            $normalizedBefore = $before !== null ? $this->sortAssocRecursive($before) : null;
            $normalizedAfter = $after !== null ? $this->sortAssocRecursive($after) : null;

            $ipAddress = $request?->ip();
            $deviceId = $request !== null
                ? hash('sha256', (string) ($request->userAgent() ?? 'unknown'))
                : null;

            $entryHash = $this->computeHash(
                tenantId: $tenantId,
                occurredAt: $occurredAt,
                actorUserId: $actor?->id,
                eventType: $eventType,
                resourceType: $resourceType,
                resourceId: $resourceId,
                classificationRank: $classificationRank,
                before: $normalizedBefore,
                after: $normalizedAfter,
                prevHash: $prevHash,
            );

            $seqId = DB::table('audit_events')->insertGetId([
                'tenant_id' => $tenantId,
                'occurred_at' => $occurredAt->format('Y-m-d H:i:s.uP'),
                'actor_user_id' => $actor?->id,
                'actor_ip' => $ipAddress,
                'actor_device_id' => $deviceId,
                'event_type' => $eventType,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'classification_rank' => $classificationRank,
                'before_json' => $normalizedBefore !== null ? json_encode($normalizedBefore, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null,
                'after_json' => $normalizedAfter !== null ? json_encode($normalizedAfter, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null,
                'prev_hash' => $prevHash,
                'entry_hash' => $entryHash,
            ], 'seq_id');

            /** @var AuditEvent $event */
            $event = AuditEvent::query()->where('seq_id', $seqId)->firstOrFail();

            return $event;
        });
    }

    /**
     * Verify full cryptographic hash-chain integrity for a tenant.
     *
     * @return array{valid: bool, total_events: int, broken_at_seq_id: int|null, head_hash: string|null, reason: string|null}
     */
    public function verifyChain(Tenant $tenant): array
    {
        $expectedPrev = self::GENESIS_HASH;
        $total = 0;
        $headHash = null;

        $events = AuditEvent::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('seq_id')
            ->cursor();

        foreach ($events as $event) {
            $total++;
            if ($event->prev_hash !== $expectedPrev) {
                return [
                    'valid' => false,
                    'total_events' => $total,
                    'broken_at_seq_id' => $event->seq_id,
                    'head_hash' => $headHash,
                    'reason' => 'prev_hash mismatch at seq_id '.$event->seq_id,
                ];
            }

            $before = $event->before_json !== null ? $this->sortAssocRecursive($event->before_json) : null;
            $after = $event->after_json !== null ? $this->sortAssocRecursive($event->after_json) : null;

            $recomputed = $this->computeHash(
                tenantId: $event->tenant_id,
                occurredAt: $event->occurred_at->copy()->setTimezone('UTC'),
                actorUserId: $event->actor_user_id,
                eventType: $event->event_type,
                resourceType: $event->resource_type,
                resourceId: $event->resource_id,
                classificationRank: $event->classification_rank,
                before: $before,
                after: $after,
                prevHash: $event->prev_hash,
            );

            if (! hash_equals($recomputed, $event->entry_hash)) {
                return [
                    'valid' => false,
                    'total_events' => $total,
                    'broken_at_seq_id' => $event->seq_id,
                    'head_hash' => $headHash,
                    'reason' => 'entry_hash mismatch at seq_id '.$event->seq_id,
                ];
            }

            $expectedPrev = $event->entry_hash;
            $headHash = $event->entry_hash;
        }

        return [
            'valid' => true,
            'total_events' => $total,
            'broken_at_seq_id' => null,
            'head_hash' => $headHash,
            'reason' => null,
        ];
    }

    public function createCheckpoint(Tenant $tenant): ?AuditCheckpoint
    {
        $first = AuditEvent::query()->where('tenant_id', $tenant->id)->orderBy('seq_id')->first();
        $last = AuditEvent::query()->where('tenant_id', $tenant->id)->orderByDesc('seq_id')->first();

        if ($first === null || $last === null) {
            return null;
        }

        $count = AuditEvent::query()->where('tenant_id', $tenant->id)->count();
        $payload = implode('|', [
            $tenant->id,
            (string) $first->seq_id,
            (string) $last->seq_id,
            (string) $count,
            $last->entry_hash,
        ]);

        $signature = hash_hmac('sha256', $payload, config()->string('app.key'));

        return AuditCheckpoint::query()->create([
            'tenant_id' => $tenant->id,
            'period_start' => $first->occurred_at,
            'period_end' => $last->occurred_at,
            'first_seq_id' => $first->seq_id,
            'last_seq_id' => $last->seq_id,
            'event_count' => $count,
            'head_hash' => $last->entry_hash,
            'signature_hmac' => $signature,
        ]);
    }

    /**
     * Mask classified document details in audit payloads when viewer clearance is lower.
     *
     * @return array<string, mixed>
     */
    public function formatEventForViewer(AuditEvent $event, User $viewer): array
    {
        $masked = $event->classification_rank > $viewer->clearance_rank;

        return [
            'seq_id' => $event->seq_id,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'actor_user_id' => $event->actor_user_id,
            'actor_ip' => $event->actor_ip,
            'event_type' => $event->event_type,
            'resource_type' => $event->resource_type,
            'resource_id' => $event->resource_id,
            'classification_rank' => $event->classification_rank,
            'masked' => $masked,
            'before' => $masked ? ['redacted' => "[REDACTED - CLEARANCE RANK {$event->classification_rank}]"] : $event->before_json,
            'after' => $masked ? ['redacted' => "[REDACTED - CLEARANCE RANK {$event->classification_rank}]"] : $event->after_json,
            'prev_hash' => $event->prev_hash,
            'entry_hash' => $event->entry_hash,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function computeHash(
        string $tenantId,
        Carbon $occurredAt,
        ?string $actorUserId,
        string $eventType,
        string $resourceType,
        ?string $resourceId,
        int $classificationRank,
        ?array $before,
        ?array $after,
        string $prevHash,
    ): string {
        $canonical = json_encode([
            'tenant_id' => $tenantId,
            'occurred_at' => $occurredAt->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s.u\Z'),
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'classification_rank' => $classificationRank,
            'before' => $before,
            'after' => $after,
            'prev_hash' => $prevHash,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $canonical);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sortAssocRecursive(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortValueRecursive($value);
            }
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function sortValueRecursive(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->sortValueRecursive($item) : $item,
                $data
            );
        }

        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortValueRecursive($value);
            }
        }

        return $data;
    }

    private function tenantLockKey(string $tenantId): int
    {
        $bytes = hash('sha256', 'audit_chain|'.$tenantId, true);
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('q', substr($bytes, 0, 8));

        return $unpacked[1];
    }
}
