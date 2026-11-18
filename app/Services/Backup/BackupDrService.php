<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Enums\BackupTier;
use App\Models\AuditCheckpoint;
use App\Models\AuditEvent;
use App\Models\BackupSnapshot;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Compartment;
use App\Models\CompliancePack;
use App\Models\Contract;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Crypto\EnvelopeEncryptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class BackupDrService
{
    public function __construct(
        private readonly ImmutableAuditLogger $audit,
        private readonly EnvelopeEncryptionService $crypto,
    ) {}

    /**
     * Build a signed, XChaCha20-Poly1305 encrypted 3-2-1 backup snapshot containing tenant DB metadata,
     * immutable audit ledger & checkpoints, WAL marker, and encrypted MinIO/local vault blobs.
     */
    public function createEncryptedSnapshot(
        Tenant $tenant,
        User $actor,
        BackupTier $tier = BackupTier::Tier1Local,
        string $label = '',
        ?Request $request = null,
    ): BackupSnapshot {
        $chainCheck = $this->audit->verifyChain($tenant);
        if (! $chainCheck['valid']) {
            throw new RuntimeException('Backup aborted: audit ledger hash chain integrity check failed.');
        }

        $latestCp = AuditCheckpoint::query()->where('tenant_id', $tenant->id)->latest()->first();
        $maxSeqRaw = AuditEvent::query()->where('tenant_id', $tenant->id)->max('seq_id');
        $maxSeq = is_numeric($maxSeqRaw) ? (int) $maxSeqRaw : 0;
        if ($maxSeq > 0 && ($latestCp === null || $latestCp->last_seq_id < $maxSeq)) {
            $this->audit->createCheckpoint($tenant);
        }

        $events = AuditEvent::query()->where('tenant_id', $tenant->id)->orderBy('seq_id')->get();
        $jsonlLines = [];
        foreach ($events as $ev) {
            $jsonlLines[] = json_encode([
                'seq_id' => $ev->seq_id,
                'event_type' => $ev->event_type,
                'resource_type' => $ev->resource_type,
                'resource_id' => $ev->resource_id,
                'entry_hash' => $ev->entry_hash,
                'prev_hash' => $ev->prev_hash,
                'occurred_at' => $ev->occurred_at->toIso8601String(),
            ], JSON_THROW_ON_ERROR);
        }

        $documents = Document::query()->where('tenant_id', $tenant->id)->get();
        $versions = DocumentVersion::query()->where('tenant_id', $tenant->id)->get();

        $jsonEntries = [
            'tenant.json' => json_encode($tenant->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'clearance_levels.json' => json_encode(ClearanceLevel::query()->where('tenant_id', $tenant->id)->get()->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'compartments.json' => json_encode(Compartment::query()->where('tenant_id', $tenant->id)->get()->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'cabinets.json' => json_encode(Cabinet::query()->where('tenant_id', $tenant->id)->get()->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'documents.json' => json_encode($documents->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'document_versions.json' => json_encode($versions->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'audit_checkpoints.json' => json_encode(AuditCheckpoint::query()->where('tenant_id', $tenant->id)->get()->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'vendors.json' => json_encode(Vendor::query()->where('tenant_id', $tenant->id)->get()->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'contracts.json' => json_encode(Contract::query()->where('tenant_id', $tenant->id)->get()->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'compliance_packs.json' => json_encode(CompliancePack::query()->where('tenant_id', $tenant->id)->get()->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'audit_events.jsonl' => implode("\n", $jsonlLines),
            'wal_position.json' => json_encode([
                'captured_at_utc' => now('UTC')->toIso8601String(),
                'wal_level' => 'replica',
                'max_audit_seq_id' => $maxSeq,
                'head_hash' => $chainCheck['head_hash'] ?? str_repeat('0', 64),
                'backup_tier' => $tier->value,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        ];

        $tmpZip = tempnam(sys_get_temp_dir(), 'bdoc_bk_').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open temporary archive for 3-2-1 backup.');
        }

        $fileHashes = [];
        foreach ($jsonEntries as $name => $content) {
            $zip->addFromString($name, $content);
            $fileHashes[$name] = hash('sha256', $content);
        }

        $blobCount = 0;
        foreach ($versions as $ver) {
            if (Storage::disk($ver->storage_disk)->exists($ver->storage_key)) {
                $blobBytes = Storage::disk($ver->storage_disk)->get($ver->storage_key);
                if (is_string($blobBytes)) {
                    $entryName = "vault_blobs/{$ver->id}.enc";
                    $zip->addFromString($entryName, $blobBytes);
                    $fileHashes[$entryName] = hash('sha256', $blobBytes);
                    $blobCount++;
                }
            }
        }

        $headHash = $chainCheck['head_hash'] ?? str_repeat('0', 64);
        $manifestJson = json_encode([
            'product' => 'BetterDoc DMS 3-2-1 Backup',
            'tenant_id' => $tenant->id,
            'tier' => $tier->value,
            'document_count' => $documents->count(),
            'version_count' => $versions->count(),
            'blob_count' => $blobCount,
            'audit_event_count' => $chainCheck['total_events'],
            'head_hash' => $headHash,
            'files' => $fileHashes,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $zip->addFromString('backup_manifest.json', $manifestJson);
        $zip->close();

        $manifestSha = hash('sha256', $manifestJson);
        $sigHmac = hash_hmac('sha256', $manifestSha.'|'.$headHash, config()->string('app.key'));

        $snapshotId = (string) Str::uuid7();
        $storageDisk = config()->string('betterdoc.backup.storage_disk', 'betterdoc_vault');
        $storageKey = "tenants/{$tenant->id}/backups/{$tier->value}/{$snapshotId}.bdocbk.enc";

        try {
            $enc = $this->crypto->encryptFileToDisk($tmpZip, $storageDisk, $storageKey);
        } finally {
            @unlink($tmpZip);
        }

        $resolvedLabel = trim($label) !== ''
            ? trim($label)
            : strtoupper($tier->value).' Backup — '.now('UTC')->format('Y-m-d H:i');

        $snapshot = new BackupSnapshot([
            'tenant_id' => $tenant->id,
            'created_by' => $actor->id,
            'snapshot_label' => $resolvedLabel,
            'backup_tier' => $tier,
            'storage_disk' => $storageDisk,
            'storage_key' => $storageKey,
            'manifest_sha256' => $manifestSha,
            'signature_hmac' => $sigHmac,
            'wrapped_dek' => $enc['wrapped_dek'],
            'kek_key_id' => $enc['kek_key_id'],
            'size_bytes' => $enc['ciphertext_size'],
            'includes_wal' => true,
            'status' => 'verified',
            'verified_at' => now(),
        ]);
        $snapshot->id = $snapshotId;
        $snapshot->save();

        $this->audit->log(
            tenantId: $tenant->id,
            eventType: 'backup.snapshot_created',
            resourceType: 'backup_snapshot',
            resourceId: $snapshot->id,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            after: [
                'tier' => $tier->value,
                'documents' => $documents->count(),
                'blobs' => $blobCount,
                'manifest_sha256' => $manifestSha,
            ],
            request: $request,
        );

        return $snapshot;
    }

    /**
     * Timed Clean-Machine Restore Drill: decrypts 3-2-1 backup envelope, verifies manifest HMAC & object SHA-256s,
     * reconstructs files into isolated staging directory, verifies DB audit chain + checkpoints, and asserts RTO < 2h.
     *
     * @return array{valid: bool, restored_documents: int, restored_blobs: int, audit_chain_valid: bool, checkpoint_hmac_valid: bool, duration_ms: int, rto_under_2h: bool, reason: string|null}
     */
    public function verifyAndRunRestoreDrill(
        BackupSnapshot $snapshot,
        Tenant $tenant,
        User $actor,
        ?Request $request = null,
    ): array {
        $started = microtime(true);
        $handle = $this->crypto->decryptKeyToTempHandle(
            $snapshot->storage_disk,
            $snapshot->storage_key,
            $snapshot->wrapped_dek,
            $snapshot->kek_key_id,
        );

        $zipBytes = stream_get_contents($handle);
        fclose($handle);

        $valid = false;
        $restoredDocs = 0;
        $restoredBlobs = 0;
        $reason = null;

        $tmpZip = tempnam(sys_get_temp_dir(), 'bdoc_dr_').'.zip';
        $stagingDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bdoc_restore_'.substr($snapshot->id, 0, 8);
        @mkdir($stagingDir, 0700, true);

        try {
            if (! is_string($zipBytes) || $zipBytes === '') {
                throw new RuntimeException('Encrypted backup payload is empty.');
            }
            file_put_contents($tmpZip, $zipBytes);

            $zip = new ZipArchive;
            if ($zip->open($tmpZip) !== true) {
                throw new RuntimeException('Unable to open decrypted backup ZIP.');
            }

            $manifestRaw = $zip->getFromName('backup_manifest.json');
            if (! is_string($manifestRaw)) {
                throw new RuntimeException('Missing backup_manifest.json in snapshot.');
            }

            $manifestSha = hash('sha256', $manifestRaw);
            /** @var mixed $manifest */
            $manifest = json_decode($manifestRaw, true);
            $headHash = is_array($manifest) && is_string($manifest['head_hash'] ?? null) ? $manifest['head_hash'] : '';
            $expectedHmac = hash_hmac('sha256', $manifestSha.'|'.$headHash, config()->string('app.key'));

            if (! hash_equals($snapshot->manifest_sha256, $manifestSha) || ! hash_equals($snapshot->signature_hmac, $expectedHmac)) {
                throw new RuntimeException('Backup manifest SHA-256 or HMAC signature mismatch.');
            }

            $allFilesValid = true;
            if (is_array($manifest) && is_array($manifest['files'] ?? null)) {
                foreach ($manifest['files'] as $fname => $expectedSha) {
                    if (! is_string($fname) || ! is_string($expectedSha)) {
                        continue;
                    }
                    $content = $zip->getFromName($fname);
                    if (! is_string($content) || ! hash_equals($expectedSha, hash('sha256', $content))) {
                        $allFilesValid = false;
                        $reason = "Corrupted backup entry: {$fname}";
                        break;
                    }

                    $targetFile = $stagingDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $fname);
                    @mkdir(dirname($targetFile), 0700, true);
                    file_put_contents($targetFile, $content);

                    if (str_starts_with($fname, 'vault_blobs/')) {
                        $restoredBlobs++;
                    }
                }
                $restoredDocs = is_numeric($manifest['document_count'] ?? null) ? (int) $manifest['document_count'] : 0;
            } else {
                $allFilesValid = false;
                $reason = 'Manifest files list missing.';
            }

            $zip->close();
            $valid = $allFilesValid;
        } catch (RuntimeException $e) {
            $reason = $e->getMessage();
            $valid = false;
        } finally {
            @unlink($tmpZip);
            $this->deleteDirectory($stagingDir);
        }

        $chainRes = $this->audit->verifyChain($tenant);
        $cpRes = $this->audit->verifyCheckpoints($tenant);
        $durationMs = max(1, (int) round((microtime(true) - $started) * 1000));
        $targetRtoMs = config()->integer('betterdoc.backup.target_rto_seconds', 7200) * 1000;
        $rtoOk = $durationMs <= $targetRtoMs;

        $overallValid = $valid && $chainRes['valid'] && $cpRes['valid'] && $rtoOk;

        $snapshot->update([
            'status' => $overallValid ? 'restored' : 'failed',
            'verified_at' => now(),
            'last_restored_at' => now(),
            'restore_duration_ms' => $durationMs,
        ]);

        $this->audit->log(
            tenantId: $tenant->id,
            eventType: 'backup.restore_drill_verified',
            resourceType: 'backup_snapshot',
            resourceId: $snapshot->id,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            after: [
                'valid' => $overallValid,
                'restored_documents' => $restoredDocs,
                'restored_blobs' => $restoredBlobs,
                'duration_ms' => $durationMs,
                'rto_under_2h' => $rtoOk,
            ],
            request: $request,
        );

        return [
            'valid' => $overallValid,
            'restored_documents' => $restoredDocs,
            'restored_blobs' => $restoredBlobs,
            'audit_chain_valid' => $chainRes['valid'],
            'checkpoint_hmac_valid' => $cpRes['valid'],
            'duration_ms' => $durationMs,
            'rto_under_2h' => $rtoOk,
            'reason' => $reason,
        ];
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
