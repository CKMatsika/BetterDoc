<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Enums\ApInvoiceStatus;
use App\Enums\BetterDocRole;
use App\Enums\DocumentStatus;
use App\Enums\ErpSystemType;
use App\Enums\IdpDocType;
use App\Enums\IdpReviewStatus;
use App\Models\ApInvoice;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\ErpBridgeAgent;
use App\Models\IdpExtraction;
use App\Models\IntegrationConnection;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Ai\IdpExtractionService;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Dms\GovernanceService;
use App\Services\Finance\ApAutomationService;
use App\Services\Integration\Connectors\ErpConnectorManager;
use App\Services\Integration\ErpBridgeService;
use App\Services\PrintScan\ScanIngestionService;
use App\Services\Search\DocumentSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

class MobileCaptureService
{
    public function __construct(
        private readonly ScanIngestionService $scanIngestion,
        private readonly IdpExtractionService $idp,
        private readonly ApAutomationService $apAutomation,
        private readonly ErpConnectorManager $erpConnectors,
        private readonly ErpBridgeService $erpBridge,
        private readonly GovernanceService $governance,
        private readonly DocumentSearchService $search,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * Ingest a photo or PDF from a mobile device (PWA or Flutter API), run deskew/OCR/IDP,
     * detect whether it is an accounting invoice, and return suggested AP & ERP routing metadata.
     *
     * @param  array{title?: string, auto_deskew?: bool, force_invoice?: bool, source_channel?: string}  $options
     * @return array{
     *     batch_id: string,
     *     document: array<string, mixed>,
     *     extraction: array<string, mixed>|null,
     *     is_invoice: bool,
     *     suggested_invoice: array<string, mixed>|null,
     *     erp_targets: list<array<string, mixed>>
     * }
     */
    public function captureDocument(
        User $actor,
        Tenant $tenant,
        Cabinet $cabinet,
        ClearanceLevel $level,
        string $filename,
        string $rawBytes,
        array $options = [],
        ?Request $request = null,
    ): array {
        $title = trim((string) ($options['title'] ?? ''));
        $autoDeskew = (bool) ($options['auto_deskew'] ?? true);
        $forceInvoice = (bool) ($options['force_invoice'] ?? false);
        $sourceChannel = trim((string) ($options['source_channel'] ?? 'mobile_pwa'));

        $batch = $this->scanIngestion->ingestBatch(
            tenant: $tenant,
            actor: $actor,
            cabinet: $cabinet,
            defaultLevel: $level,
            sourceChannel: $sourceChannel,
            filename: $filename,
            rawBytes: $rawBytes,
            options: [
                'auto_split_barcode' => false,
                'auto_deskew' => $autoDeskew,
                'auto_idp' => true,
                'title_prefix' => $title !== '' ? $title : null,
            ],
            request: $request,
        );

        /** @var list<string> $createdIds */
        $createdIds = is_array($batch->report_json['created_doc_ids'] ?? null)
            ? array_values(array_filter($batch->report_json['created_doc_ids'], 'is_string'))
            : [];

        if ($createdIds === []) {
            throw new RuntimeException('Mobile capture completed without creating a vault document.');
        }

        /** @var Document $document */
        $document = Document::query()
            ->with(['cabinet', 'classificationLevel', 'currentVersion'])
            ->where('tenant_id', $tenant->id)
            ->findOrFail($createdIds[0]);

        /** @var IdpExtraction|null $extraction */
        $extraction = IdpExtraction::query()
            ->where('tenant_id', $tenant->id)
            ->where('document_id', $document->id)
            ->latest()
            ->first();

        $isInvoice = $forceInvoice || ($extraction !== null && $extraction->detected_doc_type === IdpDocType::Invoice);

        $suggestedInvoice = $isInvoice
            ? $this->buildSuggestedInvoice($tenant->id, $document, $extraction)
            : null;

        return [
            'batch_id' => $batch->id,
            'document' => $this->serializeDocument($document),
            'extraction' => $extraction !== null ? [
                'id' => $extraction->id,
                'detected_doc_type' => $extraction->detected_doc_type->value,
                'overall_confidence' => $extraction->overall_confidence,
                'review_status' => $extraction->review_status->value,
                'fields' => $extraction->extracted_fields_json,
            ] : null,
            'is_invoice' => $isInvoice,
            'suggested_invoice' => $suggestedInvoice,
            'erp_targets' => $this->listAccountingErpTargets($tenant->id),
        ];
    }

    /**
     * Confirm an extracted mobile invoice, match in AP Automation, push to the chosen Accounting ERP
     * (Xero, NetSuite, or On-Prem Sage Pastel LAN Bridge), and optionally archive/WORM-lock the document.
     *
     * @param  array{
     *     vendor_id?: string|null,
     *     vendor_name?: string|null,
     *     purchase_order_id?: string|null,
     *     invoice_number: string,
     *     invoice_date: string,
     *     due_date?: string|null,
     *     currency?: string,
     *     subtotal_cents: int,
     *     vat_cents: int,
     *     total_cents: int,
     *     description?: string|null,
     *     match_mode?: string,
     *     auto_approve?: bool,
     *     override_reason?: string|null,
     *     erp_target_type?: string,
     *     erp_target_id?: string|null,
     *     archive_document?: bool,
     *     apply_worm_lock?: bool
     * }  $payload
     * @return array{
     *     document: array<string, mixed>,
     *     ap_invoice: array<string, mixed>,
     *     erp_result: array<string, mixed>
     * }
     */
    public function processInvoiceToErpAndArchive(
        Document $document,
        User $actor,
        array $payload,
        ?Request $request = null,
    ): array {
        if ($document->tenant_id !== $actor->tenant_id) {
            throw new RuntimeException('Cross-tenant mobile invoice processing is forbidden.');
        }

        // 1. Resolve or create Vendor
        $vendor = $this->resolveOrCreateVendor(
            tenantId: $actor->tenant_id,
            vendorId: $payload['vendor_id'] ?? null,
            vendorName: $payload['vendor_name'] ?? null,
            currency: $payload['currency'] ?? 'USD',
        );

        // 2. Resolve optional Purchase Order
        $po = null;
        if (! empty($payload['purchase_order_id'])) {
            $po = PurchaseOrder::query()
                ->where('tenant_id', $actor->tenant_id)
                ->find($payload['purchase_order_id']);
        }

        // 3. Approve/Correct latest IDP Extraction if present
        /** @var IdpExtraction|null $extraction */
        $extraction = IdpExtraction::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('document_id', $document->id)
            ->latest()
            ->first();

        if ($extraction !== null) {
            $this->idp->reviewExtraction(
                extraction: $extraction,
                reviewer: $actor,
                status: IdpReviewStatus::Corrected,
                correctedValues: [
                    'vendor_name' => $vendor->name,
                    'invoice_number' => trim($payload['invoice_number']),
                    'invoice_date' => $payload['invoice_date'],
                    'currency' => strtoupper($payload['currency'] ?? $vendor->currency),
                    'subtotal' => number_format($payload['subtotal_cents'] / 100, 2, '.', ''),
                    'vat_amount' => number_format($payload['vat_cents'] / 100, 2, '.', ''),
                    'total_amount' => number_format($payload['total_cents'] / 100, 2, '.', ''),
                ],
                notes: 'Verified via BetterDoc Mobile PWA Invoice-to-ERP flow.',
                request: $request,
            );
        }

        // 4. Capture AP Invoice & Run Duplicate + 2/3-Way Match
        $description = trim((string) ($payload['description'] ?? ''));
        if ($description === '') {
            $description = "Mobile Captured Invoice — {$document->title}";
        }

        $apInvoice = $this->apAutomation->captureAndMatch(
            actor: $actor,
            vendor: $vendor,
            document: $document,
            po: $po,
            header: [
                'invoice_number' => trim($payload['invoice_number']),
                'invoice_date' => $payload['invoice_date'],
                'due_date' => $payload['due_date'] ?? null,
                'currency' => strtoupper($payload['currency'] ?? $vendor->currency),
                'subtotal_cents' => max(0, (int) $payload['subtotal_cents']),
                'vat_cents' => max(0, (int) $payload['vat_cents']),
                'total_cents' => max(0, (int) $payload['total_cents']),
                'match_mode' => $payload['match_mode'] ?? '2_way',
                'idp_extraction_id' => $extraction?->id,
            ],
            lines: [
                [
                    'sku' => 'MOB-INV',
                    'description' => $description,
                    'qty_invoiced' => 1.0,
                    'unit_price_cents' => max(0, (int) $payload['subtotal_cents']),
                ],
            ],
            request: $request,
        );

        // 5. Auto-approve when requested and permitted (never approves DuplicateBlocked)
        $autoApprove = (bool) ($payload['auto_approve'] ?? true);
        if ($autoApprove && $apInvoice->status !== ApInvoiceStatus::DuplicateBlocked) {
            if ($apInvoice->status === ApInvoiceStatus::VarianceFlagged) {
                app(PermissionRegistrar::class)->setPermissionsTeamId($actor->tenant_id);
                $overrideReason = trim((string) ($payload['override_reason'] ?? ''));
                if ($overrideReason !== '' && $actor->hasAnyRole([BetterDocRole::Approver->value, BetterDocRole::SystemAdmin->value])) {
                    $apInvoice = $this->apAutomation->approveInvoice($apInvoice, $actor, $overrideReason, $request);
                }
            } else {
                $apInvoice = $this->apAutomation->approveInvoice($apInvoice, $actor, null, $request);
            }
        }

        // 6. Push to Accounting ERP (Cloud Connector or LAN Bridge)
        $erpTargetType = trim((string) ($payload['erp_target_type'] ?? 'none'));
        $erpTargetId = isset($payload['erp_target_id']) ? trim((string) $payload['erp_target_id']) : null;
        $erpResult = $this->dispatchInvoiceToErp($actor, $apInvoice, $erpTargetType, $erpTargetId, $request);

        // 7. Archive & optionally WORM-lock the source document
        $archiveDocument = (bool) ($payload['archive_document'] ?? true);
        $applyWormLock = (bool) ($payload['apply_worm_lock'] ?? false);

        $meta = is_array($document->metadata_json) ? $document->metadata_json : [];
        $meta['mobile_erp_processing'] = [
            'ap_invoice_id' => $apInvoice->id,
            'invoice_number' => $apInvoice->invoice_number,
            'vendor_name' => $vendor->name,
            'total_cents' => $apInvoice->total_cents,
            'currency' => $apInvoice->currency,
            'ap_status' => $apInvoice->status->value,
            'erp_target_type' => $erpResult['target_type'],
            'erp_target_name' => $erpResult['target_name'],
            'erp_sync_status' => $erpResult['status'],
            'processed_at' => now('UTC')->toIso8601String(),
        ];

        $updates = ['metadata_json' => $meta];
        if ($archiveDocument && $apInvoice->status !== ApInvoiceStatus::DuplicateBlocked) {
            $updates['status'] = DocumentStatus::Archived;
        }
        $document->update($updates);

        if ($applyWormLock && $archiveDocument && $apInvoice->status !== ApInvoiceStatus::DuplicateBlocked) {
            $document = $this->governance->applyWormLock($document->refresh(), $actor, null, $request);
        } else {
            $document = $document->refresh();
        }

        $this->search->indexDocument($document);

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'mobile.invoice_processed_to_erp',
            resourceType: 'document',
            resourceId: $document->id,
            actor: $actor,
            classificationRank: $document->classification_rank,
            after: [
                'ap_invoice_id' => $apInvoice->id,
                'invoice_number' => $apInvoice->invoice_number,
                'ap_status' => $apInvoice->status->value,
                'erp_result' => $erpResult,
                'archived' => $document->status === DocumentStatus::Archived,
                'is_worm' => $document->is_worm,
            ],
            request: $request,
        );

        return [
            'document' => $this->serializeDocument($document->load(['cabinet', 'classificationLevel', 'currentVersion'])),
            'ap_invoice' => [
                'id' => $apInvoice->id,
                'invoice_number' => $apInvoice->invoice_number,
                'vendor_name' => $vendor->name,
                'currency' => $apInvoice->currency,
                'subtotal_cents' => $apInvoice->subtotal_cents,
                'vat_cents' => $apInvoice->vat_cents,
                'total_cents' => $apInvoice->total_cents,
                'status' => $apInvoice->status->value,
                'match_report' => $apInvoice->match_report_json ?? [],
            ],
            'erp_result' => $erpResult,
        ];
    }

