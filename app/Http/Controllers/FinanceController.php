<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BetterDocRole;
use App\Enums\DocumentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\ApInvoice;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Finance\ApAutomationService;
use App\Services\Integration\IntegrationWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class FinanceController extends Controller
{
    public function __construct(
        private readonly ApAutomationService $ap,
        private readonly ImmutableAuditLogger $audit,
        private readonly IntegrationWebhookService $webhooks,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $vendors = Vendor::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('name')
            ->get()
            ->toArray();

        $purchaseOrders = PurchaseOrder::query()
            ->with(['vendor', 'lines.receiptLines', 'goodsReceipts'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(30)
            ->get()
            ->map(static fn (PurchaseOrder $po): array => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'vendor_id' => $po->vendor_id,
                'vendor_name' => $po->vendor->name ?? '',
                'status' => $po->status->value,
                'currency' => $po->currency,
                'total_cents' => $po->total_cents,
                'issued_at' => $po->issued_at->toDateString(),
                'grn_count' => $po->goodsReceipts->count(),
                'lines' => $po->lines->map(static function (PurchaseOrderLine $l): array {
                    $sumQty = $l->receiptLines->sum('qty_received');

                    return [
                        'id' => $l->id,
                        'line_number' => $l->line_number,
                        'sku' => $l->sku,
                        'description' => $l->description,
                        'qty_ordered' => $l->qty_ordered,
                        'qty_received' => is_numeric($sumQty) ? (float) $sumQty : 0.0,
                        'unit_price_cents' => $l->unit_price_cents,
                    ];
                })->values()->all(),
            ])
            ->values()
            ->all();

        $invoices = ApInvoice::query()
            ->with(['vendor', 'purchaseOrder', 'document', 'lines', 'approver'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(40)
            ->get()
            ->map(static fn (ApInvoice $inv): array => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'invoice_date' => $inv->invoice_date->toDateString(),
                'vendor_name' => $inv->vendor->name ?? '',
                'vendor_tin' => $inv->vendor->tax_id_tin ?? '',
                'po_number' => $inv->purchaseOrder?->po_number,
                'document_id' => $inv->document_id,
                'doc_number' => $inv->document->doc_number ?? '',
                'currency' => $inv->currency,
                'subtotal_cents' => $inv->subtotal_cents,
                'vat_cents' => $inv->vat_cents,
                'total_cents' => $inv->total_cents,
                'status' => $inv->status->value,
                'match_mode' => $inv->match_mode,
                'match_report' => $inv->match_report_json ?? [],
                'zimra_fiscal_status' => $inv->zimra_fiscal_status->value,
                'zimra_receipt_no' => $inv->zimra_receipt_no,
                'zimra_qr_payload' => $inv->zimra_qr_payload,
                'ubl_xml_hash' => $inv->ubl_xml_hash,
                'approver_name' => $inv->approver?->name,
                'lines' => $inv->lines->toArray(),
            ])
            ->values()
            ->all();

        $documents = Document::query()
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(40)
            ->get(['id', 'doc_number', 'title', 'classification_rank'])
            ->toArray();

        $cabinets = Cabinet::query()->where('tenant_id', $user->tenant_id)->orderBy('name')->get(['id', 'name'])->toArray();
        $levels = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('rank', '<=', $user->clearance_rank)
            ->orderBy('rank')
            ->get(['id', 'rank', 'name'])
            ->toArray();

        return Inertia::render('Finance/Index', [
            'vendors' => $vendors,
            'purchaseOrders' => $purchaseOrders,
            'invoices' => $invoices,
            'clearedDocuments' => $documents,
            'cabinets' => $cabinets,
            'allowedClearances' => $levels,
            'canApproveInvoices' => $user->hasAnyRole([BetterDocRole::Approver->value, BetterDocRole::SystemAdmin->value]),
        ]);
    }

    public function storeVendor(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{code: string, name: string, tax_id_tin?: string|null, vat_number?: string|null, currency?: string, peppol_endpoint_id?: string|null, email?: string|null} $validated */
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:160'],
            'tax_id_tin' => ['nullable', 'string', 'max:48'],
            'vat_number' => ['nullable', 'string', 'max:48'],
            'currency' => ['nullable', 'string', 'max:8'],
            'peppol_endpoint_id' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
        ]);

        $vendor = Vendor::query()->create([
            'tenant_id' => $user->tenant_id,
            'code' => strtoupper(trim($validated['code'])),
            'name' => trim($validated['name']),
            'tax_id_tin' => $validated['tax_id_tin'] ?? null,
            'vat_number' => $validated['vat_number'] ?? null,
            'currency' => strtoupper($validated['currency'] ?? 'USD'),
            'peppol_endpoint_id' => $validated['peppol_endpoint_id'] ?? null,
            'email' => $validated['email'] ?? null,
            'is_active' => true,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['vendor' => $vendor], 201);
        }

        return back()->with('success', "Vendor '{$vendor->name}' registered.");
    }

    public function storePurchaseOrder(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{vendor_id: string, po_number: string, issued_at?: string|null, currency?: string, lines: list<array{sku: string, description: string, qty_ordered: float|int, unit_price_cents: int}>} $validated */
        $validated = $request->validate([
            'vendor_id' => ['required', 'uuid'],
            'po_number' => ['required', 'string', 'max:64'],
            'issued_at' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'max:8'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku' => ['required', 'string', 'max:64'],
            'lines.*.description' => ['required', 'string', 'max:200'],
            'lines.*.qty_ordered' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price_cents' => ['required', 'integer', 'min:0'],
        ]);

        /** @var Vendor $vendor */
        $vendor = Vendor::query()->where('tenant_id', $user->tenant_id)->where('id', $validated['vendor_id'])->firstOrFail();

        $po = DB::transaction(function () use ($user, $vendor, $validated): PurchaseOrder {
            $subtotal = 0;
            foreach ($validated['lines'] as $l) {
                $subtotal += (int) round((float) $l['qty_ordered'] * (int) $l['unit_price_cents']);
            }
            $tax = (int) round($subtotal * 0.15);

            /** @var PurchaseOrder $created */
            $created = PurchaseOrder::query()->create([
                'tenant_id' => $user->tenant_id,
                'vendor_id' => $vendor->id,
                'created_by' => $user->id,
                'po_number' => strtoupper(trim($validated['po_number'])),
                'status' => PurchaseOrderStatus::Open,
                'currency' => strtoupper($validated['currency'] ?? $vendor->currency),
                'subtotal_cents' => $subtotal,
                'tax_cents' => $tax,
                'total_cents' => $subtotal + $tax,
                'issued_at' => $validated['issued_at'] ?? now('UTC')->toDateString(),
            ]);

            foreach ($validated['lines'] as $idx => $l) {
                PurchaseOrderLine::query()->create([
                    'tenant_id' => $user->tenant_id,
                    'purchase_order_id' => $created->id,
                    'line_number' => $idx + 1,
                    'sku' => trim($l['sku']),
                    'description' => trim($l['description']),
                    'qty_ordered' => (float) $l['qty_ordered'],
                    'unit_price_cents' => (int) $l['unit_price_cents'],
                    'tax_rate_bps' => 1500,
                ]);
            }

            return $created->load('lines');
        });

        if ($request->wantsJson()) {
            return response()->json(['purchase_order' => $po], 201);
        }

        return back()->with('success', "Purchase Order {$po->po_number} created.");
    }

    public function storeGoodsReceipt(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($purchaseOrder->tenant_id === $user->tenant_id, 404);

        /** @var array{grn_number: string, notes?: string|null, lines: list<array{purchase_order_line_id: string, qty_received: float|int}>} $validated */
        $validated = $request->validate([
            'grn_number' => ['required', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'uuid'],
            'lines.*.qty_received' => ['required', 'numeric', 'min:0.001'],
        ]);

        $grn = DB::transaction(function () use ($user, $purchaseOrder, $validated): GoodsReceipt {
            /** @var GoodsReceipt $created */
            $created = GoodsReceipt::query()->create([
                'tenant_id' => $user->tenant_id,
                'purchase_order_id' => $purchaseOrder->id,
                'received_by' => $user->id,
                'grn_number' => strtoupper(trim($validated['grn_number'])),
                'received_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['lines'] as $l) {
                /** @var PurchaseOrderLine $poLine */
                $poLine = PurchaseOrderLine::query()
                    ->where('purchase_order_id', $purchaseOrder->id)
                    ->where('id', $l['purchase_order_line_id'])
                    ->firstOrFail();

                GoodsReceiptLine::query()->create([
                    'tenant_id' => $user->tenant_id,
                    'goods_receipt_id' => $created->id,
                    'purchase_order_line_id' => $poLine->id,
                    'qty_received' => (float) $l['qty_received'],
                ]);
            }

            $purchaseOrder->update(['status' => PurchaseOrderStatus::Received]);

            return $created->load('lines');
        });

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: 'ap.grn_recorded',
            resourceType: 'goods_receipt',
            resourceId: $grn->id,
            actor: $user,
            classificationRank: 1,
            after: ['grn_number' => $grn->grn_number, 'po_number' => $purchaseOrder->po_number],
            request: $request,
        );

        if ($request->wantsJson()) {
            return response()->json(['goods_receipt' => $grn], 201);
        }

        return back()->with('success', "Goods Receipt {$grn->grn_number} recorded against {$purchaseOrder->po_number}.");
    }

    public function storeInvoice(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{vendor_id: string, purchase_order_id?: string|null, document_id?: string|null, cabinet_id?: string|null, classification_level_id?: string|null, invoice_number: string, invoice_date: string, due_date?: string|null, currency?: string, subtotal_cents: int, vat_cents: int, total_cents: int, match_mode?: string, lines: list<array{sku: string, description: string, qty_invoiced: float|int, unit_price_cents: int}>} $validated */
        $validated = $request->validate([
            'vendor_id' => ['required', 'uuid'],
            'purchase_order_id' => ['nullable', 'uuid'],
            'document_id' => ['nullable', 'uuid'],
            'cabinet_id' => ['nullable', 'uuid'],
            'classification_level_id' => ['nullable', 'uuid'],
            'invoice_number' => ['required', 'string', 'max:64'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'max:8'],
            'subtotal_cents' => ['required', 'integer', 'min:0'],
            'vat_cents' => ['required', 'integer', 'min:0'],
            'total_cents' => ['required', 'integer', 'min:0'],
            'match_mode' => ['nullable', Rule::in(['2_way', '3_way'])],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku' => ['required', 'string', 'max:64'],
            'lines.*.description' => ['required', 'string', 'max:200'],
            'lines.*.qty_invoiced' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price_cents' => ['required', 'integer', 'min:0'],
        ]);

        /** @var Vendor $vendor */
        $vendor = Vendor::query()->where('tenant_id', $user->tenant_id)->where('id', $validated['vendor_id'])->firstOrFail();

        $po = ! empty($validated['purchase_order_id'])
            ? PurchaseOrder::query()->where('tenant_id', $user->tenant_id)->where('id', $validated['purchase_order_id'])->first()
            : null;

        $matchMode = ($validated['match_mode'] ?? '3_way') === '2_way' ? '2_way' : '3_way';
        $cabinetId = $validated['cabinet_id'] ?? null;
        $levelId = $validated['classification_level_id'] ?? null;

        $document = null;
        if (! empty($validated['document_id'])) {
            $document = Document::query()->where('tenant_id', $user->tenant_id)->where('id', $validated['document_id'])->firstOrFail();
            Gate::authorize('view', $document);
        } else {
            $cab = Cabinet::query()->where('tenant_id', $user->tenant_id)
                ->when(is_string($cabinetId) && $cabinetId !== '', static fn ($q) => $q->where('id', $cabinetId))
                ->firstOrFail();
            $level = ClearanceLevel::query()->where('tenant_id', $user->tenant_id)
                ->where('rank', '<=', $user->clearance_rank)
                ->when(is_string($levelId) && $levelId !== '', static fn ($q) => $q->where('id', $levelId))
                ->orderBy('rank')
                ->firstOrFail();

            /** @var Document $document */
            $document = Document::query()->create([
                'tenant_id' => $user->tenant_id,
                'cabinet_id' => $cab->id,
                'org_unit_id' => $user->org_unit_id,
                'owner_id' => $user->id,
                'classification_level_id' => $level->id,
                'classification_rank' => $level->rank,
                'doc_number' => 'AP/'.$validated['invoice_number'],
                'title' => "Vendor Invoice {$validated['invoice_number']} — {$vendor->name}",
                'summary' => "AP invoice submitted for {$matchMode} match.",
                'status' => DocumentStatus::Active,
                'unit_wide_readable' => true,
            ]);
        }

        $typedLines = [];
        foreach ($validated['lines'] as $l) {
            $typedLines[] = [
                'sku' => $l['sku'],
                'description' => $l['description'],
                'qty_invoiced' => (float) $l['qty_invoiced'],
                'unit_price_cents' => (int) $l['unit_price_cents'],
            ];
        }

        $invoice = $this->ap->captureAndMatch(
            actor: $user,
            vendor: $vendor,
            document: $document,
            po: $po,
            header: [
                'invoice_number' => $validated['invoice_number'],
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'] ?? null,
                'currency' => $validated['currency'] ?? $vendor->currency,
                'subtotal_cents' => (int) $validated['subtotal_cents'],
                'vat_cents' => (int) $validated['vat_cents'],
                'total_cents' => (int) $validated['total_cents'],
                'match_mode' => $matchMode,
            ],
            lines: $typedLines,
            request: $request,
        );

        if ($request->wantsJson()) {
            return response()->json(['invoice' => $invoice->load('lines')], 201);
        }

        return back()->with('success', "Invoice {$invoice->invoice_number} processed (Status: {$invoice->status->value}).");
    }

    public function approveInvoice(Request $request, ApInvoice $apInvoice): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($apInvoice->tenant_id === $user->tenant_id, 404);
        abort_unless($user->hasAnyRole([BetterDocRole::Approver->value, BetterDocRole::SystemAdmin->value]), 403);

        /** @var array{override_reason?: string|null} $validated */
        $validated = $request->validate([
            'override_reason' => ['nullable', 'string', 'max:400'],
        ]);

        try {
            $approved = $this->ap->approveInvoice($apInvoice, $user, $validated['override_reason'] ?? null, $request);
        } catch (RuntimeException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            throw ValidationException::withMessages(['invoice' => $e->getMessage()]);
        }

        $this->webhooks->emit(
            tenantId: $user->tenant_id,
            eventType: 'ap.invoice_approved',
            data: [
                'invoice_id' => $approved->id,
                'invoice_number' => $approved->invoice_number,
                'vendor_id' => $approved->vendor_id,
                'currency' => $approved->currency,
                'total_cents' => $approved->total_cents,
                'document_id' => $approved->document_id,
            ],
            actor: $user,
        );

        if ($request->wantsJson()) {
            return response()->json(['invoice' => $approved]);
        }

        return back()->with('success', "AP Invoice {$approved->invoice_number} approved.");
    }

    public function exportUbl(Request $request, ApInvoice $apInvoice): HttpResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($apInvoice->tenant_id === $user->tenant_id, 404);
        Gate::authorize('view', $apInvoice->document);

        $xml = $this->ap->generateUbl21Xml($apInvoice, $user, $request);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ubl-invoice-'.preg_replace('/[^A-Za-z0-9\-]/', '_', $apInvoice->invoice_number).'.xml"',
        ]);
    }

    public function fiscalizeZimra(Request $request, ApInvoice $apInvoice): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($apInvoice->tenant_id === $user->tenant_id, 404);
        Gate::authorize('view', $apInvoice->document);

        $res = $this->ap->fiscalizeZimra($apInvoice, $user, $request);

        if ($request->wantsJson()) {
            return response()->json([
                'zimra_fiscal_status' => $res['status']->value,
                'receipt_no' => $res['receipt_no'],
                'qr_payload' => $res['qr_payload'],
                'payload' => $res['payload'],
            ]);
        }

        return back()->with('success', "ZIMRA FDMS status: {$res['status']->value} ({$res['qr_payload']}).");
    }
}
