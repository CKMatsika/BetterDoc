<?php

declare(strict_types=1);

namespace App\Services\PrintScan;

use App\Enums\DocumentStatus;
use App\Enums\ScanBatchStatus;
use App\Enums\ScanSourceType;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\ScanBatch;
use App\Models\ScanSource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\IdpExtractionService;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Ingestion\MalwareScannerService;
use App\Services\Security\DbSecurityContext;
use App\Services\Security\SentinelHttpClientGuard;
use App\Services\Upload\ResumableUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

class ScanIngestionService
{
    public function __construct(
        private readonly PrintScanBridgeService $bridge,
        private readonly ResumableUploadService $uploadService,
        private readonly MalwareScannerService $malwareScanner,
        private readonly IdpExtractionService $idp,
        private readonly ImmutableAuditLogger $audit,
        private readonly DbSecurityContext $dbContext,
        private readonly SentinelHttpClientGuard $guard,
    ) {}

    /**
     * Process an uploaded PDF or scanned image(s) through Malware -> Deskew/Crop -> Barcode Separator Split ->
     * CDR/OCR -> Envelope Encryption -> Auto-IDP into distinct catalogued Documents.
     *
     * @param  array{auto_split_barcode?: bool, auto_deskew?: bool, auto_idp?: bool, title_prefix?: string|null}  $options
     */
    public function ingestBatch(
        Tenant $tenant,
        User $actor,
        Cabinet $cabinet,
        ClearanceLevel $defaultLevel,
        string $sourceChannel,
        string $filename,
        string $rawBytes,
        array $options = [],
        ?ScanSource $source = null,
        ?Request $request = null,
    ): ScanBatch {
        if ($defaultLevel->rank > $actor->clearance_rank) {
            throw new RuntimeException('Cannot ingest scan batch above your own clearance rank.');
        }

        $autoSplit = (bool) ($options['auto_split_barcode'] ?? true);
        $autoDeskew = (bool) ($options['auto_deskew'] ?? true);
        $autoIdp = (bool) ($options['auto_idp'] ?? false);
        $titlePrefix = trim((string) ($options['title_prefix'] ?? ''));

        $batch = ScanBatch::query()->create([
            'tenant_id' => $tenant->id,
            'scan_source_id' => $source?->id,
            'submitted_by' => $actor->id,
            'cabinet_id' => $cabinet->id,
            'source_channel' => $sourceChannel,
            'original_filename' => $filename,
            'status' => ScanBatchStatus::Processing,
        ]);

        $tempRaw = tempnam(sys_get_temp_dir(), 'sent_scan_');
        if ($tempRaw === false) {
            throw new RuntimeException('Unable to allocate temporary scan ingestion file.');
        }
        $extraTemp = [$tempRaw];

        try {
            file_put_contents($tempRaw, $rawBytes);
            $this->malwareScanner->scanOrFail($tempRaw, $filename);

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'tif', 'tiff', 'bmp'], true);

            $pdfBytes = $rawBytes;
            $deskewApplied = false;

            if ($isImage) {
                $imgTemp = $tempRaw.'.'.$ext;
                file_put_contents($imgTemp, $rawBytes);
                $extraTemp[] = $imgTemp;

                if ($autoDeskew) {
                    $converted = $this->bridge->deskewImagesToPdf([$imgTemp]);
                    $pdfBytes = $converted['pdf_bytes'];
                    $deskewApplied = $converted['deskew_applied'];
                } else {
                    $converted = $this->bridge->deskewImagesToPdf([$imgTemp]);
                    $pdfBytes = $converted['pdf_bytes'];
                }
            } elseif (! str_starts_with($rawBytes, '%PDF-')) {
                // Allow plain-text / EML text body normalization cleanly
                $pdfBytes = $this->convertPlainTextPdfViaTemp($rawBytes, $filename);
            }

            $segments = [];
            $separatorsFound = 0;
            $inputPages = 1;

            if ($autoSplit && str_starts_with($pdfBytes, '%PDF-')) {
                $splitRes = $this->bridge->splitPdfBySeparators($pdfBytes);
                $segments = $splitRes['segments'];
                $separatorsFound = $splitRes['separators_found'];
                $inputPages = $splitRes['total_input_pages'];
            }

            if ($segments === [] && str_starts_with($pdfBytes, '%PDF-')) {
                $segments = [[
                    'pdf_bytes' => $pdfBytes,
                    'page_count' => $inputPages,
                    'meta' => [],
                ]];
            }

            /** @var list<string> $createdDocIds */
            $createdDocIds = [];

