<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Mobile\MobileCaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MobilePwaController extends Controller
{
    public function __construct(
        private readonly MobileCaptureService $mobileCapture,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $cabinets = Cabinet::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Cabinet $c): array => ['id' => $c->id, 'name' => $c->name])
            ->values()
            ->all();

        $allowedClearances = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('rank', '<=', $user->clearance_rank)
            ->orderBy('rank')
            ->get(['id', 'rank', 'code', 'name'])
            ->map(static fn (ClearanceLevel $l): array => [
                'id' => $l->id,
                'rank' => $l->rank,
                'code' => $l->code,
                'name' => $l->name,
            ])
            ->values()
            ->all();

        $vendors = Vendor::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(150)
            ->get(['id', 'code', 'name', 'currency'])
            ->map(static fn (Vendor $v): array => [
                'id' => $v->id,
                'code' => $v->code,
                'name' => $v->name,
                'currency' => $v->currency,
            ])
            ->values()
            ->all();

        $purchaseOrders = PurchaseOrder::query()
            ->with('vendor')
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(80)
            ->get()
            ->map(static fn (PurchaseOrder $po): array => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'vendor_id' => $po->vendor_id,
                'vendor_name' => $po->vendor->name ?? '',
                'currency' => $po->currency,
                'total_cents' => $po->total_cents,
            ])
            ->values()
            ->all();

        $recentDocuments = Document::query()
            ->with(['cabinet', 'classificationLevel', 'currentVersion'])
            ->where('tenant_id', $user->tenant_id)
            ->where('classification_rank', '<=', $user->clearance_rank)
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (Document $d): array => $this->mobileCapture->serializeDocument($d))
            ->values()
            ->all();

        return Inertia::render('Mobile/Capture', [
            'cabinets' => $cabinets,
            'allowedClearances' => $allowedClearances,
            'vendors' => $vendors,
            'purchaseOrders' => $purchaseOrders,
            'erpTargets' => $this->mobileCapture->listAccountingErpTargets($user->tenant_id),
            'recentDocuments' => $recentDocuments,
        ]);
    }

    public function capture(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{file: UploadedFile, cabinet_id: string, classification_level_id: string, title?: string|null, auto_deskew?: bool|null, force_invoice?: bool|null} $validated */
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:25600', 'mimes:pdf,png,jpg,jpeg,webp,tif,tiff,bmp'],
            'cabinet_id' => ['required', 'uuid'],
            'classification_level_id' => ['required', 'uuid'],
            'title' => ['nullable', 'string', 'max:180'],
            'auto_deskew' => ['nullable', 'boolean'],
            'force_invoice' => ['nullable', 'boolean'],
        ]);

        $file = $validated['file'];
        $rawBytes = file_get_contents($file->getRealPath());
        if (! is_string($rawBytes) || $rawBytes === '') {
            return response()->json(['message' => 'Uploaded capture is empty.'], 422);
        }

        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($user->tenant_id);
        /** @var Cabinet $cabinet */
        $cabinet = Cabinet::query()->where('tenant_id', $user->tenant_id)->findOrFail($validated['cabinet_id']);
        /** @var ClearanceLevel $level */
        $level = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('rank', '<=', $user->clearance_rank)
            ->findOrFail($validated['classification_level_id']);

        $result = $this->mobileCapture->captureDocument(
            actor: $user,
            tenant: $tenant,
            cabinet: $cabinet,
            level: $level,
            filename: $file->getClientOriginalName() ?: 'mobile-capture.jpg',
            rawBytes: $rawBytes,
            options: [
                'title' => (string) ($validated['title'] ?? ''),
                'auto_deskew' => (bool) ($validated['auto_deskew'] ?? true),
                'force_invoice' => (bool) ($validated['force_invoice'] ?? false),
                'source_channel' => 'mobile_pwa',
            ],
            request: $request,
        );

        return response()->json($result, 201);
    }

    public function processInvoice(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('view', $document);

        /** @var User $user */
        $user = $request->user();

        /** @var array{vendor_id?: string|null, vendor_name?: string|null, purchase_order_id?: string|null, invoice_number: string, invoice_date: string, due_date?: string|null, currency?: string, subtotal_cents: int, vat_cents: int, total_cents: int, description?: string|null, match_mode?: string, auto_approve?: bool, override_reason?: string|null, erp_target_type?: string, erp_target_id?: string|null, archive_document?: bool, apply_worm_lock?: bool} $validated */
        $validated = $request->validate([
            'vendor_id' => ['nullable', 'uuid'],
            'vendor_name' => ['nullable', 'string', 'max:160'],
            'purchase_order_id' => ['nullable', 'uuid'],
            'invoice_number' => ['required', 'string', 'max:80'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'max:8'],
            'subtotal_cents' => ['required', 'integer', 'min:0'],
            'vat_cents' => ['required', 'integer', 'min:0'],
            'total_cents' => ['required', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:240'],
            'match_mode' => ['nullable', Rule::in(['2_way', '3_way'])],
            'auto_approve' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:300'],
            'erp_target_type' => ['required', Rule::in(['connection', 'bridge', 'none'])],
            'erp_target_id' => ['nullable', 'uuid'],
            'archive_document' => ['nullable', 'boolean'],
            'apply_worm_lock' => ['nullable', 'boolean'],
        ]);

        $result = $this->mobileCapture->processInvoiceToErpAndArchive(
            document: $document,
            actor: $user,
            payload: $validated,
            request: $request,
        );

        return response()->json($result);
    }

    public function manageDocument(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('view', $document);

        /** @var User $user */
        $user = $request->user();

        /** @var array{title?: string|null, cabinet_id?: string|null, status?: string|null} $validated */
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'cabinet_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in([DocumentStatus::Active->value, DocumentStatus::Archived->value])],
        ]);

        $updated = $this->mobileCapture->manageDocument($document, $user, $validated, $request);

        return response()->json([
            'document' => $this->mobileCapture->serializeDocument($updated),
        ]);
    }
}
