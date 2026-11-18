<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BetterDocRole;
use App\Enums\LocalAgentStatus;
use App\Enums\PrinterDriver;
use App\Enums\ScanSourceType;
use App\Exceptions\PrintPolicyViolationException;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\CostCentre;
use App\Models\Document;
use App\Models\LocalAgent;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\ScanBatch;
use App\Models\ScanSource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PrintScan\LocalAgentService;
use App\Services\PrintScan\PrintScanBridgeService;
use App\Services\PrintScan\PrintService;
use App\Services\PrintScan\ScanIngestionService;
use App\Services\Security\DbSecurityContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class PrintScanController extends Controller
{
    public function __construct(
        private readonly PrintService $printerService,
        private readonly PrintScanBridgeService $bridge,
        private readonly ScanIngestionService $scanIngestion,
        private readonly LocalAgentService $localAgentService,
        private readonly DbSecurityContext $dbContext,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $isOperator = $user->hasAnyRole([
            BetterDocRole::SystemAdmin->value,
            BetterDocRole::RecordsOfficer->value,
            BetterDocRole::Auditor->value,
        ]);

        $printers = Printer::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('max_classification_rank')
            ->get()
            ->map(static fn (Printer $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'location' => $p->location,
                'cups_queue_name' => $p->cups_queue_name,
                'ipps_uri' => $p->ipps_uri,
                'driver' => $p->driver->value,
                'max_classification_rank' => $p->max_classification_rank,
                'supports_secure_release' => $p->supports_secure_release,
                'supports_duplex' => $p->supports_duplex,
                'is_online' => $p->is_online,
            ])
            ->values()
            ->all();

        $costCentres = CostCentre::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('code')
            ->get()
            ->map(static fn (CostCentre $cc): array => [
                'id' => $cc->id,
                'code' => $cc->code,
                'name' => $cc->name,
                'monthly_page_quota' => $cc->monthly_page_quota,
                'pages_used_month' => $cc->pages_used_month,
                'remaining_pages' => $cc->remainingPages(),
                'is_active' => $cc->is_active,
            ])
            ->values()
            ->all();

        $printJobsQuery = PrintJob::query()
            ->with(['document', 'printer', 'costCentre', 'user'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(40);

        if (! $isOperator) {
            $printJobsQuery->where('user_id', $user->id);
        }

        $printJobs = $printJobsQuery
            ->get()
            ->map(static fn (PrintJob $j): array => [
                'id' => $j->id,
                'document_id' => $j->document_id,
                'doc_number' => $j->document->doc_number ?? 'DOC',
                'document_title' => $j->document->title ?? 'Restricted Document',
                'printer_id' => $j->printer_id,
                'printer_name' => $j->printer->name ?? 'Printer',
                'cost_centre_code' => $j->costCentre?->code,
                'user_name' => $j->user->name ?? '',
                'is_mine' => $j->user_id === $user->id,
                'status' => $j->status->value,
                'copies' => $j->copies,
                'page_count' => $j->page_count,
                'pin_attempts' => $j->pin_attempts,
                'classification_rank' => $j->classification_rank,
                'watermark_stamp_text' => $j->watermark_stamp_text,
                'cups_job_id' => $j->cups_job_id,
                'has_spool_preview' => $j->watermarked_storage_key !== null && $j->user_id === $user->id,
                'held_at' => $j->held_at?->toIso8601String(),
                'released_at' => $j->released_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $scanSources = ScanSource::query()
            ->with(['targetCabinet', 'defaultClassificationLevel'])
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('name')
            ->get()
            ->map(static fn (ScanSource $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'source_type' => $s->source_type->value,
                'endpoint_or_path' => $s->endpoint_or_path,
                'cabinet_name' => $s->targetCabinet?->name,
                'classification_name' => $s->defaultClassificationLevel?->name,
                'auto_ocr' => $s->auto_ocr,
                'auto_split_barcode' => $s->auto_split_barcode,
                'auto_deskew' => $s->auto_deskew,
                'is_active' => $s->is_active,
                'last_polled_at' => $s->last_polled_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $scanBatches = ScanBatch::query()
            ->with(['cabinet', 'scanSource'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(25)
            ->get()
            ->map(static fn (ScanBatch $b): array => [
                'id' => $b->id,
                'source_channel' => $b->source_channel,
                'original_filename' => $b->original_filename,
                'cabinet_name' => $b->cabinet->name ?? '',
                'page_count' => $b->page_count,
                'split_documents_count' => $b->split_documents_count,
                'status' => $b->status->value,
                'report' => $b->report_json ?? [],
                'created_at' => $b->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $localAgents = LocalAgent::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->latest()
            ->get()
            ->map(static fn (LocalAgent $a): array => [
                'id' => $a->id,
                'workstation_name' => $a->workstation_name,
                'agent_version' => $a->agent_version,
                'status' => $a->status->value,
                'capabilities' => $a->capabilities_json ?? [],
                'last_seen_at' => $a->last_seen_at?->toIso8601String(),
                'paired_ip' => $a->paired_ip,
            ])
            ->values()
            ->all();

        $cabinets = Cabinet::query()->where('tenant_id', $user->tenant_id)->orderBy('name')->get(['id', 'name'])->toArray();
        $allowedClearances = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('rank', '<=', $user->clearance_rank)
            ->orderBy('rank')
            ->get(['id', 'rank', 'code', 'name'])
            ->toArray();

        $clearedDocuments = Document::query()
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(40)
            ->get(['id', 'doc_number', 'title', 'classification_rank'])
            ->toArray();

        return Inertia::render('PrintScan/Index', [
            'printers' => $printers,
            'costCentres' => $costCentres,
            'printJobs' => $printJobs,
            'scanSources' => $scanSources,
            'scanBatches' => $scanBatches,
            'localAgents' => $localAgents,
            'cabinets' => $cabinets,
            'allowedClearances' => $allowedClearances,
            'clearedDocuments' => $clearedDocuments,
            'userRfidBadge' => $user->rfid_badge_uid,
            'canManageDevices' => $user->hasAnyRole([BetterDocRole::SystemAdmin->value, BetterDocRole::RecordsOfficer->value]),
        ]);
    }

    public function storePrinter(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyRole([BetterDocRole::SystemAdmin->value, BetterDocRole::RecordsOfficer->value]), 403);

        /** @var array{name: string, location?: string|null, cups_queue_name: string, ipps_uri: string, driver: string, max_classification_rank: int, supports_secure_release?: bool} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'cups_queue_name' => ['required', 'string', 'max:120'],
            'ipps_uri' => ['required', 'string', 'max:255'],
            'driver' => ['required', Rule::in(array_column(PrinterDriver::cases(), 'value'))],
            'max_classification_rank' => ['required', 'integer', 'min:0', 'max:4'],
            'supports_secure_release' => ['nullable', 'boolean'],
        ]);

        Printer::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => $validated['name'],
            'location' => $validated['location'] ?? null,
            'cups_queue_name' => $validated['cups_queue_name'],
            'ipps_uri' => $validated['ipps_uri'],
            'driver' => PrinterDriver::from($validated['driver']),
            'max_classification_rank' => (int) $validated['max_classification_rank'],
            'supports_secure_release' => (bool) ($validated['supports_secure_release'] ?? true),
            'supports_duplex' => true,
            'is_online' => true,
        ]);

        return back()->with('success', "LAN printer '{$validated['name']}' registered.");
    }

    public function discoverPrinters(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyRole([BetterDocRole::SystemAdmin->value, BetterDocRole::RecordsOfficer->value]), 403);

        return response()->json(['discovered' => $this->printerService->discoverLanPrinters()]);
    }

    public function storeCostCentre(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyRole([BetterDocRole::SystemAdmin->value, BetterDocRole::RecordsOfficer->value]), 403);

        /** @var array{code: string, name: string, monthly_page_quota: int} $validated */
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
            'monthly_page_quota' => ['required', 'integer', 'min:10', 'max:500000'],
        ]);

        CostCentre::query()->create([
            'tenant_id' => $user->tenant_id,
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'monthly_page_quota' => (int) $validated['monthly_page_quota'],
            'pages_used_month' => 0,
            'is_active' => true,
        ]);

        return back()->with('success', "Cost centre {$validated['code']} created.");
    }

    public function queuePrint(Request $request, Document $document): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('view', $document);

        /** @var array{printer_id: string, cost_centre_id?: string|null, copies?: int} $validated */
        $validated = $request->validate([
            'printer_id' => ['required', 'uuid'],
            'cost_centre_id' => ['nullable', 'uuid'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        /** @var Printer $printer */
        $printer = Printer::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', $validated['printer_id'])
            ->firstOrFail();

        $costCentre = ! empty($validated['cost_centre_id'])
            ? CostCentre::query()->where('tenant_id', $user->tenant_id)->where('id', $validated['cost_centre_id'])->first()
            : null;

        try {
            $res = $this->printerService->queuePrintJob(
                user: $user,
                document: $document,
                printer: $printer,
                costCentre: $costCentre,
                copies: (int) ($validated['copies'] ?? 1),
                request: $request,
            );
        } catch (PrintPolicyViolationException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            throw ValidationException::withMessages(['printer_id' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'job' => $res['job'],
                'release_pin' => $res['release_pin'],
            ], 201);
        }

        $pinNote = $res['release_pin'] !== null
            ? " One-Time Release PIN: {$res['release_pin']} (held securely until release at printer)."
            : ' Dispatched cleanly to device.';

        return back()->with([
            'success' => "Watermarked print job queued cleanly on {$printer->name}.{$pinNote}",
            'print_release_pin' => $res['release_pin'],
        ]);
    }

    public function releaseWithPin(Request $request, PrintJob $printJob): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $isOperator = $user->hasAnyRole([BetterDocRole::SystemAdmin->value, BetterDocRole::RecordsOfficer->value]);
        abort_unless($printJob->user_id === $user->id || $isOperator, 403);

        /** @var array{pin: string} $validated */
        $validated = $request->validate([
            'pin' => ['required', 'string', 'min:4', 'max:12'],
        ]);

        try {
            $released = $this->printerService->releaseWithPin($printJob, $validated['pin'], $user, $request);
        } catch (PrintPolicyViolationException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'status' => $printJob->refresh()->status->value,
                    'pin_attempts' => $printJob->refresh()->pin_attempts,
                ], 422);
            }
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json(['job' => $released]);
        }

        return back()->with('success', "Print job released ({$released->cups_job_id}).");
    }

    public function releaseWithRfid(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{rfid_badge_uid: string, printer_id: string} $validated */
        $validated = $request->validate([
            'rfid_badge_uid' => ['required', 'string', 'max:64'],
            'printer_id' => ['required', 'uuid'],
        ]);

        /** @var Printer $printer */
        $printer = Printer::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', $validated['printer_id'])
            ->firstOrFail();

        try {
            $released = $this->printerService->releaseWithRfid($user->tenant_id, $validated['rfid_badge_uid'], $printer, $user, $request);
        } catch (PrintPolicyViolationException $e) {
            throw ValidationException::withMessages(['rfid_badge_uid' => $e->getMessage()]);
        }

        return back()->with('success', count($released).' held print job(s) released via RFID badge tap.');
    }

    public function previewWatermarkedSpool(Request $request, PrintJob $printJob): HttpResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($printJob->user_id === $user->id && $printJob->tenant_id === $user->tenant_id, 403);

        $pdfBytes = $this->printerService->readWatermarkedSpoolBytes($printJob);

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="print-preview-'.substr($printJob->id, 0, 8).'.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function downloadSeparatorSheet(Request $request): HttpResponse
    {
        /** @var User $user */
        $user = $request->user();
        $docType = $request->string('doc_type', 'invoice')->toString();
        $title = $request->string('title', 'Scanned Batch Record')->toString();
        $cabinetCode = $request->string('cabinet_code', 'General')->toString();
        $classificationName = $request->string('classification_name', 'Internal')->toString();

        $pdfBytes = $this->bridge->generateSeparatorPdf($docType, $title, $cabinetCode, $classificationName);
        unset($user);

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="betterdoc-separator-'.$docType.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function uploadScanBatch(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = Tenant::query()->findOrFail($user->tenant_id);

        /** @var array{cabinet_id: string, classification_level_id: string, auto_split_barcode?: bool, auto_deskew?: bool, auto_idp?: bool, source_channel?: string|null, title_prefix?: string|null} $validated */
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:51200'],
            'cabinet_id' => ['required', 'uuid'],
            'classification_level_id' => ['required', 'uuid'],
            'auto_split_barcode' => ['nullable', 'boolean'],
            'auto_deskew' => ['nullable', 'boolean'],
            'auto_idp' => ['nullable', 'boolean'],
            'source_channel' => ['nullable', 'string', 'max:24'],
            'title_prefix' => ['nullable', 'string', 'max:180'],
        ]);

        /** @var Cabinet $cabinet */
        $cabinet = Cabinet::query()->where('tenant_id', $user->tenant_id)->where('id', $validated['cabinet_id'])->firstOrFail();
        /** @var ClearanceLevel $level */
        $level = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', $validated['classification_level_id'])
            ->where('rank', '<=', $user->clearance_rank)
            ->firstOrFail();

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $rawBytes = file_get_contents($file->getRealPath() ?: '');
        if (! is_string($rawBytes) || $rawBytes === '') {
            throw ValidationException::withMessages(['file' => 'Uploaded scan payload is empty.']);
        }

        $batch = $this->scanIngestion->ingestBatch(
            tenant: $tenant,
            actor: $user,
            cabinet: $cabinet,
            defaultLevel: $level,
            sourceChannel: $validated['source_channel'] ?? 'web_scan',
            filename: $file->getClientOriginalName(),
            rawBytes: $rawBytes,
            options: [
                'auto_split_barcode' => (bool) ($validated['auto_split_barcode'] ?? true),
                'auto_deskew' => (bool) ($validated['auto_deskew'] ?? true),
                'auto_idp' => (bool) ($validated['auto_idp'] ?? false),
                'title_prefix' => $validated['title_prefix'] ?? null,
            ],
            source: null,
            request: $request,
        );

        if ($request->wantsJson()) {
            return response()->json(['batch' => $batch], 201);
        }

        return back()->with('success', "Scan batch processed: {$batch->split_documents_count} catalogued document(s) created.");
    }

    public function storeScanSource(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyRole([BetterDocRole::SystemAdmin->value, BetterDocRole::RecordsOfficer->value]), 403);

        /** @var array{name: string, source_type: string, endpoint_or_path: string, target_cabinet_id?: string|null, default_classification_level_id?: string|null, auto_split_barcode?: bool, auto_deskew?: bool} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'source_type' => ['required', Rule::in(array_column(ScanSourceType::cases(), 'value'))],
            'endpoint_or_path' => ['required', 'string', 'max:255'],
            'target_cabinet_id' => ['nullable', 'uuid'],
            'default_classification_level_id' => ['nullable', 'uuid'],
            'auto_split_barcode' => ['nullable', 'boolean'],
            'auto_deskew' => ['nullable', 'boolean'],
        ]);

        ScanSource::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => $validated['name'],
            'source_type' => ScanSourceType::from($validated['source_type']),
            'endpoint_or_path' => $validated['endpoint_or_path'],
            'target_cabinet_id' => $validated['target_cabinet_id'] ?? null,
            'default_classification_level_id' => $validated['default_classification_level_id'] ?? null,
            'auto_ocr' => true,
            'auto_split_barcode' => (bool) ($validated['auto_split_barcode'] ?? true),
            'auto_deskew' => (bool) ($validated['auto_deskew'] ?? true),
            'is_active' => true,
        ]);

        return back()->with('success', "Scan source '{$validated['name']}' configured.");
    }

    public function pollScanSource(Request $request, ScanSource $scanSource): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($scanSource->tenant_id === $user->tenant_id, 404);

        $batches = $this->scanIngestion->pollSource($scanSource, $user, $request);
        $docsCount = 0;
        foreach ($batches as $b) {
            $docsCount += $b->split_documents_count;
        }

        return back()->with('success', "Polled {$scanSource->name}: {$docsCount} document(s) captured.");
    }

    public function initiateAgentPairing(Request $request): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{workstation_name: string} $validated */
        $validated = $request->validate([
            'workstation_name' => ['required', 'string', 'max:120'],
        ]);

        $res = $this->localAgentService->initiatePairing($user, $validated['workstation_name'], $request);

        if ($request->wantsJson()) {
            return response()->json([
                'agent' => $res['agent'],
                'pairing_code' => $res['pairing_code'],
            ], 201);
        }

        return back()->with([
            'success' => "Workstation agent created. One-Time Pairing Code: {$res['pairing_code']} (ID: {$res['agent']->id})",
            'agent_pairing_code' => $res['pairing_code'],
            'agent_pairing_id' => $res['agent']->id,
        ]);
    }

    public function revokeAgent(Request $request, LocalAgent $localAgent): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($localAgent->user_id === $user->id && $localAgent->tenant_id === $user->tenant_id, 403);

        $localAgent->update(['status' => LocalAgentStatus::Revoked]);

        return back()->with('success', "Workstation agent '{$localAgent->workstation_name}' revoked.");
    }

    public function downloadAgentScript(Request $request): HttpResponse
    {
        $serverUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $script = $this->localAgentService->buildWorkstationAgentScript($serverUrl);

        return response($script, 200, [
            'Content-Type' => 'text/x-python',
            'Content-Disposition' => 'attachment; filename="betterdoc-local-agent.py"',
        ]);
    }

    public function completeAgentPairing(Request $request): JsonResponse
    {
        /** @var array{agent_id: string, pairing_code: string, agent_version?: string|null, capabilities?: array<string, mixed>|null} $validated */
        $validated = $request->validate([
            'agent_id' => ['required', 'uuid'],
            'pairing_code' => ['required', 'string', 'max:32'],
            'agent_version' => ['nullable', 'string', 'max:32'],
            'capabilities' => ['nullable', 'array'],
        ]);

        try {
            $res = $this->localAgentService->completePairing(
                agentId: $validated['agent_id'],
                pairingCode: $validated['pairing_code'],
                agentVersion: $validated['agent_version'] ?? '1.0.0',
                capabilities: $validated['capabilities'] ?? [],
                request: $request,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'agent_id' => $res['agent']->id,
            'workstation_name' => $res['agent']->workstation_name,
            'bearer_token' => $res['bearer_token'],
        ], 200);
    }

    public function ingestAgentScan(Request $request): JsonResponse
    {
        $rawToken = $request->header('X-BetterDoc-Agent-Token') ?: ($request->bearerToken() ?? '');
        $auth = $this->localAgentService->authenticateBearerToken($rawToken);
        if ($auth === null) {
            return response()->json(['message' => 'Unauthorized local agent token.'], 401);
        }

        $user = $auth['user'];

        /** @var array{cabinet_id: string, classification_level_id: string, filename: string, file_b64: string, auto_split_barcode?: bool} $validated */
        $validated = $request->validate([
            'cabinet_id' => ['required', 'uuid'],
            'classification_level_id' => ['required', 'uuid'],
            'filename' => ['required', 'string', 'max:180'],
            'file_b64' => ['required', 'string'],
            'auto_split_barcode' => ['nullable', 'boolean'],
        ]);

        $rawBytes = base64_decode($validated['file_b64'], true);
        if (! is_string($rawBytes) || $rawBytes === '') {
            return response()->json(['message' => 'Invalid scan base64 payload.'], 422);
        }

        $batch = $this->dbContext->runAs($user, function () use ($user, $validated, $rawBytes, $request): ScanBatch {
            $tenant = Tenant::query()->findOrFail($user->tenant_id);
            /** @var Cabinet $cabinet */
            $cabinet = Cabinet::query()->where('tenant_id', $user->tenant_id)->where('id', $validated['cabinet_id'])->firstOrFail();
            /** @var ClearanceLevel $level */
            $level = ClearanceLevel::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('id', $validated['classification_level_id'])
                ->where('rank', '<=', $user->clearance_rank)
                ->firstOrFail();

            return $this->scanIngestion->ingestBatch(
                tenant: $tenant,
                actor: $user,
                cabinet: $cabinet,
                defaultLevel: $level,
                sourceChannel: ScanSourceType::TwainWia->value,
                filename: $validated['filename'],
                rawBytes: $rawBytes,
                options: [
                    'auto_split_barcode' => (bool) ($validated['auto_split_barcode'] ?? true),
                    'auto_deskew' => true,
                    'auto_idp' => true,
                ],
                source: null,
                request: $request,
            );
        });

        return response()->json(['batch' => $batch], 201);
    }
}