            foreach ($segments as $idx => $segment) {
                $meta = $segment['meta'];
                $segTitle = $meta['title'] ?? ($titlePrefix !== '' ? $titlePrefix : pathinfo($filename, PATHINFO_FILENAME));
                if (count($segments) > 1 && ! isset($meta['title'])) {
                    $segTitle .= ' — Part '.($idx + 1);
                }

                $targetCabinet = $this->resolveCabinet($tenant->id, $meta['cabinet'] ?? '', $cabinet);
                $targetLevel = $this->resolveClassificationLevel($tenant->id, $meta['classification'] ?? '', $actor, $defaultLevel);

                $segPath = tempnam(sys_get_temp_dir(), 'sent_seg_');
                if ($segPath === false) {
                    continue;
                }
                $extraTemp[] = $segPath;
                file_put_contents($segPath, $segment['pdf_bytes']);

                $docNo = sprintf('SCN/%s/%03d-%s', now('UTC')->format('Ymd'), $idx + 1, strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)));

                $doc = $this->dbContext->runAs($actor, function () use (
                    $tenant,
                    $targetCabinet,
                    $targetLevel,
                    $actor,
                    $docNo,
                    $segTitle,
                    $sourceChannel,
                    $meta,
                    $segPath,
                    $idx,
                    $filename,
                    $request,
                    $autoIdp,
                ): Document {
                    /** @var Document $created */
                    $created = Document::query()->create([
                        'tenant_id' => $tenant->id,
                        'cabinet_id' => $targetCabinet->id,
                        'org_unit_id' => $actor->org_unit_id,
                        'owner_id' => $actor->id,
                        'classification_level_id' => $targetLevel->id,
                        'classification_rank' => $targetLevel->rank,
                        'doc_number' => $docNo,
                        'title' => mb_substr($segTitle, 0, 200),
                        'summary' => "Ingested via {$sourceChannel}".(isset($meta['doc_type']) ? " (Separator: {$meta['doc_type']})" : ''),
                        'status' => DocumentStatus::Active,
                        'unit_wide_readable' => true,
                        'metadata_json' => [
                            'scan_channel' => $sourceChannel,
                            'separator_meta' => $meta,
                        ],
                    ]);

                    $segFilename = pathinfo($filename, PATHINFO_FILENAME).'-part'.($idx + 1).'.pdf';

                    $this->uploadService->ingestFileIntoDocumentVersion(
                        sourceFilePath: $segPath,
                        filename: $segFilename,
                        mimeType: 'application/pdf',
                        document: $created,
                        actor: $actor,
                        major: true,
                        versionNote: "Scanned via {$sourceChannel}",
                        request: $request,
                        preverifiedClean: true,
                    );

                    if ($autoIdp || (($meta['doc_type'] ?? '') === 'invoice')) {
                        $this->idp->extractFromDocument($created->refresh(), $actor, null, $request);
                    }

                    $this->audit->log(
                        tenantId: $tenant->id,
                        eventType: 'scan.document_ingested',
                        resourceType: 'document',
                        resourceId: $created->id,
                        actor: $actor,
                        classificationRank: $created->classification_rank,
                        after: [
                            'channel' => $sourceChannel,
                            'doc_number' => $created->doc_number,
                            'separator_meta' => $meta,
                        ],
                        request: $request,
                    );

                    return $created->refresh();
                });

                $createdDocIds[] = $doc->id;
            }

            $batch->update([
                'page_count' => $inputPages,
                'split_documents_count' => count($createdDocIds),
                'status' => ScanBatchStatus::Completed,
                'completed_at' => now(),
                'report_json' => [
                    'created_doc_ids' => $createdDocIds,
                    'separators_found' => $separatorsFound,
                    'deskew_applied' => $deskewApplied,
                ],
            ]);

            return $batch->refresh();
        } catch (Throwable $e) {
            $batch->update([
                'status' => ScanBatchStatus::Failed,
                'report_json' => ['error' => $e->getMessage()],
            ]);
            throw $e;
        } finally {
            foreach ($extraTemp as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * Poll a configured hot folder, local mail-in dropbox, or LAN eSCL scanner and ingest pending batches.
     *
     * @return list<ScanBatch>
     */
    public function pollSource(ScanSource $source, User $actor, ?Request $request = null): array
    {
        if (! $source->is_active) {
            return [];
        }

        $tenant = Tenant::query()->findOrFail($source->tenant_id);
        $cabinet = $source->targetCabinet ?? Cabinet::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $level = $source->defaultClassificationLevel
            ?? ClearanceLevel::query()->where('tenant_id', $tenant->id)->where('rank', '<=', $actor->clearance_rank)->orderBy('rank')->firstOrFail();

        $batches = [];

        if ($source->source_type === ScanSourceType::HotFolder) {
            $dir = $this->resolveSafeDropPath($source->endpoint_or_path, config()->string('sentinel.print_scan.hot_folder_root'));
            if (is_dir($dir)) {
                $processedDir = $dir.DIRECTORY_SEPARATOR.'processed';
                if (! is_dir($processedDir)) {
                    mkdir($processedDir, 0750, true);
                }

                $files = scandir($dir) ?: [];
                foreach ($files as $entry) {
                    if ($entry === '.' || $entry === '..' || $entry === 'processed') {
                        continue;
                    }
                    $full = $dir.DIRECTORY_SEPARATOR.$entry;
                    if (! is_file($full)) {
                        continue;
                    }
                    $raw = file_get_contents($full);
                    if (! is_string($raw) || $raw === '') {
                        continue;
                    }

                    $batches[] = $this->ingestBatch(
                        tenant: $tenant,
                        actor: $actor,
                        cabinet: $cabinet,
                        defaultLevel: $level,
                        sourceChannel: ScanSourceType::HotFolder->value,
                        filename: $entry,
                        rawBytes: $raw,
                        options: [
                            'auto_split_barcode' => $source->auto_split_barcode,
                            'auto_deskew' => $source->auto_deskew,
                            'auto_idp' => true,
                        ],
                        source: $source,
                        request: $request,
                    );

                    @rename($full, $processedDir.DIRECTORY_SEPARATOR.$entry);
                }
            }
        } elseif ($source->source_type === ScanSourceType::MailIn) {
            $dir = $this->resolveSafeDropPath($source->endpoint_or_path, config()->string('sentinel.print_scan.mail_in_root'));
            if (is_dir($dir)) {
                $processedDir = $dir.DIRECTORY_SEPARATOR.'processed';
                if (! is_dir($processedDir)) {
                    mkdir($processedDir, 0750, true);
                }

                $files = scandir($dir) ?: [];
                foreach ($files as $entry) {
                    if (! str_ends_with(strtolower($entry), '.eml')) {
                        continue;
                    }
                    $full = $dir.DIRECTORY_SEPARATOR.$entry;
                    if (! is_file($full)) {
                        continue;
                    }
                    $emlRaw = file_get_contents($full);
                    if (! is_string($emlRaw) || $emlRaw === '') {
                        continue;
                    }

                    $parsed = $this->parseEmlAttachments($emlRaw);
                    foreach ($parsed['attachments'] as $att) {
                        $batches[] = $this->ingestBatch(
                            tenant: $tenant,
                            actor: $actor,
                            cabinet: $cabinet,
                            defaultLevel: $level,
                            sourceChannel: ScanSourceType::MailIn->value,
                            filename: $att['filename'],
                            rawBytes: $att['bytes'],
                            options: [
                                'auto_split_barcode' => $source->auto_split_barcode,
                                'auto_deskew' => $source->auto_deskew,
                                'auto_idp' => true,
                                'title_prefix' => $parsed['subject'],
                            ],
                            source: $source,
                            request: $request,
                        );
                    }

                    @rename($full, $processedDir.DIRECTORY_SEPARATOR.$entry);
                }
            }
        } elseif (in_array($source->source_type, [ScanSourceType::EsclAirscan, ScanSourceType::Sane], true)) {
            $baseUrl = rtrim($source->endpoint_or_path, '/');
            try {
                $resp = Http::withRequestMiddleware(
                    fn (RequestInterface $req): RequestInterface => $this->guard->assertOutboundAllowed($req)
                )->timeout(10)->get("{$baseUrl}/eSCL/ScanJobs/NextDocument");

                if ($resp->successful() && $resp->body() !== '') {
                    $ctype = (string) $resp->header('Content-Type');
                    $ext = str_contains($ctype, 'png') ? 'png' : (str_contains($ctype, 'jpeg') ? 'jpg' : 'pdf');
                    $batches[] = $this->ingestBatch(
                        tenant: $tenant,
                        actor: $actor,
                        cabinet: $cabinet,
                        defaultLevel: $level,
                        sourceChannel: $source->source_type->value,
                        filename: 'escl-scan-'.now('UTC')->format('Ymd-His').'.'.$ext,
                        rawBytes: $resp->body(),
                        options: [
                            'auto_split_barcode' => $source->auto_split_barcode,
                            'auto_deskew' => $source->auto_deskew,
                            'auto_idp' => true,
                        ],
                        source: $source,
                        request: $request,
                    );
                }
            } catch (Throwable $e) {
                Log::info('eSCL LAN scanner poll returned idle/offline.', ['url' => $baseUrl, 'reason' => $e->getMessage()]);
            }
        }

        $source->update(['last_polled_at' => now()]);

        return $batches;
    }

    private function resolveSafeDropPath(string $configured, string $root): string
    {
        if (! is_dir($root)) {
            mkdir($root, 0750, true);
        }

        $cleanRel = trim(str_replace(['..', "\0"], '', $configured), '/\\');
        if (str_contains($configured, ':') || str_starts_with($configured, DIRECTORY_SEPARATOR)) {
            $realRoot = realpath($root) ?: $root;
            $realCfg = realpath($configured);
            if ($realCfg !== false && str_starts_with(strtolower($realCfg), strtolower($realRoot))) {
                return $realCfg;
            }
        }

        return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($cleanRel);
    }

    private function resolveCabinet(string $tenantId, string $hint, Cabinet $fallback): Cabinet
    {
        $clean = trim($hint);
        if ($clean === '') {
            return $fallback;
        }

        $match = Cabinet::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('lower(name) LIKE ?', ['%'.mb_strtolower($clean).'%'])
            ->first();

        return $match ?? $fallback;
    }

    private function resolveClassificationLevel(string $tenantId, string $hint, User $actor, ClearanceLevel $fallback): ClearanceLevel
    {
        $clean = trim($hint);
        if ($clean === '') {
            return $fallback;
        }

        /** @var ClearanceLevel|null $match */
        $match = ClearanceLevel::query()
            ->where('tenant_id', $tenantId)
            ->where('rank', '<=', $actor->clearance_rank)
            ->where(function ($q) use ($clean): void {
                $q->whereRaw('lower(name) = ?', [mb_strtolower($clean)])
                    ->orWhereRaw('lower(code) = ?', [mb_strtolower($clean)]);
            })
            ->first();

        return $match ?? $fallback;
    }

    private function convertPlainTextPdfViaTemp(string $rawBytes, string $filename): string
    {
        $tmpTxt = tempnam(sys_get_temp_dir(), 'sent_eml_').'.txt';
        file_put_contents($tmpTxt, "Source File: {$filename}\n\n".mb_substr($rawBytes, 0, 20000));
        try {
            $tmpDoc = new Document(['id' => '00000000-0000-0000-0000-000000000000']);
            unset($tmpDoc);
            $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], mb_substr($rawBytes, 0, 500));
            $stream = "BT /F1 11 Tf 42 760 Td ({$safe}) Tj ET";
            $len = strlen($stream);

            return "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n4 0 obj << /Length {$len} >> stream\n{$stream}\nendstream endobj\n5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\nxref\n0 6\n0000000000 65535 f \ntrailer << /Size 6 /Root 1 0 R >>\nstartxref\n0\n%%EOF";
        } finally {
            @unlink($tmpTxt);
        }
    }

    /**
     * Extract PDF/image attachments cleanly from an RFC-2822 local .eml message.
     *
     * @return array{subject: string, attachments: list<array{filename: string, bytes: string}>}
     */
    private function parseEmlAttachments(string $rawEml): array
    {
        $subject = 'Mail-In Capture';
        if (preg_match('/^Subject:\s*(.+)$/im', $rawEml, $sm) === 1) {
            $subject = trim($sm[1]);
        }

        $attachments = [];
        if (preg_match('/boundary=["\']?([^"\'\r\n;]+)["\']?/i', $rawEml, $bm) === 1) {
            $boundary = '--'.$bm[1];
            $parts = explode($boundary, $rawEml);
            foreach ($parts as $part) {
                if (! str_contains(strtolower($part), 'filename=')) {
                    continue;
                }
                if (preg_match('/filename=["\']?([^"\'\r\n;]+)["\']?/i', $part, $fm) !== 1) {
                    continue;
                }
                $fname = basename(trim($fm[1]));
                $chunks = preg_split('/\r?\n\r?\n/', $part, 2);
                if (! is_array($chunks) || count($chunks) < 2) {
                    continue;
                }
                $headers = strtolower($chunks[0]);
                $body = trim($chunks[1]);
                $decoded = str_contains($headers, 'base64')
                    ? base64_decode(str_replace(["\r", "\n", ' '], '', $body), true)
                    : $body;

                if (is_string($decoded) && $decoded !== '') {
                    $attachments[] = ['filename' => $fname, 'bytes' => $decoded];
                }
            }
        }

        if ($attachments === []) {
            $attachments[] = ['filename' => 'mail-body.txt', 'bytes' => $rawEml];
        }

        return ['subject' => $subject, 'attachments' => $attachments];
    }
}
