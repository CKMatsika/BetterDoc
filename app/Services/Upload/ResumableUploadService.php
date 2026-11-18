<?php

declare(strict_types=1);

namespace App\Services\Upload;

use App\Enums\AvStatus;
use App\Enums\CdrStatus;
use App\Enums\UploadStatus;
use App\Exceptions\MalwareDetectedException;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\ProcessingJob;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Crypto\EnvelopeEncryptionService;
use App\Services\Ingestion\CdrSanitizerService;
use App\Services\Ingestion\MalwareScannerService;
use App\Services\Ingestion\OcrAndConversionService;
use App\Services\Search\DocumentSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ResumableUploadService
{
    public function __construct(
        private readonly MalwareScannerService $malwareScanner,
        private readonly CdrSanitizerService $cdr,
        private readonly OcrAndConversionService $ocr,
        private readonly EnvelopeEncryptionService $crypto,
        private readonly DocumentSearchService $search,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function initiate(
        User $user,
        string $filename,
        string $mimeType,
        int $totalSize,
        ?string $sha256Expected = null,
        ?string $documentId = null,
        ?int $chunkSize = null,
    ): UploadSession {
        $maxSize = config()->integer('sentinel.upload.max_size_bytes', 2147483648);
        if ($totalSize <= 0 || $totalSize > $maxSize) {
            throw new RuntimeException('File size exceeds allowed bounds.');
        }

        $effectiveChunkSize = $chunkSize ?? config()->integer('sentinel.upload.chunk_size_bytes', 5242880);
        $effectiveChunkSize = max(65536, $effectiveChunkSize);
        $totalChunks = (int) ceil($totalSize / $effectiveChunkSize);
        $ttlHours = config()->integer('sentinel.upload.session_ttl_hours', 48);
        $sessionUuid = (string) Str::uuid7();

        return UploadSession::query()->create([
            'id' => $sessionUuid,
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'document_id' => $documentId,
            'filename' => basename($filename),
            'mime_type' => $mimeType,
            'total_size' => $totalSize,
            'chunk_size' => $effectiveChunkSize,
            'total_chunks' => $totalChunks,
            'uploaded_parts_json' => [],
            'sha256_expected' => $sha256Expected !== null ? strtolower($sha256Expected) : null,
            'storage_temp_prefix' => "tenants/{$user->tenant_id}/uploads/{$sessionUuid}",
            'status' => UploadStatus::Initiated,
            'expires_at' => now()->addHours($ttlHours),
        ]);
    }

    public function storeChunk(
        UploadSession $session,
        int $partIndex,
        string $bytes,
        ?string $chunkSha256 = null,
    ): UploadSession {
        if (in_array($session->status, [UploadStatus::Completed, UploadStatus::Aborted, UploadStatus::Expired], true)) {
            throw new RuntimeException("Upload session is already {$session->status->value}.");
        }

        if ($partIndex < 0 || $partIndex >= $session->total_chunks) {
            throw new RuntimeException("Invalid chunk index {$partIndex}.");
        }

        $actualSha = hash('sha256', $bytes);
        if ($chunkSha256 !== null && ! hash_equals(strtolower($chunkSha256), $actualSha)) {
            throw new RuntimeException("Chunk {$partIndex} SHA-256 checksum mismatch.");
        }

        $partPath = "{$session->storage_temp_prefix}/part_{$partIndex}";
        Storage::disk('sentinel_quarantine')->put($partPath, $bytes);

        return DB::transaction(function () use ($session, $partIndex, $bytes, $actualSha): UploadSession {
            /** @var UploadSession $locked */
            $locked = UploadSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();
            $parts = $locked->uploaded_parts_json;
            $parts[(string) $partIndex] = [
                'size' => strlen($bytes),
                'sha256' => $actualSha,
            ];

            $locked->update([
                'uploaded_parts_json' => $parts,
                'status' => UploadStatus::Uploading,
            ]);

            return $locked->refresh();
        });
    }

    public function finalizeIntoDocumentVersion(
        UploadSession $session,
        Document $document,
        User $actor,
        bool $major = false,
        ?string $versionNote = null,
        ?Request $request = null,
    ): DocumentVersion {
        $missing = $session->missingParts();
        if ($missing !== []) {
            throw new RuntimeException('Cannot finalize upload; missing chunks: '.implode(', ', $missing));
        }

        $session->update(['status' => UploadStatus::Assembling]);

        $tempAssembled = tempnam(sys_get_temp_dir(), 'sent_asm_');
        if ($tempAssembled === false) {
            throw new RuntimeException('Unable to allocate temporary assembly file.');
        }

        $extraTempFiles = [$tempAssembled];

        try {
            $out = fopen($tempAssembled, 'wb');
            if ($out === false) {
                throw new RuntimeException('Failed opening assembly file for write.');
            }

            $hashCtx = hash_init('sha256');
            $totalBytes = 0;

            for ($i = 0; $i < $session->total_chunks; $i++) {
                $partStream = Storage::disk('sentinel_quarantine')->readStream("{$session->storage_temp_prefix}/part_{$i}");
                if ($partStream === null) {
                    fclose($out);
                    throw new RuntimeException("Missing chunk {$i} in quarantine staging.");
                }

                while (! feof($partStream)) {
                    $buf = fread($partStream, 65536);
                    if ($buf === false || $buf === '') {
                        break;
                    }
                    fwrite($out, $buf);
                    hash_update($hashCtx, $buf);
                    $totalBytes += strlen($buf);
                }
                fclose($partStream);
            }
            fclose($out);

            $assembledSha = hash_final($hashCtx);
            if ($session->sha256_expected !== null && ! hash_equals($session->sha256_expected, $assembledSha)) {
                throw new RuntimeException('Final assembled SHA-256 mismatch against expected digest.');
            }

            // 1. Mandatory Malware & Magic-Byte Scan in Quarantine
            try {
                $this->malwareScanner->scanOrFail($tempAssembled, $session->filename);
            } catch (MalwareDetectedException $e) {
                $session->update(['status' => UploadStatus::Aborted]);
                $this->audit->log(
                    tenantId: $actor->tenant_id,
                    eventType: 'security.malware_quarantined',
                    resourceType: 'document',
                    resourceId: $document->id,
                    actor: $actor,
                    classificationRank: $document->classification_rank,
                    after: ['filename' => $session->filename, 'threat' => $e->getMessage()],
                    request: $request,
                );
                throw $e;
            }

            $version = $this->ingestFileIntoDocumentVersion(
                sourceFilePath: $tempAssembled,
                filename: $session->filename,
                mimeType: $session->mime_type,
                document: $document,
                actor: $actor,
                major: $major,
                versionNote: $versionNote,
                request: $request,
                preverifiedClean: true,
                originalSizeBytes: $totalBytes,
                originalSha256: $assembledSha,
            );

            $session->update([
                'document_id' => $document->id,
                'status' => UploadStatus::Completed,
            ]);

            // Clean staging quarantine chunks
            for ($i = 0; $i < $session->total_chunks; $i++) {
                Storage::disk('sentinel_quarantine')->delete("{$session->storage_temp_prefix}/part_{$i}");
            }

            return $version;
        } finally {
            foreach ($extraTempFiles as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * Run Malware -> OCR/PDF-A -> CDR -> Envelope Encryption -> Version Persistence -> Search & AI Chunk Indexing
     * cleanly for both chunked browser uploads and LAN/PWA/Hot-Folder/Agent scan ingestion.
     */
    public function ingestFileIntoDocumentVersion(
        string $sourceFilePath,
        string $filename,
        string $mimeType,
        Document $document,
        User $actor,
        bool $major = false,
        ?string $versionNote = null,
        ?Request $request = null,
        bool $preverifiedClean = false,
        ?int $originalSizeBytes = null,
        ?string $originalSha256 = null,
    ): DocumentVersion {
        $extraTempFiles = [];

        try {
            if (! $preverifiedClean) {
                try {
                    $this->malwareScanner->scanOrFail($sourceFilePath, $filename);
                } catch (MalwareDetectedException $e) {
                    $this->audit->log(
                        tenantId: $actor->tenant_id,
                        eventType: 'security.malware_quarantined',
                        resourceType: 'document',
                        resourceId: $document->id,
                        actor: $actor,
                        classificationRank: $document->classification_rank,
                        after: ['filename' => $filename, 'threat' => $e->getMessage()],
                        request: $request,
                    );
                    throw $e;
                }
            }

            $cdrStatus = CdrStatus::Skipped;
            $cdrReport = ['stripped_vectors' => []];
            $workingPath = $sourceFilePath;

            $ocrResult = $this->ocr->processToSearchablePdf($workingPath, $mimeType, $filename);
            if ($ocrResult['pdf_path'] !== $workingPath) {
                $extraTempFiles[] = $ocrResult['pdf_path'];
                $workingPath = $ocrResult['pdf_path'];
            }

            if ($ocrResult['mime_type'] === 'application/pdf') {
                $cdr = $this->cdr->sanitizePdfInPlace($workingPath);
                $cdrStatus = $cdr['status'];
                $cdrReport = ['stripped_vectors' => $cdr['stripped_vectors']];
                if ($cdr['stripped_vectors'] !== []) {
                    $this->audit->log(
                        tenantId: $actor->tenant_id,
                        eventType: 'document.cdr_sanitized',
                        resourceType: 'document',
                        resourceId: $document->id,
                        actor: $actor,
                        classificationRank: $document->classification_rank,
                        after: ['stripped_vectors' => $cdr['stripped_vectors']],
                        request: $request,
                    );
                }
            }

            $versionUuid = (string) Str::uuid7();
            [$nextMajor, $nextMinor] = $this->nextVersionNumbers($document, $major);
            $storageKey = "tenants/{$document->tenant_id}/docs/{$document->id}/v{$nextMajor}_{$nextMinor}_{$versionUuid}.enc";

            $fallbackSha = $originalSha256 ?? (hash_file('sha256', $sourceFilePath) ?: hash('sha256', $filename));
            $fallbackSize = $originalSizeBytes ?? (filesize($sourceFilePath) ?: 0);
            $finalSha = hash_file('sha256', $workingPath) ?: $fallbackSha;
            $finalSize = filesize($workingPath) ?: $fallbackSize;

            $enc = $this->crypto->encryptFileToDisk($workingPath, 'sentinel_vault', $storageKey);

            $version = DB::transaction(function () use (
                $versionUuid,
                $document,
                $nextMajor,
                $nextMinor,
                $storageKey,
                $filename,
                $ocrResult,
                $finalSize,
                $finalSha,
                $enc,
                $cdrStatus,
                $cdrReport,
                $versionNote,
                $actor,
                $request,
            ): DocumentVersion {
                DocumentVersion::query()
                    ->where('document_id', $document->id)
                    ->where('is_current', true)
                    ->update(['is_current' => false]);

                /** @var DocumentVersion $created */
                $created = DocumentVersion::query()->create([
                    'id' => $versionUuid,
                    'tenant_id' => $document->tenant_id,
                    'document_id' => $document->id,
                    'version_major' => $nextMajor,
                    'version_minor' => $nextMinor,
                    'is_current' => true,
                    'storage_disk' => 'sentinel_vault',
                    'storage_key' => $storageKey,
                    'original_filename' => $filename,
                    'mime_type' => $ocrResult['mime_type'],
                    'file_size_bytes' => $finalSize,
                    'sha256_hash' => $finalSha,
                    'wrapped_dek' => $enc['wrapped_dek'],
                    'kek_key_id' => $enc['kek_key_id'],
                    'av_status' => AvStatus::Clean,
                    'cdr_status' => $cdrStatus,
                    'ocr_status' => $ocrResult['status'],
                    'pdfa_compliant' => $ocrResult['pdfa_compliant'],
                    'page_count' => $ocrResult['page_count'],
                    'extracted_text' => $ocrResult['extracted_text'],
                    'version_note' => $versionNote,
                    'cdr_report_json' => $cdrReport,
                    'created_by' => $actor->id,
                ]);

                $document->update([
                    'current_version_id' => $created->id,
                    'ocr_text' => $ocrResult['extracted_text'],
                ]);

                ProcessingJob::query()->create([
                    'tenant_id' => $document->tenant_id,
                    'document_version_id' => $created->id,
                    'idempotency_key' => hash('sha256', $created->id.'|ingest|'.$finalSha),
                    'stage' => 'ingest_complete',
                    'status' => 'completed',
                    'attempts' => 1,
                    'heartbeat_at' => now(),
                ]);

                $this->audit->log(
                    tenantId: $document->tenant_id,
                    eventType: 'document.version_uploaded',
                    resourceType: 'document_version',
                    resourceId: $created->id,
                    actor: $actor,
                    classificationRank: $document->classification_rank,
                    after: [
                        'document_id' => $document->id,
                        'version' => "v{$nextMajor}.{$nextMinor}",
                        'sha256' => $finalSha,
                        'size_bytes' => $finalSize,
                    ],
                    request: $request,
                );

                return $created;
            });

            $this->search->indexDocument($document->refresh());

            return $version;
        } finally {
            foreach ($extraTempFiles as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function nextVersionNumbers(Document $document, bool $major): array
    {
        /** @var DocumentVersion|null $latest */
        $latest = DocumentVersion::query()
            ->where('document_id', $document->id)
            ->orderByDesc('version_major')
            ->orderByDesc('version_minor')
            ->first();

        if ($latest === null) {
            return [1, 0];
        }

        return $major
            ? [$latest->version_major + 1, 0]
            : [$latest->version_major, $latest->version_minor + 1];
    }
}