    /**
     * Quick mobile document management action (rename, move cabinet, archive/activate).
     *
     * @param  array{title?: string|null, cabinet_id?: string|null, status?: string|null}  $changes
     */
    public function manageDocument(
        Document $document,
        User $actor,
        array $changes,
        ?Request $request = null,
    ): Document {
        if ($document->tenant_id !== $actor->tenant_id) {
            throw new RuntimeException('Cross-tenant document update forbidden.');
        }

        $updates = [];
        if (isset($changes['title']) && trim($changes['title']) !== '') {
            $updates['title'] = mb_substr(trim($changes['title']), 0, 200);
        }

        if (isset($changes['cabinet_id']) && trim($changes['cabinet_id']) !== '') {
            $cabinet = Cabinet::query()
                ->where('tenant_id', $actor->tenant_id)
                ->findOrFail($changes['cabinet_id']);
            $updates['cabinet_id'] = $cabinet->id;
        }

        if (isset($changes['status']) && in_array($changes['status'], [DocumentStatus::Active->value, DocumentStatus::Archived->value], true)) {
            $updates['status'] = DocumentStatus::from($changes['status']);
        }

        if ($updates !== []) {
            $document->update($updates);
            $this->search->indexDocument($document->refresh());

            $this->audit->log(
                tenantId: $actor->tenant_id,
                eventType: 'mobile.document_managed',
                resourceType: 'document',
                resourceId: $document->id,
                actor: $actor,
                classificationRank: $document->classification_rank,
                after: [
                    'title' => $document->title,
                    'cabinet_id' => $document->cabinet_id,
                    'status' => $document->status->value,
                ],
                request: $request,
            );
        }

        return $document->refresh()->load(['cabinet', 'classificationLevel', 'currentVersion']);
    }

