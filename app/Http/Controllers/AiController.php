<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AnomalyStatus;
use App\Enums\IdpReviewStatus;
use App\Enums\SentinelRole;
use App\Exceptions\AirGapViolationException;
use App\Exceptions\WriteDownViolationException;
use App\Models\AiAnomalyAlert;
use App\Models\AiAuditLog;
use App\Models\AiChatSession;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\IdpExtraction;
use App\Models\User;
use App\Services\Ai\AiDocumentToolsService;
use App\Services\Ai\AnomalyDetectionService;
use App\Services\Ai\HybridRagService;
use App\Services\Ai\IdpExtractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class AiController extends Controller
{
    public function __construct(
        private readonly HybridRagService $rag,
        private readonly IdpExtractionService $idp,
        private readonly AiDocumentToolsService $tools,
        private readonly AnomalyDetectionService $anomalies,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $sessions = AiChatSession::query()
            ->with(['messages'])
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->latest('updated_at')
            ->limit(15)
            ->get()
            ->map(static fn (AiChatSession $s): array => [
                'id' => $s->id,
                'title' => $s->title,
                'high_water_rank' => $s->high_water_rank,
                'high_water_compartments' => $s->high_water_compartments,
                'model_name' => $s->model_name,
                'updated_at' => $s->updated_at?->toIso8601String(),
                'messages' => $s->messages->map(static fn ($m): array => [
                    'id' => $m->id,
                    'role' => $m->role->value,
                    'content' => $m->content,
                    'high_water_rank' => $m->high_water_rank,
                    'cited_chunks' => $m->cited_chunks_json ?? [],
                    'model_used' => $m->model_used,
                    'latency_ms' => $m->latency_ms,
                    'created_at' => $m->created_at?->toIso8601String(),
                ])->values()->all(),
            ])
            ->values()
            ->all();

        $idpQueue = IdpExtraction::query()
            ->with(['document', 'reviewer'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(30)
            ->get()
            ->map(static fn (IdpExtraction $ex): array => [
                'id' => $ex->id,
                'document_id' => $ex->document_id,
                'doc_number' => $ex->document->doc_number ?? 'DOC',
                'document_title' => $ex->document->title ?? '',
                'detected_doc_type' => $ex->detected_doc_type->value,
                'overall_confidence' => $ex->overall_confidence,
                'review_status' => $ex->review_status->value,
                'model_name' => $ex->model_name,
                'fields' => $ex->extracted_fields_json,
                'reviewer_name' => $ex->reviewer?->name,
                'created_at' => $ex->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $canManageAnomalies = $user->hasAnyRole([SentinelRole::Auditor->value, SentinelRole::SystemAdmin->value]);

        $alerts = $canManageAnomalies
            ? AiAnomalyAlert::query()
                ->with(['actor'])
                ->where('tenant_id', $user->tenant_id)
                ->latest()
                ->limit(30)
                ->get()
                ->map(static fn (AiAnomalyAlert $a): array => [
                    'id' => $a->id,
                    'alert_type' => $a->alert_type,
                    'severity' => $a->severity->value,
                    'status' => $a->status->value,
                    'title' => $a->title,
                    'actor_name' => $a->actor?->name,
                    'document_id' => $a->document_id,
                    'details' => $a->details_json,
                    'created_at' => $a->created_at?->toIso8601String(),
                ])
                ->values()
                ->all()
            : [];

        $aiLogsQuery = AiAuditLog::query()
            ->with(['user'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(25);

        if (! $canManageAnomalies) {
            $aiLogsQuery->where('user_id', $user->id);
        }

        $aiLogs = $aiLogsQuery
            ->get()
            ->map(static fn (AiAuditLog $l): array => [
                'id' => $l->id,
                'action' => $l->action,
                'model_used' => $l->model_used,
                'user_name' => $l->user?->name,
                'high_water_rank' => $l->high_water_rank,
                'retrieved_count' => is_array($l->retrieved_doc_ids) ? count($l->retrieved_doc_ids) : 0,
                'latency_ms' => $l->latency_ms,
                'prompt_sha256' => $l->prompt_sha256,
                'created_at' => $l->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $documents = Document::query()
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(50)
            ->get(['id', 'doc_number', 'title', 'classification_rank'])
            ->toArray();

        $cabinets = Cabinet::query()->orderBy('name')->get(['id', 'name', 'default_classification_rank'])->toArray();
        $allowedClearances = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('rank', '<=', $user->clearance_rank)
            ->orderBy('rank')
            ->get(['id', 'rank', 'code', 'name'])
            ->toArray();

        return Inertia::render('Ai/Index', [
            'sessions' => $sessions,
            'idpQueue' => $idpQueue,
            'anomalyAlerts' => $alerts,
            'aiAuditLogs' => $aiLogs,
            'clearedDocuments' => $documents,
            'cabinets' => $cabinets,
            'allowedClearances' => $allowedClearances,
            'aiStatus' => [
                'driver' => config()->string('sentinel.ai.driver', 'local_hybrid'),
                'chat_model' => config()->string('sentinel.ai.chat_model', 'qwen2.5:7b-instruct'),
                'embed_model' => config()->string('sentinel.ai.embed_model', 'bge-m3'),
                'cloud_ai_blocked' => config()->boolean('sentinel.air_gapped', true),
            ],
            'canManageAnomalies' => $canManageAnomalies,
        ]);
    }

    public function chat(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{query: string, session_id?: string|null, focus_document_id?: string|null} $validated */
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:2000'],
            'session_id' => ['nullable', 'uuid'],
            'focus_document_id' => ['nullable', 'uuid'],
        ]);

        $session = null;
        if (! empty($validated['session_id'])) {
            $session = AiChatSession::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->where('id', $validated['session_id'])
                ->firstOrFail();
        }

        try {
            $result = $this->rag->ask(
                user: $user,
                query: $validated['query'],
                existingSession: $session,
                focusDocumentId: $validated['focus_document_id'] ?? null,
                request: $request,
            );
        } catch (AirGapViolationException|RuntimeException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            throw ValidationException::withMessages(['query' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'session' => [
                    'id' => $result['session']->id,
                    'high_water_rank' => $result['session']->high_water_rank,
                    'high_water_compartments' => $result['session']->high_water_compartments,
                ],
                'message' => [
                    'id' => $result['message']->id,
                    'content' => $result['message']->content,
                    'high_water_rank' => $result['message']->high_water_rank,
                    'cited_doc_ids' => $result['message']->cited_doc_ids ?? [],
                    'citations' => $result['citations'],
                ],
            ]);
        }

        return back()->with('success', 'Local AI synthesis completed under active clearance filter.');
    }

    public function exportDraft(Request $request, AiChatSession $aiChatSession): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($aiChatSession->user_id === $user->id && $aiChatSession->tenant_id === $user->tenant_id, 404);

        /** @var array{cabinet_id: string, target_classification_level_id: string, title: string} $validated */
        $validated = $request->validate([
            'cabinet_id' => ['required', 'uuid'],
            'target_classification_level_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
        ]);

        /** @var Cabinet $cabinet */
        $cabinet = Cabinet::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', $validated['cabinet_id'])
            ->firstOrFail();
        /** @var ClearanceLevel $level */
        $level = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', $validated['target_classification_level_id'])
            ->firstOrFail();

        try {
            $doc = $this->rag->exportSessionAsDocument($user, $aiChatSession, $cabinet, $level, $validated['title'], $request);
        } catch (WriteDownViolationException $e) {
            throw ValidationException::withMessages(['target_classification_level_id' => $e->getMessage()]);
        }

        return redirect()->route('documents.show', $doc->id)
            ->with('success', "AI brief saved cleanly as {$doc->doc_number} (High-Water Rank L{$doc->classification_rank}).");
    }

    public function runIdp(Request $request, Document $document): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('view', $document);

        $extraction = $this->idp->extractFromDocument($document, $user, null, $request);

        if ($request->wantsJson()) {
            return response()->json(['extraction' => $extraction], 201);
        }

        return back()->with('success', 'IDP extracted '.count($extraction->extracted_fields_json)." field(s) ({$extraction->detected_doc_type->value}).");
    }

    public function reviewIdp(Request $request, IdpExtraction $idpExtraction): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($idpExtraction->document !== null) {
            Gate::authorize('update', $idpExtraction->document);
        }

        /** @var array{review_status: string, corrected_values?: array<string, string|null>, notes?: string|null} $validated */
        $validated = $request->validate([
            'review_status' => ['required', Rule::in(array_column(IdpReviewStatus::cases(), 'value'))],
            'corrected_values' => ['nullable', 'array'],
            'corrected_values.*' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $corrected = [];
        foreach ($validated['corrected_values'] ?? [] as $k => $v) {
            if ($v !== null) {
                $corrected[$k] = $v;
            }
        }

        $this->idp->reviewExtraction(
            extraction: $idpExtraction,
            reviewer: $user,
            status: IdpReviewStatus::from($validated['review_status']),
            correctedValues: $corrected,
            notes: $validated['notes'] ?? null,
            request: $request,
        );

        return back()->with('success', 'IDP extraction review saved and promoted to verified record metadata.');
    }

    public function documentAction(Request $request, Document $document): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('view', $document);

        /** @var array{action: string, target_lang?: string|null, instructions?: string|null, apply_tags?: bool, from_version_id?: string|null, to_version_id?: string|null} $validated */
        $validated = $request->validate([
            'action' => ['required', Rule::in(['summarize', 'clauses', 'draft_reply', 'auto_tag', 'duplicates', 'translate', 'compare_versions'])],
            'target_lang' => ['nullable', Rule::in(['en', 'sn', 'nd'])],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'apply_tags' => ['nullable', 'boolean'],
            'from_version_id' => ['nullable', 'uuid'],
            'to_version_id' => ['nullable', 'uuid'],
        ]);

        return match ($validated['action']) {
            'summarize' => response()->json($this->tools->summarize($user, $document, $request)),
            'clauses' => response()->json($this->tools->extractClauses($user, $document, $request)),
            'draft_reply' => response()->json($this->tools->draftReply($user, $document, $validated['instructions'] ?? '', $request)),
            'auto_tag' => response()->json($this->tools->suggestTags($user, $document, (bool) ($validated['apply_tags'] ?? false), $request)),
            'duplicates' => response()->json(['duplicates' => $this->tools->detectDuplicates($user, $document, $request)]),
            'translate' => response()->json($this->tools->translate(
                $user,
                trim(($document->title ?? '')."\n".($document->summary ?? '')."\n".mb_substr((string) ($document->ocr_text ?? ''), 0, 1200)),
                $validated['target_lang'] ?? 'sn',
                $document,
                $request,
            )),
            'compare_versions' => $this->handleCompareVersions($user, $document, $validated['from_version_id'] ?? '', $validated['to_version_id'] ?? '', $request),
            default => abort(422, 'Unsupported AI action requested.'),
        };
    }

    public function scanAnomalies(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyRole([SentinelRole::Auditor->value, SentinelRole::SystemAdmin->value]), 403);
        $created = $this->anomalies->scanTenant($user->tenant_id, $user);

        return back()->with('success', 'Security anomaly scan finished: '.count($created).' new alert(s) raised.');
    }

    public function updateAnomalyStatus(Request $request, AiAnomalyAlert $aiAnomalyAlert): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyRole([SentinelRole::Auditor->value, SentinelRole::SystemAdmin->value]) && $aiAnomalyAlert->tenant_id === $user->tenant_id, 403);

        /** @var array{status: string} $validated */
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_column(AnomalyStatus::cases(), 'value'))],
        ]);

        $aiAnomalyAlert->update([
            'status' => AnomalyStatus::from($validated['status']),
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ]);

        return back()->with('success', 'Anomaly alert status updated.');
    }

    private function handleCompareVersions(
        User $user,
        Document $document,
        string $fromId,
        string $toId,
        Request $request,
    ): JsonResponse {
        $versions = DocumentVersion::query()
            ->where('document_id', $document->id)
            ->orderByDesc('version_major')
            ->orderByDesc('version_minor')
            ->get();

        $to = $toId !== '' ? $versions->firstWhere('id', $toId) : $versions->first();
        $from = $fromId !== '' ? $versions->firstWhere('id', $fromId) : $versions->skip(1)->first();

        if (! $to instanceof DocumentVersion || ! $from instanceof DocumentVersion) {
            return response()->json(['summary' => 'At least two document versions are required to compare semantically.', 'added_lines' => [], 'removed_lines' => []]);
        }

        return response()->json($this->tools->compareVersions($user, $document, $from, $to, $request));
    }
}
