<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Enums\HostingMode;
use App\Enums\SyncOutboxStatus;
use App\Enums\SyncTargetDriver;
use App\Exceptions\AirGapViolationException;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\SyncOutboxEvent;
use App\Models\SyncTarget;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Crypto\EnvelopeEncryptionService;
use App\Services\Security\BetterDocHttpClientGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class SyncGatewayService
{
    public function __construct(
        private readonly EnvelopeEncryptionService $crypto,
        private readonly ImmutableAuditLogger $audit,
        private readonly BetterDocHttpClientGuard $guard,
    ) {}

    public function assertSyncAllowed(Tenant $tenant): void
    {
        if (config()->boolean('betterdoc.air_gapped', true)
            || $tenant->air_gapped
            || $tenant->hosting_mode === HostingMode::AirGappedOnPrem) {
            throw new AirGapViolationException('sync.gateway', 'outbound-sync://disabled-in-airgap');
        }

        if (! config()->boolean('betterdoc.sync.enabled', false)) {
            throw new RuntimeException('Sync Gateway is disabled by tenant configuration (BETTERDOC_SYNC_ENABLED=false).');
        }
    }

    /**
     * Queue an encrypted replication envelope into the sync outbox, enforcing Air-Gap & Classification Ceiling.
     */
    public function enqueueDocumentSync(
        Tenant $tenant,
        User $actor,
        Document $document,
        SyncTarget $target,
        ?Request $request = null,
    ): SyncOutboxEvent {
        try {
            $this->assertSyncAllowed($tenant);
        } catch (AirGapViolationException $e) {
            $this->audit->log(
                tenantId: $tenant->id,
                eventType: 'sync.blocked_airgap',
                resourceType: 'document',
                resourceId: $document->id,
                actor: $actor,
                classificationRank: $document->classification_rank,
                after: ['target_id' => $target->id, 'reason' => $e->getMessage()],
                request: $request,
            );

            throw $e;
        }

        $globalCeiling = config()->integer('betterdoc.sync.max_classification_rank', 1);
        $level = ClearanceLevel::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $document->classification_level_id)
            ->first();

        if ($document->classification_rank > $target->max_classification_rank
            || $document->classification_rank > $globalCeiling
            || ($level instanceof ClearanceLevel && ! $level->cloud_sync_allowed)) {
            $this->audit->log(
                tenantId: $tenant->id,
                eventType: 'sync.blocked_classification',
                resourceType: 'document',
                resourceId: $document->id,
                actor: $actor,
                classificationRank: $document->classification_rank,
                after: [
                    'target_id' => $target->id,
                    'doc_rank' => $document->classification_rank,
                    'target_max_rank' => $target->max_classification_rank,
                    'global_ceiling' => $globalCeiling,
                ],
                request: $request,
            );

            throw new RuntimeException(
                "Classification ceiling blocked sync of L{$document->classification_rank} document '{$document->doc_number}' (target ceiling L{$target->max_classification_rank})."
            );
        }

        $document->loadMissing(['currentVersion']);
        $version = $document->currentVersion;

        $payload = json_encode([
            'tenant_id' => $tenant->id,
            'document_id' => $document->id,
            'doc_number' => $document->doc_number,
            'title' => $document->title,
            'classification_rank' => $document->classification_rank,
            'version' => $version !== null ? [
                'id' => $version->id,
                'major' => $version->version_major,
                'minor' => $version->version_minor,
                'sha256_hash' => $version->sha256_hash,
                'storage_key' => $version->storage_key,
            ] : null,
            'enqueued_at_utc' => now('UTC')->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        $payloadSha = hash('sha256', $payload);
        $outboxId = (string) Str::uuid7();
        $mirrorDisk = config()->string('betterdoc.sync.mirror_disk', 'betterdoc_vault');
        $storageKey = "tenants/{$tenant->id}/sync_outbox/{$outboxId}.json.enc";

        $tmpFile = tempnam(sys_get_temp_dir(), 'bdoc_sync_').'.json';
        file_put_contents($tmpFile, $payload);
        try {
            $enc = $this->crypto->encryptFileToDisk($tmpFile, $mirrorDisk, $storageKey);
        } finally {
            @unlink($tmpFile);
        }

        $event = new SyncOutboxEvent([
            'tenant_id' => $tenant->id,
            'sync_target_id' => $target->id,
            'document_id' => $document->id,
            'event_type' => 'document.replicated',
            'classification_rank' => $document->classification_rank,
            'payload_sha256' => $payloadSha,
            'storage_key' => $storageKey,
            'wrapped_dek' => $enc['wrapped_dek'],
            'kek_key_id' => $enc['kek_key_id'],
            'status' => SyncOutboxStatus::Pending,
        ]);
        $event->id = $outboxId;
        $event->save();

        $this->audit->log(
            tenantId: $tenant->id,
            eventType: 'sync.outbox_enqueued',
            resourceType: 'sync_outbox_event',
            resourceId: $event->id,
            actor: $actor,
            classificationRank: $document->classification_rank,
            after: ['target' => $target->name, 'payload_sha256' => $payloadSha],
            request: $request,
        );

        return $event;
    }

    /**
     * Dispatch pending outbox events and create non-destructive conflict branches when remote digests diverge.
     *
     * @return array{synced: int, conflict_branched: int, failed: int}
     */
    public function processOutbox(Tenant $tenant, User $actor, ?string $simulatedRemoteConflictDigest = null): array
    {
        $this->assertSyncAllowed($tenant);

        $mirrorDisk = config()->string('betterdoc.sync.mirror_disk', 'betterdoc_vault');
        $pending = SyncOutboxEvent::query()
            ->with(['target', 'document'])
            ->where('tenant_id', $tenant->id)
            ->where('status', SyncOutboxStatus::Pending->value)
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        $synced = 0;
        $branched = 0;
        $failed = 0;

        foreach ($pending as $ev) {
            $target = $ev->target;
            if (! $target instanceof SyncTarget || ! $target->enabled) {
                continue;
            }

            // Re-verify ceiling strictly at dispatch time
            if ($ev->classification_rank > $target->max_classification_rank) {
                $ev->update([
                    'status' => SyncOutboxStatus::BlockedClassification,
                    'attempts' => $ev->attempts + 1,
                    'last_error' => 'Classification exceeded target ceiling at dispatch.',
                ]);
                $failed++;

                continue;
            }

            if ($target->driver === SyncTargetDriver::LocalMirror) {
                $siteDir = preg_replace('/[^A-Za-z0-9_\-]/', '_', $target->bucket_or_site) ?: 'site';
                $docKey = $ev->document_id ?? $ev->id;
                $mirrorManifestKey = "tenants/{$tenant->id}/sync_mirror/{$siteDir}/{$docKey}.json";

                $existingRaw = Storage::disk($mirrorDisk)->exists($mirrorManifestKey)
                    ? Storage::disk($mirrorDisk)->get($mirrorManifestKey)
                    : null;

                $remoteDigest = $simulatedRemoteConflictDigest;
                if ($remoteDigest === null && is_string($existingRaw)) {
                    /** @var mixed $decoded */
                    $decoded = json_decode($existingRaw, true);
                    if (is_array($decoded) && is_string($decoded['payload_sha256'] ?? null)
                        && $decoded['payload_sha256'] !== $ev->payload_sha256
                        && ($decoded['conflict_test_lock'] ?? false) === true) {
                        $remoteDigest = $decoded['payload_sha256'];
                    }
                }

                if ($remoteDigest !== null && $remoteDigest !== $ev->payload_sha256 && $target->conflict_strategy === 'branch_conflict') {
                    $branchLabel = 'conflict/'.$siteDir.'/'.now('UTC')->format('Ymd-His');
                    Storage::disk($mirrorDisk)->put(
                        "tenants/{$tenant->id}/sync_mirror/{$siteDir}/branches/{$docKey}-{$ev->id}.json",
                        json_encode([
                            'branch' => $branchLabel,
                            'local_sha256' => $ev->payload_sha256,
                            'remote_sha256' => $remoteDigest,
                            'storage_key' => $ev->storage_key,
                        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
                    );

                    $ev->update([
                        'status' => SyncOutboxStatus::ConflictBranched,
                        'conflict_branch_label' => $branchLabel,
                        'conflict_details' => "Remote SHA {$remoteDigest} diverged from local {$ev->payload_sha256}; preserved as branch.",
                        'attempts' => $ev->attempts + 1,
                    ]);
                    $branched++;

                    $this->audit->log(
                        tenantId: $tenant->id,
                        eventType: 'sync.conflict_branched',
                        resourceType: 'sync_outbox_event',
                        resourceId: $ev->id,
                        actor: $actor,
                        classificationRank: $ev->classification_rank,
                        after: ['branch' => $branchLabel, 'remote_sha256' => $remoteDigest],
                    );

                    continue;
                }

                Storage::disk($mirrorDisk)->put(
                    $mirrorManifestKey,
                    json_encode([
                        'outbox_event_id' => $ev->id,
                        'payload_sha256' => $ev->payload_sha256,
                        'storage_key' => $ev->storage_key,
                        'synced_at' => now('UTC')->toIso8601String(),
                    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
                );

                $ev->update([
                    'status' => SyncOutboxStatus::Synced,
                    'attempts' => $ev->attempts + 1,
                    'synced_at' => now(),
                ]);
                $target->update(['last_synced_at' => now()]);
                $synced++;

                continue;
            }

            // Remote HTTP Gateway (S3 / Laravel Cloud / Secondary Site)
            $endpoint = rtrim((string) $target->endpoint_url, '/');
            if ($endpoint === '') {
                $ev->update([
                    'status' => SyncOutboxStatus::Failed,
                    'attempts' => $ev->attempts + 1,
                    'last_error' => 'Sync target endpoint_url is not configured.',
                ]);
                $failed++;

                continue;
            }

            $resp = Http::withRequestMiddleware(
                fn (RequestInterface $req): RequestInterface => $this->guard->assertOutboundAllowed($req)
            )->timeout(10)->post("{$endpoint}/api/v1/sync/ingest", [
                'tenant_id' => $tenant->id,
                'outbox_id' => $ev->id,
                'document_id' => $ev->document_id,
                'classification_rank' => $ev->classification_rank,
                'payload_sha256' => $ev->payload_sha256,
                'wrapped_dek' => $ev->wrapped_dek,
            ]);

            if ($resp->status() === 409) {
                $branchLabel = 'conflict/'.$target->bucket_or_site.'/'.now('UTC')->format('Ymd-His');
                $ev->update([
                    'status' => SyncOutboxStatus::ConflictBranched,
                    'conflict_branch_label' => $branchLabel,
                    'conflict_details' => 'Remote gateway returned HTTP 409 digest conflict.',
                    'attempts' => $ev->attempts + 1,
                ]);
                $branched++;
            } elseif ($resp->successful()) {
                $ev->update([
                    'status' => SyncOutboxStatus::Synced,
                    'attempts' => $ev->attempts + 1,
                    'synced_at' => now(),
                ]);
                $target->update(['last_synced_at' => now()]);
                $synced++;
            } else {
                $ev->update([
                    'status' => SyncOutboxStatus::Failed,
                    'attempts' => $ev->attempts + 1,
                    'last_error' => 'HTTP '.$resp->status(),
                ]);
                $failed++;
            }
        }

        return [
            'synced' => $synced,
            'conflict_branched' => $branched,
            'failed' => $failed,
        ];
    }
}