    /**
     * List all Accounting ERP targets available to push invoices from mobile capture.
     *
     * @return list<array{target_type: string, target_id: string, name: string, system_type: string, mode: string, status: string}>
     */
    public function listAccountingErpTargets(string $tenantId): array
    {
        $targets = [];

        $connections = IntegrationConnection::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('system_type', [ErpSystemType::Xero->value, ErpSystemType::Netsuite->value])
            ->orderBy('name')
            ->get();

        /** @var IntegrationConnection $conn */
        foreach ($connections as $conn) {
            $targets[] = [
                'target_type' => 'connection',
                'target_id' => $conn->id,
                'name' => $conn->name,
                'system_type' => $conn->system_type->value,
                'mode' => 'cloud_api',
                'status' => 'active',
            ];
        }

        $bridges = ErpBridgeAgent::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['online', 'pending_pairing'])
            ->orderBy('name')
            ->get();

        /** @var ErpBridgeAgent $agent */
        foreach ($bridges as $agent) {
            $caps = $agent->capabilities_json;
            if ($caps !== [] && ! in_array('push_invoices', $caps, true)) {
                continue;
            }
            $targets[] = [
                'target_type' => 'bridge',
                'target_id' => $agent->id,
                'name' => "{$agent->name} ({$agent->erp_target})",
                'system_type' => $agent->erp_target,
                'mode' => 'lan_bridge',
                'status' => $agent->status,
            ];
        }

