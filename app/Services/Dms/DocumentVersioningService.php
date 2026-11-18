<?php

declare(strict_types=1);

namespace App\Services\Dms;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentLock;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Crypto\EnvelopeEncryptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentVersioningService
{
    public function __construct(
        private readonly EnvelopeEncryptionService $crypto,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function checkout(Document $document, User $user, ?string $note = null, ?Request $request = null): DocumentLock
    {
        return DB::transaction(function () use ($document, $user, $note, $request): DocumentLock {
            /** @var DocumentLock|null $existing */
            $existing = DocumentLock::query()
                ->where('document_id', $document->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->isActive() && $existing->locked_by !== $user->id) {
                throw new RuntimeException('Document is already checked out by another officer.');
            }

            if ($existing !== null) {
                $existing->delete();
            }

            $lock = DocumentLock::query()->create([
                'document_id' => $document->id,
                'tenant_id' => $document->tenant_id,
                'locked_by' => $user->id,
                'lock_type' => 'checkout',
                'checkout_note' => $note,
                'locked_at' => now(),
                'expires_at' => now()->addHours(8),
            ]);

            $document->update(['status' => DocumentStatus::CheckedOut]);

            $this->audit->log(
                tenantId: $document->tenant_id,
                eventType: 'document.checked_out',
                resourceType: 'document',
                resourceId: $document->id,
                actor: $user,
                classificationRank: $document->classification_rank,
                after: ['note' => $note],
                request: $request,
            );

            return $lock;
        });
    }

    public function checkin(Document $document, User $user, ?Request $request = null): void
    {
        DB::transaction(function () use ($document, $user, $request): void {
            /** @var DocumentLock|null $lock */
            $lock = DocumentLock::query()->where('document_id', $document->id)->first();
            if ($lock !== null && $lock->locked_by !== $user->id && $lock->isActive()) {
                throw new RuntimeException('Only the checkout holder may check in this document.');
            }

            if ($lock !== null) {
                $lock->delete();
            }

            if ($document->status === DocumentStatus::CheckedOut) {
                $document->update(['status' => DocumentStatus::Active]);
            }

            $this->audit->log(
                tenantId: $document->tenant_id,
                eventType: 'document.checked_in',
                resourceType: 'document',
                resourceId: $document->id,
                actor: $user,
                classificationRank: $document->classification_rank,
                request: $request,
            );
        });
    }

    /**
     * Compute a line-by-line text comparison between two document versions.
     *
     * @return list<array{type: string, text: string}>
     */
    public function diffVersions(DocumentVersion $from, DocumentVersion $to): array
    {
        $aLines = explode("\n", str_replace("\r\n", "\n", (string) ($from->extracted_text ?? '')));
        $bLines = explode("\n", str_replace("\r\n", "\n", (string) ($to->extracted_text ?? '')));

        $n = count($aLines);
        $m = count($bLines);
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                if ($aLines[$i] === $bLines[$j]) {
                    $dp[$i][$j] = $dp[$i + 1][$j + 1] + 1;
                } else {
                    $dp[$i][$j] = max($dp[$i + 1][$j], $dp[$i][$j + 1]);
                }
            }
        }

        $diff = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($aLines[$i] === $bLines[$j]) {
                $diff[] = ['type' => 'unchanged', 'text' => $aLines[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $diff[] = ['type' => 'removed', 'text' => $aLines[$i]];
                $i++;
            } else {
                $diff[] = ['type' => 'added', 'text' => $bLines[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $diff[] = ['type' => 'removed', 'text' => $aLines[$i]];
            $i++;
        }
        while ($j < $m) {
            $diff[] = ['type' => 'added', 'text' => $bLines[$j]];
            $j++;
        }

        return $diff;
    }

    public function restoreVersion(
        Document $document,
        DocumentVersion $source,
        User $actor,
        ?Request $request = null,
    ): DocumentVersion {
        $handle = $this->crypto->decryptVersionToTempHandle($source);
        $tmpPath = tempnam(sys_get_temp_dir(), 'sent_rst_');
        if ($tmpPath === false) {
            fclose($handle);
            throw new RuntimeException('Unable to allocate temporary file for version restore.');
        }

        try {
            $dest = fopen($tmpPath, 'wb');
            if ($dest === false) {
                throw new RuntimeException('Unable to open temporary restore target.');
            }
            stream_copy_to_stream($handle, $dest);
            fclose($dest);
            fclose($handle);

            /** @var DocumentVersion|null $latest */
            $latest = DocumentVersion::query()
                ->where('document_id', $document->id)
                ->orderByDesc('version_major')
                ->first();
            $nextMajor = ($latest->version_major ?? 0) + 1;
            $newId = (string) Str::uuid7();
            $storageKey = "tenants/{$document->tenant_id}/docs/{$document->id}/v{$nextMajor}_0_{$newId}.enc";
            $enc = $this->crypto->encryptFileToDisk($tmpPath, 'betterdoc_vault', $storageKey);

            return DB::transaction(function () use ($newId, $document, $source, $nextMajor, $storageKey, $enc, $actor, $request): DocumentVersion {
                DocumentVersion::query()
                    ->where('document_id', $document->id)
                    ->where('is_current', true)
                    ->update(['is_current' => false]);

                /** @var DocumentVersion $restored */
                $restored = DocumentVersion::query()->create([
                    'id' => $newId,
                    'tenant_id' => $document->tenant_id,
                    'document_id' => $document->id,
                    'version_major' => $nextMajor,
                    'version_minor' => 0,
                    'is_current' => true,
                    'storage_disk' => 'betterdoc_vault',
                    'storage_key' => $storageKey,
                    'original_filename' => $source->original_filename,
                    'mime_type' => $source->mime_type,
                    'file_size_bytes' => $source->file_size_bytes,
                    'sha256_hash' => $source->sha256_hash,
                    'wrapped_dek' => $enc['wrapped_dek'],
                    'kek_key_id' => $enc['kek_key_id'],
                    'av_status' => $source->av_status,
                    'cdr_status' => $source->cdr_status,
                    'ocr_status' => $source->ocr_status,
                    'pdfa_compliant' => $source->pdfa_compliant,
                    'page_count' => $source->page_count,
                    'extracted_text' => $source->extracted_text,
                    'version_note' => "Restored from {$source->label()}",
                    'created_by' => $actor->id,
                ]);

                $document->update([
                    'current_version_id' => $restored->id,
                    'ocr_text' => $source->extracted_text,
                ]);

                $this->audit->log(
                    tenantId: $document->tenant_id,
                    eventType: 'document.version_restored',
                    resourceType: 'document',
                    resourceId: $document->id,
                    actor: $actor,
                    classificationRank: $document->classification_rank,
                    after: ['restored_from' => $source->label(), 'new_version' => $restored->label()],
                    request: $request,
                );

                return $restored;
            });
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }
}
