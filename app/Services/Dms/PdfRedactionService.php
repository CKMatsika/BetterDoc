<?php

declare(strict_types=1);

namespace App\Services\Dms;

use App\Enums\AnnotationKind;
use App\Enums\AvStatus;
use App\Enums\CdrStatus;
use App\Enums\OcrStatus;
use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Collaboration\RealtimeNotificationService;
use App\Services\Crypto\EnvelopeEncryptionService;
use App\Services\Pki\SignerBridgeService;
use App\Services\Search\DocumentSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PdfRedactionService
{
    public function __construct(
        private readonly EnvelopeEncryptionService $crypto,
        private readonly SignerBridgeService $signerBridge,
        private readonly DocumentSearchService $search,
        private readonly ImmutableAuditLogger $audit,
        private readonly RealtimeNotificationService $notifications,
    ) {}

    /**
     * @param  array{x: float, y: float, width: float, height: float}  $coords
     */
    public function createAnnotation(
        Document $document,
        DocumentVersion $version,
        User $actor,
        int $pageNumber,
        AnnotationKind $kind,
        array $coords,
        ?string $content = null,
        ?Request $request = null,
    ): DocumentAnnotation {
        if ($document->is_worm) {
            throw new RuntimeException('Cannot add annotations or redaction marks to a WORM-locked record.');
        }

        /** @var DocumentAnnotation $annotation */
        $annotation = DocumentAnnotation::query()->create([
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
            'version_id' => $version->id,
            'created_by' => $actor->id,
            'page_number' => max(1, $pageNumber),
            'kind' => $kind,
            'coords_json' => [
                'x' => max(0.0, min(1.0, (float) $coords['x'])),
                'y' => max(0.0, min(1.0, (float) $coords['y'])),
                'width' => max(0.005, min(1.0, (float) $coords['width'])),
                'height' => max(0.005, min(1.0, (float) $coords['height'])),
            ],
            'content' => $content !== null ? trim($content) : null,
        ]);

        $this->audit->log(
            tenantId: $document->tenant_id,
            eventType: 'document.annotation_created',
            resourceType: 'document',
            resourceId: $document->id,
            actor: $actor,
            classificationRank: $document->classification_rank,
            after: [
                'annotation_id' => $annotation->id,
                'kind' => $kind->value,
                'page_number' => $annotation->page_number,
            ],
            request: $request,
        );

        $this->notifications->broadcastDocumentActivity($document->id, 'annotation.created', [
            'kind' => $kind->value,
        ]);

        return $annotation;
    }

    /**
     * Execute destructive server-side PyMuPDF redaction over all pending redaction marks
     * (or explicit IDs), creating a new encrypted clean DocumentVersion with zero extractable redacted text.
     *
     * @param  list<string>|null  $annotationIds
     */
    public function applyRedactions(
        Document $document,
        DocumentVersion $sourceVersion,
        User $actor,
        ?array $annotationIds = null,
        ?Request $request = null,
    ): DocumentVersion {
        if ($document->is_worm) {
            throw new RuntimeException('Cannot modify or redact a WORM-locked record.');
        }

        if ($sourceVersion->mime_type !== 'application/pdf') {
            throw new RuntimeException('Destructive redaction is only supported on PDF versions.');
        }

        $query = DocumentAnnotation::query()
            ->where('document_id', $document->id)
            ->where('version_id', $sourceVersion->id)
            ->where('kind', AnnotationKind::RedactionMark->value)
            ->whereNull('redacted_in_version_id');

        if ($annotationIds !== null && $annotationIds !== []) {
            $query->whereIn('id', $annotationIds);
        }

        $marks = $query->orderBy('page_number')->get();
        if ($marks->isEmpty()) {
            throw new RuntimeException('No pending redaction marks found on the current PDF version.');
        }

        $boxes = $marks->map(static fn (DocumentAnnotation $a): array => [
            'page_number' => $a->page_number,
            'x' => (float) ($a->coords_json['x'] ?? 0.0),
            'y' => (float) ($a->coords_json['y'] ?? 0.0),
            'width' => (float) ($a->coords_json['width'] ?? 0.1),
            'height' => (float) ($a->coords_json['height'] ?? 0.05),
        ])->values()->all();

        $handle = $this->crypto->decryptVersionToTempHandle($sourceVersion);
        $inPath = tempnam(sys_get_temp_dir(), 'sent_red_in_');
        $outPath = tempnam(sys_get_temp_dir(), 'sent_red_out_');

        if ($inPath === false || $outPath === false) {
            fclose($handle);
            throw new RuntimeException('Unable to allocate temporary files for redaction.');
        }

        try {
            $dest = fopen($inPath, 'wb');
            if ($dest === false) {
                throw new RuntimeException('Unable to write decrypted source PDF.');
            }
            stream_copy_to_stream($handle, $dest);
            fclose($dest);
            fclose($handle);

            $result = $this->signerBridge->execute('redact_pdf', [
                'input_pdf_path' => $inPath,
                'output_pdf_path' => $outPath,
                'boxes' => $boxes,
                'scrub_metadata' => true,
            ]);

            $redactedSize = filesize($outPath);
            if ($redactedSize === false || $redactedSize === 0) {
                throw new RuntimeException('Redacted PDF output was empty.');
            }

            /** @var int $appliedCount */
            $appliedCount = is_numeric($result['applied_boxes'] ?? null) ? (int) $result['applied_boxes'] : count($boxes);
            $extractedText = is_string($result['extracted_text'] ?? null) ? $result['extracted_text'] : '';
            $sha256 = is_string($result['sha256_hash'] ?? null) ? $result['sha256_hash'] : (hash_file('sha256', $outPath) ?: '');
            $pageCount = is_numeric($result['page_count'] ?? null) ? (int) $result['page_count'] : $sourceVersion->page_count;

            $newId = (string) Str::uuid7();
            $maxMinor = DocumentVersion::query()
                ->where('document_id', $document->id)
                ->where('version_major', $sourceVersion->version_major)
                ->max('version_minor');
            $nextMinor = (is_numeric($maxMinor) ? (int) $maxMinor : $sourceVersion->version_minor) + 1;

            $storageKey = "tenants/{$document->tenant_id}/docs/{$document->id}/v{$sourceVersion->version_major}_{$nextMinor}_{$newId}.enc";
            $enc = $this->crypto->encryptFileToDisk($outPath, 'betterdoc_vault', $storageKey);

            $newVersion = DB::transaction(function () use (
                $newId,
                $document,
                $sourceVersion,
                $nextMinor,
                $storageKey,
                $redactedSize,
                $sha256,
                $enc,
                $pageCount,
                $extractedText,
                $actor,
                $marks,
                $appliedCount,
                $request,
            ): DocumentVersion {
                DocumentVersion::query()
                    ->where('document_id', $document->id)
                    ->where('is_current', true)
                    ->update(['is_current' => false]);

                $created = new DocumentVersion([
                    'tenant_id' => $document->tenant_id,
                    'document_id' => $document->id,
                    'version_major' => $sourceVersion->version_major,
                    'version_minor' => $nextMinor,
                    'is_current' => true,
                    'storage_disk' => 'betterdoc_vault',
                    'storage_key' => $storageKey,
                    'original_filename' => preg_replace('/\.pdf$/i', '_redacted.pdf', $sourceVersion->original_filename) ?? 'redacted.pdf',
                    'mime_type' => 'application/pdf',
                    'file_size_bytes' => $redactedSize,
                    'sha256_hash' => $sha256,
                    'wrapped_dek' => $enc['wrapped_dek'],
                    'kek_key_id' => $enc['kek_key_id'],
                    'av_status' => AvStatus::Clean,
                    'cdr_status' => CdrStatus::Sanitized,
                    'ocr_status' => OcrStatus::Completed,
                    'pdfa_compliant' => true,
                    'page_count' => $pageCount,
                    'extracted_text' => $extractedText,
                    'version_note' => "True destructive redaction applied ({$appliedCount} region(s) permanently purged)",
                    'created_by' => $actor->id,
                ]);
                $created->id = $newId;
                $created->save();

                DocumentAnnotation::query()
                    ->whereIn('id', $marks->pluck('id')->all())
                    ->update(['redacted_in_version_id' => $created->id]);

                $document->update([
                    'current_version_id' => $created->id,
                    'ocr_text' => $extractedText,
                ]);

                $this->audit->log(
                    tenantId: $document->tenant_id,
                    eventType: 'document.redacted',
                    resourceType: 'document',
                    resourceId: $document->id,
                    actor: $actor,
                    classificationRank: $document->classification_rank,
                    before: ['version_id' => $sourceVersion->id],
                    after: [
                        'new_version_id' => $created->id,
                        'applied_boxes' => $appliedCount,
                        'sha256_hash' => $sha256,
                    ],
                    request: $request,
                );

                return $created;
            });

            $this->search->indexDocument($document->refresh());
            $this->notifications->broadcastDocumentActivity($document->id, 'document.redacted', [
                'version_id' => $newVersion->id,
            ]);

            return $newVersion;
        } finally {
            @unlink($inPath);
            @unlink($outPath);
        }
    }
}
