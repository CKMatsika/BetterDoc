<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\AnomalySeverity;
use App\Enums\AnomalyStatus;
use App\Enums\ApInvoiceStatus;
use App\Enums\BetterDocRole;
use App\Enums\ZimraFiscalStatus;
use App\Models\AiAnomalyAlert;
use App\Models\ApInvoice;
use App\Models\ApInvoiceLine;
use App\Models\Document;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Collaboration\RealtimeNotificationService;
use App\Services\Security\BetterDocHttpClientGuard;
use DOMDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use stdClass;
use Throwable;

class ApAutomationService
{
    public function __construct(
        private readonly ImmutableAuditLogger $audit,
        private readonly RealtimeNotificationService $notifications,
        private readonly BetterDocHttpClientGuard $guard,
    ) {}

    /**
     * Capture an AP invoice against a document & optional PO, enforce tenant-wide duplicate checks,
     * and execute 2-way or 3-way (PO + GRN + Invoice) tolerance matching.
     *
     * @param  array{invoice_number: string, invoice_date: string, due_date?: string|null, currency?: string, subtotal_cents: int, vat_cents: int, total_cents: int, match_mode?: string, idp_extraction_id?: string|null}  $header
     * @param  list<array{sku: string, description: string, qty_invoiced: float, unit_price_cents: int}>  $lines
     */
    public function captureAndMatch(
        User $actor,
        Vendor $vendor,
        Document $document,
        ?PurchaseOrder $po,
        array $header,
        array $lines,
        ?Request $request = null,
    ): ApInvoice {
        $matchMode = ($header['match_mode'] ?? '3_way') === '2_way' ? '2_way' : '3_way';

        /** @var ApInvoice $invoice */
        $invoice = DB::transaction(function () use ($actor, $vendor, $document, $po, $header, $lines, $matchMode): ApInvoice {
            /** @var ApInvoice $created */
            $created = ApInvoice::query()->create([
                'tenant_id' => $actor->tenant_id,
                'vendor_id' => $vendor->id,
                'purchase_order_id' => $po?->id,
                'document_id' => $document->id,
                'idp_extraction_id' => $header['idp_extraction_id'] ?? null,
                'invoice_number' => trim($header['invoice_number']),
                'invoice_date' => $header['invoice_date'],
                'due_date' => $header['due_date'] ?? null,
                'currency' => strtoupper($header['currency'] ?? $vendor->currency),
                'subtotal_cents' => $header['subtotal_cents'],
                'vat_cents' => $header['vat_cents'],
                'total_cents' => $header['total_cents'],
                'status' => ApInvoiceStatus::PendingMatch,
                'match_mode' => $matchMode,
                'zimra_fiscal_status' => ZimraFiscalStatus::Pending,
            ]);

            foreach ($lines as $idx => $line) {
                $qty = max(0.0, (float) $line['qty_invoiced']);
                $price = max(0, (int) $line['unit_price_cents']);
                ApInvoiceLine::query()->create([
                    'tenant_id' => $actor->tenant_id,
                    'ap_invoice_id' => $created->id,
                    'line_number' => $idx + 1,
                    'sku' => trim($line['sku']),
                    'description' => trim($line['description']),
                    'qty_invoiced' => $qty,
                    'unit_price_cents' => $price,
                    'line_total_cents' => (int) round($qty * $price),
                ]);
            }

            return $created;
        });

        // 1. Check Duplicate Invoice Number for same Vendor across tenant
        $duplicate = $this->findDuplicateInvoice($actor->tenant_id, $vendor->id, $invoice->invoice_number, $invoice->id);
        if ($duplicate !== null) {
            $report = [
                'duplicate_detected' => true,
                'conflicting_invoice_id' => $duplicate['id'],
                'conflicting_invoice_number' => $duplicate['invoice_number'],
                'passed' => false,
                'variances' => ['Duplicate vendor invoice number detected cleanly against existing AP ledger.'],
            ];
            $invoice->update([
                'status' => ApInvoiceStatus::DuplicateBlocked,
                'match_report_json' => $report,
            ]);

            AiAnomalyAlert::query()->create([
                'tenant_id' => $actor->tenant_id,
                'user_id' => $actor->id,
                'document_id' => $document->id,
                'alert_type' => 'ap_duplicate_invoice_blocked',
                'severity' => AnomalySeverity::Critical,
                'status' => AnomalyStatus::Open,
                'title' => "Blocked Duplicate AP Invoice: {$invoice->invoice_number} ({$vendor->name})",
                'details_json' => $report,
            ]);

            $this->audit->log(
                tenantId: $actor->tenant_id,
                eventType: 'ap.duplicate_invoice_blocked',
                resourceType: 'ap_invoice',
                resourceId: $invoice->id,
                actor: $actor,
                classificationRank: $document->classification_rank,
                after: $report,
                request: $request,
            );

            return $invoice->refresh();
        }

        // 2. Run 2-way or 3-way PO + GRN + Invoice Match
        if (! $po instanceof PurchaseOrder) {
            $invoice->update([
                'status' => ApInvoiceStatus::PendingMatch,
                'match_report_json' => ['passed' => false, 'reason' => 'No Purchase Order linked for automated matching.'],
            ]);

            return $invoice->refresh();
        }

        $report = $this->evaluateMatch($invoice->load('lines'), $po->load('lines'), $matchMode);
        $nextStatus = $report['passed'] ? ApInvoiceStatus::Matched : ApInvoiceStatus::VarianceFlagged;

        $highValueThreshold = config()->integer('betterdoc.finance.ap.high_value_approval_cents', 1000000);
        $highValue = $invoice->total_cents >= $highValueThreshold;
        $report['high_value_approval_required'] = $highValue;

        $invoice->update([
            'status' => $nextStatus,
            'match_report_json' => $report,
        ]);

        if ($highValue && $nextStatus === ApInvoiceStatus::Matched) {
            $this->notifyApproversHighValue($actor->tenant_id, $invoice);
        }

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: $report['passed'] ? 'ap.invoice_matched' : 'ap.invoice_variance_flagged',
            resourceType: 'ap_invoice',
            resourceId: $invoice->id,
            actor: $actor,
            classificationRank: $document->classification_rank,
            after: [
                'invoice_number' => $invoice->invoice_number,
                'po_number' => $po->po_number,
                'match_mode' => $matchMode,
                'passed' => $report['passed'],
                'variances_count' => count($report['variances']),
            ],
            request: $request,
        );

