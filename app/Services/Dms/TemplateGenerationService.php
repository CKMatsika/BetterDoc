<?php

declare(strict_types=1);

namespace App\Services\Dms;

use App\Enums\AvStatus;
use App\Enums\CdrStatus;
use App\Enums\DocumentStatus;
use App\Enums\OcrStatus;
use App\Exceptions\InsufficientClearanceException;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Compartment;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\Folder;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Crypto\EnvelopeEncryptionService;
use App\Services\Pki\SignerBridgeService;
use App\Services\Search\DocumentSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TemplateGenerationService
{
    public function __construct(
        private readonly SignerBridgeService $signerBridge,
        private readonly EnvelopeEncryptionService $crypto,
        private readonly DocumentSearchService $search,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * Render a mail-merge template with escaped variables into a clean PDF/A Document + v1.0 version.
     *
     * @param  array<string, string>  $variables
     * @param  list<string>  $compartmentIds
     */
    public function generateDocument(
        DocumentTemplate $template,
        User $actor,
        Cabinet $cabinet,
        ClearanceLevel $level,
        string $title,
        array $variables,
        ?Folder $folder = null,
        array $compartmentIds = [],
        ?Request $request = null,
    ): Document {
        if ($template->tenant_id !== $actor->tenant_id || $level->tenant_id !== $actor->tenant_id) {
            throw new InsufficientClearanceException('Cross-tenant template generation is forbidden.');
        }

        if ($level->rank > $actor->clearance_rank) {
            throw new InsufficientClearanceException('Cannot generate a document above your own clearance rank.');
        }

        if ($compartmentIds !== [] && array_diff($compartmentIds, $actor->activeCompartmentIds()) !== []) {
            throw new InsufficientClearanceException('Cannot tag a generated document with compartments you do not hold.');
        }

        $mergedHtml = $this->renderTemplateHtml($template->body_html, $variables);

        $tmpOut = tempnam(sys_get_temp_dir(), 'sent_tpl_');
        if ($tmpOut === false) {
            throw new RuntimeException('Unable to allocate temporary PDF output path.');
        }

        try {
            $docNumber = $this->allocateDocNumber($template);

            $renderRes = $this->signerBridge->execute('render_html_pdf', [
                'output_pdf_path' => $tmpOut,
                'html_body' => $mergedHtml,
                'title' => $title,
                'classification_banner' => strtoupper($level->name),
                'doc_number' => $docNumber,
            ]);

            $fileSize = filesize($tmpOut);
            if ($fileSize === false || $fileSize === 0) {
                throw new RuntimeException('Generated template PDF was empty.');
            }

            $extractedText = is_string($renderRes['extracted_text'] ?? null) ? $renderRes['extracted_text'] : strip_tags($mergedHtml);
            $sha256 = is_string($renderRes['sha256_hash'] ?? null) ? $renderRes['sha256_hash'] : (hash_file('sha256', $tmpOut) ?: '');
            $pageCount = is_numeric($renderRes['page_count'] ?? null) ? (int) $renderRes['page_count'] : 1;

            $docId = (string) Str::uuid7();
            $verId = (string) Str::uuid7();
            $storageKey = "tenants/{$actor->tenant_id}/docs/{$docId}/v1_0_{$verId}.enc";
            $enc = $this->crypto->encryptFileToDisk($tmpOut, 'sentinel_vault', $storageKey);

            $document = DB::transaction(function () use (
                $docId,
                $verId,
                $template,
                $actor,
                $cabinet,
                $folder,
                $level,
                $docNumber,
                $title,
                $extractedText,
                $storageKey,
                $fileSize,
                $sha256,
                $enc,
                $pageCount,
                $compartmentIds,
                $variables,
                $request,
            ): Document {
                $doc = new Document([
                    'tenant_id' => $actor->tenant_id,
                    'cabinet_id' => $cabinet->id,
                    'folder_id' => $folder?->id,
                    'org_unit_id' => $actor->org_unit_id,
                    'owner_id' => $actor->id,
                    'classification_level_id' => $level->id,
                    'classification_rank' => $level->rank,
                    'doc_number' => $docNumber,
                    'title' => $title,
                    'summary' => "Generated from template {$template->code} ({$template->name}).",
                    'ocr_text' => $extractedText,
                    'status' => DocumentStatus::Active,
                    'unit_wide_readable' => true,
                    'metadata_json' => [
                        'template_id' => $template->id,
                        'template_code' => $template->code,
                        'merge_variables' => $variables,
                    ],
                ]);
                $doc->id = $docId;
                $doc->save();

                if ($compartmentIds !== []) {
                    $validIds = Compartment::query()
                        ->where('tenant_id', $actor->tenant_id)
                        ->whereIn('id', $compartmentIds)
                        ->pluck('id')
                        ->all();
                    $doc->compartments()->sync($validIds);
                }

                $version = new DocumentVersion([
                    'tenant_id' => $actor->tenant_id,
                    'document_id' => $doc->id,
                    'version_major' => 1,
                    'version_minor' => 0,
                    'is_current' => true,
                    'storage_disk' => 'sentinel_vault',
                    'storage_key' => $storageKey,
                    'original_filename' => str_replace('/', '_', $docNumber).'.pdf',
                    'mime_type' => 'application/pdf',
                    'file_size_bytes' => $fileSize,
                    'sha256_hash' => $sha256,
                    'wrapped_dek' => $enc['wrapped_dek'],
                    'kek_key_id' => $enc['kek_key_id'],
                    'av_status' => AvStatus::Clean,
                    'cdr_status' => CdrStatus::Sanitized,
                    'ocr_status' => OcrStatus::Completed,
                    'pdfa_compliant' => true,
                    'page_count' => $pageCount,
                    'extracted_text' => $extractedText,
                    'version_note' => "Generated from mail-merge template {$template->code}",
                    'created_by' => $actor->id,
                ]);
                $version->id = $verId;
                $version->save();

                $doc->update(['current_version_id' => $version->id]);

                $this->audit->log(
                    tenantId: $actor->tenant_id,
                    eventType: 'template.document_generated',
                    resourceType: 'document',
                    resourceId: $doc->id,
                    actor: $actor,
                    classificationRank: $doc->classification_rank,
                    after: [
                        'template_id' => $template->id,
                        'doc_number' => $docNumber,
                        'version_id' => $verId,
                    ],
                    request: $request,
                );

                return $doc;
            });

            $this->search->indexDocument($document->refresh());

            return $document;
        } finally {
            @unlink($tmpOut);
        }
    }

    /**
     * Perform safe variable interpolation on {{key}} placeholders, escaping values against XSS/HTML injection.
     *
     * @param  array<string, string>  $variables
     */
    public function renderTemplateHtml(string $bodyHtml, array $variables): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function (array $m) use ($variables): string {
            $key = $m[1];
            if ($key === 'date') {
                return htmlspecialchars($variables[$key] ?? now('UTC')->format('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            return htmlspecialchars((string) ($variables[$key] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $bodyHtml);
    }

    private function allocateDocNumber(DocumentTemplate $template): string
    {
        return DB::transaction(function () use ($template): string {
            /** @var DocumentTemplate $locked */
            $locked = DocumentTemplate::query()->where('id', $template->id)->lockForUpdate()->firstOrFail();
            $seq = $locked->next_sequence;
            $locked->update(['next_sequence' => $seq + 1]);

            $year = now('UTC')->format('Y');

            return sprintf('%s/%s/%04d', strtoupper(trim($locked->auto_number_prefix, '/')), $year, $seq);
        });
    }
}
