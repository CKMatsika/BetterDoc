<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApInvoiceStatus;
use App\Enums\BetterDocRole;
use App\Enums\DocumentStatus;
use App\Enums\ErpSystemType;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ApInvoice;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\ExternalMapping;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Hr\HrVaultService;
use App\Services\Integration\IntegrationWebhookService;
use App\Services\Upload\ResumableUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;

class IntegrationApiController extends Controller
{
    public function __construct(
        private readonly ResumableUploadService $uploadService,
        private readonly HrVaultService $hr,
        private readonly IntegrationWebhookService $webhooks,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'api_version' => 'v1',
            'air_gapped' => config()->boolean('betterdoc.air_gapped', true),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'clearance_rank' => $user->clearance_rank,
            ],
            'tenant' => [
                'id' => $user->tenant_id,
                'slug' => $user->tenant?->slug,
                'name' => $user->tenant?->name,
            ],
            'abilities' => $token->abilities ?? [],
        ]);
    }

    public function listDocuments(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $q = trim((string) $request->query('q', ''));
        $policyNumber = trim((string) $request->query('policy_number', ''));
        $claimNumber = trim((string) $request->query('claim_number', ''));
        $externalSystem = trim((string) $request->query('external_system', ''));
        $externalReference = trim((string) $request->query('external_reference', ''));

        $documents = Document::query()
            ->where('tenant_id', $user->tenant_id)
            ->when($q !== '', static function ($query) use ($q): void {
                $query->where(static function ($inner) use ($q): void {
                    $inner->where('doc_number', 'like', "%{$q}%")
                        ->orWhere('title', 'like', "%{$q}%")
                        ->orWhere('policy_number', 'like', "%{$q}%")
                        ->orWhere('claim_number', 'like', "%{$q}%");
                });
            })
            ->when($policyNumber !== '', static fn ($query) => $query->where('policy_number', $policyNumber))
            ->when($claimNumber !== '', static fn ($query) => $query->where('claim_number', $claimNumber))
            ->when($externalSystem !== '', static fn ($query) => $query->where('external_system', $externalSystem))
            ->when($externalReference !== '', static fn ($query) => $query->where('external_reference', $externalReference))
            ->latest()
            ->limit(50)
            ->get()
            ->map(static fn (Document $doc): array => [
                'id' => $doc->id,
                'doc_number' => $doc->doc_number,
                'title' => $doc->title,
                'status' => $doc->status->value,
                'classification_rank' => $doc->classification_rank,
                'policy_number' => $doc->policy_number,
                'claim_number' => $doc->claim_number,
                'external_system' => $doc->external_system,
                'external_reference' => $doc->external_reference,
                'metadata' => $doc->metadata_json,
                'updated_at' => $doc->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $documents]);
    }

    public function showDocument(Request $request, Document $document): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($document->tenant_id === $user->tenant_id, 404);
        Gate::authorize('view', $document);

        $mappings = ExternalMapping::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('entity_type', 'document')
            ->where('internal_id', $document->id)
            ->get(['id', 'external_system', 'external_id', 'metadata_json'])
            ->toArray();

        return response()->json([
            'document' => [
                'id' => $document->id,
                'doc_number' => $document->doc_number,
                'title' => $document->title,
                'summary' => $document->summary,
                'status' => $document->status->value,
                'classification_rank' => $document->classification_rank,
                'policy_number' => $document->policy_number,
                'claim_number' => $document->claim_number,
                'external_system' => $document->external_system,
                'external_reference' => $document->external_reference,
                'metadata' => $document->metadata_json,
                'current_version_id' => $document->current_version_id,
                'external_mappings' => $mappings,
            ],
        ]);
    }

    public function storeDocument(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{
         *   title: string,
         *   doc_number?: string|null,
         *   summary?: string|null,
         *   cabinet_id?: string|null,
         *   classification_level_id?: string|null,
         *   external_system?: string|null,
         *   external_reference?: string|null,
         *   policy_number?: string|null,
         *   claim_number?: string|null,
         *   content_text?: string|null,
         *   metadata?: array<string, mixed>|null
         * } $validated
         */
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:240'],
            'doc_number' => ['nullable', 'string', 'max:80'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'cabinet_id' => ['nullable', 'uuid'],
            'classification_level_id' => ['nullable', 'uuid'],
            'external_system' => ['nullable', Rule::in(ErpSystemType::values())],
            'external_reference' => ['nullable', 'string', 'max:128'],
            'policy_number' => ['nullable', 'string', 'max:96'],
            'claim_number' => ['nullable', 'string', 'max:96'],
            'content_text' => ['nullable', 'string', 'max:200000'],
            'metadata' => ['nullable', 'array'],
            'file' => ['nullable', 'file', 'mimes:pdf,txt,png,jpg,jpeg,xml,csv', 'max:20480'],
        ]);

        /** @var ClearanceLevel $level */
        $level = ! empty($validated['classification_level_id'])
            ? ClearanceLevel::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('id', $validated['classification_level_id'])
                ->firstOrFail()
            : ClearanceLevel::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('rank', '<=', $user->clearance_rank)
                ->orderBy('rank')
                ->firstOrFail();

        if ($level->rank > $user->clearance_rank) {
            return response()->json([
                'message' => 'Cannot ingest document classified above token user clearance rank.',
            ], 403);
        }

        /** @var Cabinet $cabinet */
        $cabinet = ! empty($validated['cabinet_id'])
            ? Cabinet::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('id', $validated['cabinet_id'])
                ->firstOrFail()
            : Cabinet::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('default_classification_rank', '<=', $user->clearance_rank)
                ->orderBy('default_classification_rank')
                ->firstOrFail();

        $docNumber = ! empty($validated['doc_number'])
            ? trim($validated['doc_number'])
            : 'EXT/'.strtoupper(substr(Str::uuid()->toString(), 0, 8));

        /** @var Document $document */
        $document = DB::transaction(function () use ($user, $cabinet, $level, $docNumber, $validated): Document {
            /** @var Document $created */
            $created = Document::query()->create([
                'tenant_id' => $user->tenant_id,
                'cabinet_id' => $cabinet->id,
                'org_unit_id' => $user->org_unit_id,
                'owner_id' => $user->id,
                'classification_level_id' => $level->id,
                'classification_rank' => $level->rank,
                'doc_number' => $docNumber,
                'title' => trim($validated['title']),
                'summary' => $validated['summary'] ?? null,
                'status' => DocumentStatus::Active,
                'unit_wide_readable' => true,
                'external_system' => $validated['external_system'] ?? null,
                'external_reference' => $validated['external_reference'] ?? null,
                'policy_number' => $validated['policy_number'] ?? null,
                'claim_number' => $validated['claim_number'] ?? null,
                'metadata_json' => $validated['metadata'] ?? null,
            ]);

            if (! empty($validated['external_system']) && ! empty($validated['external_reference'])) {
                ExternalMapping::query()->updateOrCreate(
                    [
                        'tenant_id' => $user->tenant_id,
                        'external_system' => $validated['external_system'],
                        'entity_type' => 'document',
                        'external_id' => $validated['external_reference'],
                    ],
                    [
                        'internal_id' => $created->id,
                        'metadata_json' => array_filter([
                            'policy_number' => $validated['policy_number'] ?? null,
                            'claim_number' => $validated['claim_number'] ?? null,
                        ]),
                    ]
                );
            }

            return $created;
        });

        $uploadedFile = $request->file('file');
        if ($uploadedFile instanceof UploadedFile) {
            $realPath = $uploadedFile->getRealPath();
            if (is_string($realPath) && $realPath !== '') {
                $this->uploadService->ingestFileIntoDocumentVersion(
                    sourceFilePath: $realPath,
                    filename: $uploadedFile->getClientOriginalName(),
                    mimeType: $uploadedFile->getMimeType() ?? 'application/octet-stream',
                    document: $document,
                    actor: $user,
                    major: true,
                    versionNote: 'Ingested via Integration API v1',
                    request: $request,
                );
            }
        } elseif (! empty($validated['content_text'])) {
            $tmpTxt = tempnam(sys_get_temp_dir(), 'bdoc_api_').'.txt';
            file_put_contents($tmpTxt, $validated['content_text']);
            try {
                $this->uploadService->ingestFileIntoDocumentVersion(
                    sourceFilePath: $tmpTxt,
                    filename: 'api-record-'.substr($document->id, 0, 8).'.txt',
                    mimeType: 'text/plain',
                    document: $document,
                    actor: $user,
                    major: true,
                    versionNote: 'Ingested via Integration API v1',
                    request: $request,
                );
            } finally {
                @unlink($tmpTxt);
            }
        }

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: 'api.document_ingested',
            resourceType: 'document',
            resourceId: $document->id,
            actor: $user,
            classificationRank: $level->rank,
            after: [
                'doc_number' => $document->doc_number,
                'external_system' => $document->external_system,
                'policy_number' => $document->policy_number,
                'claim_number' => $document->claim_number,
            ],
            request: $request,
        );

        $this->webhooks->emit(
            tenantId: $user->tenant_id,
            eventType: 'document.ingested',
            data: [
                'document_id' => $document->id,
                'doc_number' => $document->doc_number,
                'title' => $document->title,
                'external_system' => $document->external_system,
                'external_reference' => $document->external_reference,
                'policy_number' => $document->policy_number,
                'claim_number' => $document->claim_number,
            ],
            actor: $user,
        );

        return response()->json([
            'document' => $document->refresh(),
        ], 201);
    }

    public function listVendors(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $vendors = Vendor::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('name')
            ->get()
            ->toArray();

        return response()->json(['data' => $vendors]);
    }

    public function upsertVendor(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{
         *   code: string,
         *   name: string,
         *   tax_id_tin?: string|null,
         *   vat_number?: string|null,
         *   currency?: string|null,
         *   email?: string|null,
         *   external_system?: string|null,
         *   external_id?: string|null
         * } $validated
         */
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:160'],
            'tax_id_tin' => ['nullable', 'string', 'max:48'],
            'vat_number' => ['nullable', 'string', 'max:48'],
            'currency' => ['nullable', 'string', 'max:8'],
            'email' => ['nullable', 'email', 'max:160'],
            'external_system' => ['nullable', Rule::in(ErpSystemType::values())],
            'external_id' => ['nullable', 'string', 'max:160'],
        ]);

        /** @var Vendor $vendor */
        $vendor = Vendor::query()->updateOrCreate(
            ['tenant_id' => $user->tenant_id, 'code' => strtoupper(trim($validated['code']))],
            [
                'name' => trim($validated['name']),
                'tax_id_tin' => $validated['tax_id_tin'] ?? null,
                'vat_number' => $validated['vat_number'] ?? null,
                'currency' => strtoupper($validated['currency'] ?? 'USD'),
                'email' => $validated['email'] ?? null,
                'is_active' => true,
            ]
        );

        if (! empty($validated['external_system']) && ! empty($validated['external_id'])) {
            ExternalMapping::query()->updateOrCreate(
                [
                    'tenant_id' => $user->tenant_id,
                    'external_system' => $validated['external_system'],
                    'entity_type' => 'vendor',
                    'external_id' => trim($validated['external_id']),
                ],
                [
                    'internal_id' => $vendor->id,
                    'metadata_json' => ['code' => $vendor->code, 'name' => $vendor->name],
                ]
            );
        }

        return response()->json(['vendor' => $vendor], 201);
    }

    public function listInvoices(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $statusFilter = trim((string) $request->query('status', ''));

        $invoices = ApInvoice::query()
            ->with(['vendor', 'purchaseOrder'])
            ->where('tenant_id', $user->tenant_id)
            ->when(
                $statusFilter !== '',
                static fn ($q) => $q->where('status', $statusFilter),
                static fn ($q) => $q->whereIn('status', [ApInvoiceStatus::Matched->value, ApInvoiceStatus::Approved->value, ApInvoiceStatus::Exported->value])
            )
            ->latest('invoice_date')
            ->limit(100)
            ->get()
            ->map(static fn (ApInvoice $inv): array => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'invoice_date' => $inv->invoice_date->toDateString(),
                'due_date' => $inv->due_date?->toDateString(),
                'currency' => $inv->currency,
                'subtotal_cents' => $inv->subtotal_cents,
                'vat_cents' => $inv->vat_cents,
                'total_cents' => $inv->total_cents,
                'status' => $inv->status->value,
                'zimra_fiscal_status' => $inv->zimra_fiscal_status->value,
                'document_id' => $inv->document_id,
                'vendor_code' => $inv->vendor?->code,
                'vendor_name' => $inv->vendor?->name,
                'po_number' => $inv->purchaseOrder?->po_number,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $invoices]);
    }

    public function upsertHrEmployee(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        if (! $user->hasAnyRole([BetterDocRole::SystemAdmin->value, BetterDocRole::RecordsOfficer->value, BetterDocRole::Approver->value]) && ! $user->can('hr.manage')) {
            return response()->json(['message' => 'User is not authorized to manage HR employee files.'], 403);
        }

        /** @var array{
         *   employee_number: string,
         *   email: string,
         *   name: string,
         *   department_name?: string|null,
         *   job_title?: string|null,
         *   hire_date?: string|null,
         *   external_system?: string|null
         * } $validated
         */
        $validated = $request->validate([
            'employee_number' => ['required', 'string', 'max:48'],
            'email' => ['required', 'email', 'max:160'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'department_name' => ['nullable', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'hire_date' => ['nullable', 'date'],
            'external_system' => ['nullable', Rule::in(ErpSystemType::values())],
        ]);

        /** @var Tenant $tenant */
        $tenant = $user->tenant()->firstOrFail();
        $email = strtolower(trim($validated['email']));

        /** @var User|null $employee */
        $employee = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->first();

        if ($employee === null) {
            if (User::query()->where('email', $email)->exists()) {
                return response()->json(['message' => 'Email belongs to another tenant.'], 422);
            }

            /** @var ClearanceLevel $baseLevel */
            $baseLevel = ClearanceLevel::query()
                ->where('tenant_id', $tenant->id)
                ->orderBy('rank')
                ->firstOrFail();

            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
            /** @var User $employee */
            $employee = User::query()->create([
                'tenant_id' => $tenant->id,
                'org_unit_id' => $user->org_unit_id,
                'clearance_level_id' => $baseLevel->id,
                'clearance_rank' => $baseLevel->rank,
                'service_number' => trim($validated['employee_number']),
                'rank_title' => substr(trim($validated['job_title'] ?? 'Staff'), 0, 60),
                'name' => trim($validated['name']),
                'email' => $email,
                'password' => Hash::make(Str::password(24)),
                'status' => UserStatus::Active,
                'mfa_enforced' => false,
            ]);
            $employee->syncRoles([BetterDocRole::Reader->value]);
        }

        /** @var Cabinet $hrCabinet */
        $hrCabinet = Cabinet::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('default_classification_rank')
            ->firstOrFail();

        $profile = $this->hr->ensureEmployeeProfile(
            hrActor: $user,
            employee: $employee,
            hrCabinet: $hrCabinet,
            employeeNumber: trim($validated['employee_number']),
            departmentName: trim($validated['department_name'] ?? 'General'),
            jobTitle: trim($validated['job_title'] ?? 'Staff'),
            hireDate: $validated['hire_date'] ?? null,
        );

        $extSystem = $validated['external_system'] ?? ErpSystemType::Belina->value;
        ExternalMapping::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'external_system' => $extSystem,
                'entity_type' => 'hr_employee',
                'external_id' => trim($validated['employee_number']),
            ],
            [
                'internal_id' => $profile->id,
                'metadata_json' => ['email' => $email],
            ]
        );

        return response()->json(['employee' => $profile], 201);
    }
}