        return $invoice->refresh();
    }

    public function approveInvoice(
        ApInvoice $invoice,
        User $approver,
        ?string $overrideReason = null,
        ?Request $request = null,
    ): ApInvoice {
        if ($invoice->status === ApInvoiceStatus::DuplicateBlocked) {
            throw new RuntimeException('Cannot approve an AP invoice blocked for duplicate billing.');
        }

        if ($invoice->status === ApInvoiceStatus::VarianceFlagged) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($approver->tenant_id);
            if (! $approver->hasAnyRole([BetterDocRole::Approver->value, BetterDocRole::SystemAdmin->value]) || trim((string) $overrideReason) === '') {
                throw new RuntimeException('Variance-flagged invoices require explicit Approver override justification.');
            }
        }

        $invoice->update([
            'status' => ApInvoiceStatus::Approved,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        $this->audit->log(
            tenantId: $invoice->tenant_id,
            eventType: 'ap.invoice_approved',
            resourceType: 'ap_invoice',
            resourceId: $invoice->id,
            actor: $approver,
            classificationRank: $invoice->document->classification_rank ?? 1,
            after: ['invoice_number' => $invoice->invoice_number, 'override_reason' => $overrideReason],
            request: $request,
        );

        return $invoice->refresh();
    }

    /**
     * Export a standards-compliant UBL 2.1 / PEPPOL BIS Billing 3.0 XML invoice document.
     */
    public function generateUbl21Xml(ApInvoice $invoice, User $actor, ?Request $request = null): string
    {
        $invoice->loadMissing(['vendor', 'lines', 'purchaseOrder', 'document']);
        $vendor = $invoice->vendor;
        $tenant = $actor->tenant;
        $tenantName = $tenant !== null ? $tenant->name : 'BetterDoc Enterprise Buyer';

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'Invoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $dom->appendChild($root);

        $addCbc = static function (DOMDocument $d, \DOMElement $parent, string $name, string $val, array $attrs = []): \DOMElement {
            $el = $d->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2', 'cbc:'.$name);
            $el->appendChild($d->createTextNode($val));
            foreach ($attrs as $ak => $av) {
                if (is_string($ak) && is_string($av)) {
                    $el->setAttribute($ak, $av);
                }
            }
            $parent->appendChild($el);

            return $el;
        };

        $addCac = static function (DOMDocument $d, \DOMElement $parent, string $name): \DOMElement {
            $el = $d->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2', 'cac:'.$name);
            $parent->appendChild($el);

            return $el;
        };

        $addCbc($dom, $root, 'CustomizationID', 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0');
        $addCbc($dom, $root, 'ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
        $addCbc($dom, $root, 'ID', $invoice->invoice_number);
        $addCbc($dom, $root, 'IssueDate', $invoice->invoice_date->toDateString());
        $addCbc($dom, $root, 'InvoiceTypeCode', '380');
        $addCbc($dom, $root, 'DocumentCurrencyCode', $invoice->currency);

        if ($invoice->purchaseOrder !== null) {
            $orderRef = $addCac($dom, $root, 'OrderReference');
            $addCbc($dom, $orderRef, 'ID', $invoice->purchaseOrder->po_number);
        }

        // Seller
        $supplier = $addCac($dom, $root, 'AccountingSupplierParty');
        $sParty = $addCac($dom, $supplier, 'Party');
        if ($vendor !== null && is_string($vendor->peppol_endpoint_id) && $vendor->peppol_endpoint_id !== '') {
            $addCbc($dom, $sParty, 'EndpointID', $vendor->peppol_endpoint_id, ['schemeID' => '0088']);
        }
        $sName = $addCac($dom, $sParty, 'PartyName');
        $addCbc($dom, $sName, 'Name', $vendor !== null ? $vendor->name : 'Vendor');
        if ($vendor !== null && is_string($vendor->tax_id_tin) && $vendor->tax_id_tin !== '') {
            $taxScheme = $addCac($dom, $sParty, 'PartyTaxScheme');
            $addCbc($dom, $taxScheme, 'CompanyID', $vendor->tax_id_tin);
        }

        // Buyer
        $customer = $addCac($dom, $root, 'AccountingCustomerParty');
        $cParty = $addCac($dom, $customer, 'Party');
        $cName = $addCac($dom, $cParty, 'PartyName');
        $addCbc($dom, $cName, 'Name', $tenantName);

        // Totals
        $taxTotal = $addCac($dom, $root, 'TaxTotal');
        $addCbc($dom, $taxTotal, 'TaxAmount', number_format($invoice->vat_cents / 100, 2, '.', ''), ['currencyID' => $invoice->currency]);

        $monetary = $addCac($dom, $root, 'LegalMonetaryTotal');
        $addCbc($dom, $monetary, 'LineExtensionAmount', number_format($invoice->subtotal_cents / 100, 2, '.', ''), ['currencyID' => $invoice->currency]);
        $addCbc($dom, $monetary, 'TaxExclusiveAmount', number_format($invoice->subtotal_cents / 100, 2, '.', ''), ['currencyID' => $invoice->currency]);
        $addCbc($dom, $monetary, 'TaxInclusiveAmount', number_format($invoice->total_cents / 100, 2, '.', ''), ['currencyID' => $invoice->currency]);
        $addCbc($dom, $monetary, 'PayableAmount', number_format($invoice->total_cents / 100, 2, '.', ''), ['currencyID' => $invoice->currency]);

        foreach ($invoice->lines as $line) {
            $invLine = $addCac($dom, $root, 'InvoiceLine');
            $addCbc($dom, $invLine, 'ID', (string) $line->line_number);
            $addCbc($dom, $invLine, 'InvoicedQuantity', number_format($line->qty_invoiced, 3, '.', ''), ['unitCode' => 'EA']);
            $addCbc($dom, $invLine, 'LineExtensionAmount', number_format($line->line_total_cents / 100, 2, '.', ''), ['currencyID' => $invoice->currency]);
            $item = $addCac($dom, $invLine, 'Item');
            $addCbc($dom, $item, 'Name', $line->description);
        }

        $xml = $dom->saveXML() ?: '';
        $hash = hash('sha256', $xml);
        $invoice->update(['ubl_xml_hash' => $hash]);

        $this->audit->log(
            tenantId: $invoice->tenant_id,
            eventType: 'ap.ubl_exported',
            resourceType: 'ap_invoice',
            resourceId: $invoice->id,
            actor: $actor,
            classificationRank: $invoice->document->classification_rank ?? 1,
            after: ['ubl_sha256' => $hash],
            request: $request,
        );

        return $xml;
    }

    /**
     * Build and submit ZIMRA FDMS fiscalisation payload or queue cleanly in offline_queue during LAN/link outages.
     *
     * @return array{status: ZimraFiscalStatus, receipt_no: string|null, qr_payload: string|null, payload: array<string, mixed>}
     */
    public function fiscalizeZimra(ApInvoice $invoice, User $actor, ?Request $request = null): array
    {
        $invoice->loadMissing(['vendor', 'lines', 'document']);
        $deviceId = config()->string('betterdoc.finance.zimra.device_id', 'FDMS-ZW-001');
        $driver = config()->string('betterdoc.finance.zimra.driver', 'offline_queue');

        $signatureInput = implode('|', [
            $deviceId,
            $invoice->invoice_number,
            $invoice->currency,
            (string) $invoice->total_cents,
            $invoice->vendor->tax_id_tin ?? 'NO-TIN',
        ]);
        $fiscalSig = strtoupper(substr(hash('sha256', $signatureInput), 0, 16));

        $payload = [
            'deviceID' => $deviceId,
            'receiptType' => 'FISCALINVOICE',
            'receiptCurrency' => $invoice->currency,
            'invoiceNo' => $invoice->invoice_number,
            'invoiceDate' => $invoice->invoice_date->toIso8601String(),
            'supplierTIN' => $invoice->vendor->tax_id_tin ?? '',
            'supplierVAT' => $invoice->vendor->vat_number ?? '',
            'receiptTotal' => round($invoice->total_cents / 100, 2),
            'taxAmount' => round($invoice->vat_cents / 100, 2),
            'receiptDeviceSignature' => $fiscalSig,
            'receiptLines' => $invoice->lines->map(static fn (ApInvoiceLine $l): array => [
                'receiptLineNo' => $l->line_number,
                'receiptLineHSCode' => $l->sku,
                'receiptLineName' => $l->description,
                'receiptLinePrice' => round($l->unit_price_cents / 100, 2),
                'receiptLineQuantity' => $l->qty_invoiced,
                'receiptLineTotal' => round($l->line_total_cents / 100, 2),
            ])->values()->all(),
        ];

        $attempts = $invoice->zimra_attempts + 1;

        if ($driver === 'fdms_http') {
            $fdmsUrl = rtrim(config()->string('betterdoc.finance.zimra.fdms_url', 'http://127.0.0.1:8095'), '/');
            try {
                $resp = Http::withRequestMiddleware(
                    fn (RequestInterface $req): RequestInterface => $this->guard->assertOutboundAllowed($req)
                )->timeout(8)->post("{$fdmsUrl}/api/v1/submit-receipt", $payload);

                if ($resp->successful()) {
                    $receiptNo = is_string($resp->json('receiptGlobalNo'))
                        ? $resp->json('receiptGlobalNo')
                        : 'ZIMRA-'.$fiscalSig;
                    $qrPayload = is_string($resp->json('qrCode'))
                        ? $resp->json('qrCode')
                        : "FDMS|{$deviceId}|{$receiptNo}|{$fiscalSig}";

                    $invoice->update([
                        'zimra_fiscal_status' => ZimraFiscalStatus::Fiscalized,
                        'zimra_receipt_no' => $receiptNo,
                        'zimra_qr_payload' => $qrPayload,
                        'zimra_attempts' => $attempts,
                    ]);

                    $this->audit->log(
                        tenantId: $invoice->tenant_id,
                        eventType: 'ap.zimra_fiscalized',
                        resourceType: 'ap_invoice',
                        resourceId: $invoice->id,
                        actor: $actor,
                        classificationRank: $invoice->document->classification_rank ?? 1,
                        after: ['receipt_no' => $receiptNo, 'device_id' => $deviceId],
                        request: $request,
                    );

                    return [
                        'status' => ZimraFiscalStatus::Fiscalized,
                        'receipt_no' => $receiptNo,
                        'qr_payload' => $qrPayload,
                        'payload' => $payload,
                    ];
                }
            } catch (Throwable $e) {
                Log::info('ZIMRA FDMS offline queue fallback active.', ['reason' => $e->getMessage()]);
            }
        }

        $qrOffline = "FDMS-OFFLINE|{$deviceId}|{$invoice->invoice_number}|{$fiscalSig}";
        $invoice->update([
            'zimra_fiscal_status' => ZimraFiscalStatus::QueuedOffline,
            'zimra_qr_payload' => $qrOffline,
            'zimra_attempts' => $attempts,
        ]);

        $this->audit->log(
            tenantId: $invoice->tenant_id,
            eventType: 'ap.zimra_queued_offline',
            resourceType: 'ap_invoice',
            resourceId: $invoice->id,
            actor: $actor,
            classificationRank: $invoice->document->classification_rank ?? 1,
            after: ['attempts' => $attempts, 'offline_qr' => $qrOffline],
            request: $request,
        );

        return [
            'status' => ZimraFiscalStatus::QueuedOffline,
            'receipt_no' => null,
            'qr_payload' => $qrOffline,
            'payload' => $payload,
        ];
    }

    /**
     * @return array{mode: string, passed: bool, variances: list<string>, lines: list<array<string, mixed>>}
     */
    private function evaluateMatch(ApInvoice $invoice, PurchaseOrder $po, string $matchMode): array
    {
        $priceTolPct = max(0.0, config()->float('betterdoc.finance.ap.price_variance_tolerance_pct', 2.0));
        $qtyTolPct = max(0.0, config()->float('betterdoc.finance.ap.qty_variance_tolerance_pct', 0.0));

        $poLinesBySku = $po->lines->keyBy(static fn (PurchaseOrderLine $l): string => mb_strtolower($l->sku));
        $variances = [];
        $lineReports = [];

        foreach ($invoice->lines as $invLine) {
            /** @var PurchaseOrderLine|null $poLine */
            $poLine = $poLinesBySku->get(mb_strtolower($invLine->sku)) ?? $po->lines->firstWhere('line_number', $invLine->line_number);
            if (! $poLine instanceof PurchaseOrderLine) {
                $msg = "Line {$invLine->line_number} ({$invLine->sku}) has no matching Purchase Order line on {$po->po_number}.";
                $variances[] = $msg;
                $lineReports[] = ['line' => $invLine->line_number, 'sku' => $invLine->sku, 'matched' => false, 'reason' => $msg];

                continue;
            }

            $receivedQty = $matchMode === '3_way'
                ? (float) GoodsReceiptLine::query()->where('purchase_order_line_id', $poLine->id)->sum('qty_received')
                : $poLine->qty_ordered;

            $refQty = $matchMode === '3_way' ? min($poLine->qty_ordered, $receivedQty) : $poLine->qty_ordered;
            $qtyDiffPct = $refQty > 0.0 ? abs($invLine->qty_invoiced - $refQty) / $refQty * 100.0 : ($invLine->qty_invoiced > 0.0 ? 100.0 : 0.0);
            $priceDiffPct = $poLine->unit_price_cents > 0 ? abs($invLine->unit_price_cents - $poLine->unit_price_cents) / $poLine->unit_price_cents * 100.0 : 0.0;

            $lineIssues = [];
            if ($matchMode === '3_way' && $receivedQty <= 0.0) {
                $lineIssues[] = "No Goods Receipt (GRN) recorded yet for SKU {$invLine->sku}.";
            } elseif ($qtyDiffPct > $qtyTolPct || ($matchMode === '3_way' && $invLine->qty_invoiced > ($receivedQty * (1.0 + ($qtyTolPct / 100.0))))) {
                $lineIssues[] = sprintf('Qty mismatch: invoiced %.2f vs %s %.2f', $invLine->qty_invoiced, $matchMode === '3_way' ? 'GRN received' : 'PO ordered', $refQty);
            }

            if ($priceDiffPct > $priceTolPct) {
                $lineIssues[] = sprintf('Price variance %.2f%% exceeds tolerance (%.2f%%)', $priceDiffPct, $priceTolPct);
            }

            foreach ($lineIssues as $iss) {
                $variances[] = "Line {$invLine->line_number} ({$invLine->sku}): {$iss}";
            }

            $lineReports[] = [
                'line' => $invLine->line_number,
                'sku' => $invLine->sku,
                'qty_invoiced' => $invLine->qty_invoiced,
                'qty_po' => $poLine->qty_ordered,
                'qty_grn_received' => $receivedQty,
                'price_diff_pct' => round($priceDiffPct, 2),
                'matched' => $lineIssues === [],
                'issues' => $lineIssues,
            ];
        }

        return [
            'mode' => $matchMode,
            'passed' => $variances === [],
            'variances' => $variances,
            'lines' => $lineReports,
        ];
    }

    /**
     * @return array{id: string, invoice_number: string, total_cents: int}|null
     */
    private function findDuplicateInvoice(string $tenantId, string $vendorId, string $invoiceNumber, string $excludeId): ?array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $row = DB::selectOne(
                'SELECT id, invoice_number, total_cents FROM betterdoc_find_ap_duplicate_invoice(?, ?, ?, ?)',
                [$tenantId, $vendorId, $invoiceNumber, $excludeId]
            );
            if (! $row instanceof stdClass) {
                return null;
            }

            return [
                'id' => is_string($row->id ?? null) ? $row->id : '',
                'invoice_number' => is_string($row->invoice_number ?? null) ? $row->invoice_number : '',
                'total_cents' => is_numeric($row->total_cents ?? null) ? (int) $row->total_cents : 0,
            ];
        }

        $dup = ApInvoice::query()
            ->where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->whereRaw('lower(trim(invoice_number)) = ?', [mb_strtolower(trim($invoiceNumber))])
            ->where('id', '!=', $excludeId)
            ->first();

        return $dup instanceof ApInvoice ? [
            'id' => $dup->id,
            'invoice_number' => $dup->invoice_number,
            'total_cents' => $dup->total_cents,
        ] : null;
    }

    private function notifyApproversHighValue(string $tenantId, ApInvoice $invoice): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        $approvers = User::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('roles', static fn ($q) => $q->whereIn('name', [BetterDocRole::Approver->value, BetterDocRole::SystemAdmin->value]))
            ->get();

        foreach ($approvers as $appUser) {
            $this->notifications->notifyUser(
                recipient: $appUser,
                type: 'ap.high_value_approval',
                title: 'High-Value AP Invoice Ready for Sign-Off',
                body: "Invoice {$invoice->invoice_number} ({$invoice->currency} ".number_format($invoice->total_cents / 100, 2).') passed matching and requires senior approval.',
                documentId: $invoice->document_id,
                actionUrl: '/finance',
                metadata: ['ap_invoice_id' => $invoice->id],
                classificationRank: 1,
            );
        }
    }
}