        return $targets;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeDocument(Document $document): array
    {
        $meta = is_array($document->metadata_json) ? $document->metadata_json : [];

        return [
            'id' => $document->id,
            'doc_number' => $document->doc_number,
            'title' => $document->title,
            'status' => $document->status->value,
            'is_worm' => $document->is_worm,
            'cabinet_id' => $document->cabinet_id,
            'cabinet_name' => $document->cabinet->name ?? 'General',
            'classification_name' => $document->classificationLevel->name ?? 'Internal',
            'classification_rank' => $document->classification_rank,
            'idp_doc_type' => $meta['idp_doc_type'] ?? null,
            'mobile_erp_processing' => $meta['mobile_erp_processing'] ?? null,
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSuggestedInvoice(string $tenantId, Document $document, ?IdpExtraction $extraction): array
    {
        $fields = $extraction !== null
            ? $extraction->extracted_fields_json
            : [];

        $getVal = static fn (string $key, string $default = ''): string => isset($fields[$key])
            ? trim($fields[$key]['value'])
            : $default;

        $vendorName = $getVal('vendor_name');
        $matchedVendor = null;
        if ($vendorName !== '') {
            $matchedVendor = Vendor::query()
                ->where('tenant_id', $tenantId)
                ->where('name', 'ILIKE', '%'.$vendorName.'%')
                ->first();
        }

        $poNumber = $getVal('po_number');
        $matchedPo = null;
        if ($poNumber !== '') {
            $matchedPo = PurchaseOrder::query()
                ->where('tenant_id', $tenantId)
                ->where('po_number', 'ILIKE', $poNumber)
                ->first();
        }

        $subtotalCents = $this->parseMoneyToCents($getVal('subtotal'));
        $vatCents = $this->parseMoneyToCents($getVal('vat_amount'));
        $totalCents = $this->parseMoneyToCents($getVal('total_amount'));

        if ($totalCents === 0 && ($subtotalCents > 0 || $vatCents > 0)) {
            $totalCents = $subtotalCents + $vatCents;
        } elseif ($subtotalCents === 0 && $totalCents > 0) {
            $subtotalCents = max(0, $totalCents - $vatCents);
        }

        $invoiceNumber = $getVal('invoice_number');
        if ($invoiceNumber === '') {
            $invoiceNumber = 'INV-'.now('UTC')->format('Ymd').'-'.strtoupper(Str::random(4));
        }

        $rawDate = $getVal('invoice_date');
        $invoiceDate = now('UTC')->toDateString();
        if ($rawDate !== '') {
            $ts = strtotime($rawDate);
            if ($ts !== false) {
                $invoiceDate = date('Y-m-d', $ts);
            }
        }

        $currency = strtoupper($getVal('currency', $matchedVendor->currency ?? 'USD'));
        if (! in_array($currency, ['USD', 'ZWG', 'ZWL', 'ZAR', 'EUR', 'GBP'], true)) {
            $currency = 'USD';
        }

        return [
            'vendor_id' => $matchedVendor?->id,
            'vendor_name' => $matchedVendor->name ?? ($vendorName !== '' ? $vendorName : 'Unmapped Supplier'),
            'purchase_order_id' => $matchedPo?->id,
            'po_number' => $matchedPo->po_number ?? ($poNumber !== '' ? $poNumber : null),
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate,
            'currency' => $currency,
            'subtotal_cents' => $subtotalCents,
            'vat_cents' => $vatCents,
            'total_cents' => $totalCents,
            'zimra_tin' => $getVal('zimra_tin') ?: null,
            'description' => "Mobile Captured Invoice — {$document->title}",
        ];
    }

    private function parseMoneyToCents(string $raw): int
    {
        $clean = preg_replace('/[^0-9.]/', '', str_replace(',', '', $raw));
        if (! is_string($clean) || $clean === '' || ! is_numeric($clean)) {
            return 0;
        }

        return max(0, (int) round(((float) $clean) * 100));
    }

    private function resolveOrCreateVendor(string $tenantId, ?string $vendorId, ?string $vendorName, string $currency): Vendor
    {
        if ($vendorId !== null && trim($vendorId) !== '') {
            /** @var Vendor $found */
            $found = Vendor::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($vendorId);

            return $found;
        }

        $cleanName = trim((string) $vendorName);
        if ($cleanName === '') {
            $cleanName = 'Mobile Walk-In Vendor';
        }

        /** @var Vendor|null $existing */
        $existing = Vendor::query()
            ->where('tenant_id', $tenantId)
            ->where('name', 'ILIKE', $cleanName)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var Vendor $created */
        $created = Vendor::query()->create([
            'tenant_id' => $tenantId,
            'code' => 'MOB-'.strtoupper(Str::random(5)),
            'name' => mb_substr($cleanName, 0, 160),
            'currency' => strtoupper(substr($currency, 0, 8)),
            'is_active' => true,
        ]);

        return $created;
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchInvoiceToErp(
        User $actor,
        ApInvoice $apInvoice,
        string $erpTargetType,
        ?string $erpTargetId,
        ?Request $request,
    ): array {
        if ($apInvoice->status === ApInvoiceStatus::DuplicateBlocked) {
            return [
                'target_type' => 'blocked',
                'target_name' => 'Duplicate Protection',
                'status' => 'blocked_duplicate',
                'message' => 'Invoice blocked as a duplicate; ERP push skipped to prevent double payment.',
            ];
        }

        if ($erpTargetType === 'connection' && $erpTargetId !== null && $erpTargetId !== '') {
            /** @var IntegrationConnection $connection */
            $connection = IntegrationConnection::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('is_active', true)
                ->findOrFail($erpTargetId);

            $run = $this->erpConnectors->runSync($connection, $actor, 'push_invoices', $request);

            return [
                'target_type' => 'connection',
                'target_id' => $connection->id,
                'target_name' => $connection->name,
                'system_type' => $connection->system_type->value,
                'status' => $run->status,
                'sync_run_id' => $run->id,
                'records_processed' => $run->records_processed,
                'records_failed' => $run->records_failed,
                'error_message' => $run->error_message,
                'message' => match ($run->status) {
                    'completed' => "Pushed to {$connection->name} ({$run->records_processed} bill synced).",
                    'blocked_airgap' => "Air-gap policy blocked direct cloud push to {$connection->name}. Saved in AP Ledger.",
                    default => $run->error_message ?? "ERP sync to {$connection->name} ended with status {$run->status}.",
                },
            ];
        }

        if ($erpTargetType === 'bridge' && $erpTargetId !== null && $erpTargetId !== '') {
            /** @var ErpBridgeAgent $agent */
            $agent = ErpBridgeAgent::query()
                ->where('tenant_id', $actor->tenant_id)
                ->whereIn('status', ['online', 'pending_pairing'])
                ->findOrFail($erpTargetId);

            $job = $this->erpBridge->enqueueJob($actor, $agent, 'push_invoices', $request);

            return [
                'target_type' => 'bridge',
                'target_id' => $agent->id,
                'target_name' => $agent->name,
                'system_type' => $agent->erp_target,
                'status' => 'queued_for_lan_bridge',
                'bridge_job_id' => $job->id,
                'message' => "Queued for Windows LAN Bridge '{$agent->name}' ({$agent->erp_target}).",
            ];
        }

        return [
            'target_type' => 'none',
            'target_name' => 'BetterDoc AP Ledger',
            'status' => 'ap_ledger_only',
            'message' => 'Recorded in BetterDoc AP Finance Ledger (no external Accounting ERP selected).',
        ];
    }
}
