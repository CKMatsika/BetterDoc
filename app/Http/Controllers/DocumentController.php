<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\PrincipalType;
use App\Enums\TwoPersonActionType;
use App\Exceptions\InsufficientClearanceException;
use App\Exceptions\WriteDownViolationException;
use App\Http\Requests\ReclassifyDocumentRequest;
use App\Http\Requests\ShareDocumentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Compartment;
use App\Models\CostCentre;
use App\Models\Document;
use App\Models\DocumentAclGrant;
use App\Models\DocumentAnnotation;
use App\Models\DocumentComment;
use App\Models\DocumentVersion;
use App\Models\Folder;
use App\Models\Printer;
use App\Models\RetentionSchedule;
use App\Models\SavedSmartView;
use App\Models\SignatureEnvelope;
use App\Models\SignatureRequest;
use App\Models\SignatureVerification;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserCertificate;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Crypto\EnvelopeEncryptionService;
use App\Services\Dms\DocumentVersioningService;
use App\Services\Search\DocumentSearchService;
use App\Services\Security\ClearancePolicyEngine;
use App\Services\Security\TwoPersonService;
use App\Services\Signing\PadesSignerService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DocumentController extends Controller
{
    public function __construct(
        private readonly ClearancePolicyEngine $policyEngine,
        private readonly TwoPersonService $twoPerson,
        private readonly ImmutableAuditLogger $audit,
        private readonly DocumentSearchService $search,
        private readonly EnvelopeEncryptionService $crypto,
        private readonly DocumentVersioningService $versioning,
        private readonly PadesSignerService $pades,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $cabinets = Cabinet::query()
            ->orderBy('name')
            ->get()
            ->map(static fn (Cabinet $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'default_classification_rank' => $c->default_classification_rank,
                'document_count' => Document::query()->where('cabinet_id', $c->id)->count(),
            ])
            ->values()
            ->all();

        $selectedCabinetId = $request->query('cabinet_id');
        $selectedTagId = $request->query('tag_id');
        $searchQuery = trim((string) $request->query('q', ''));

        $searchResult = $this->search->search($user, $searchQuery, [
            'cabinet_id' => is_string($selectedCabinetId) && $selectedCabinetId !== '' ? $selectedCabinetId : null,
            'tag_id' => is_string($selectedTagId) && $selectedTagId !== '' ? $selectedTagId : null,
            'limit' => 50,
        ]);

        $allowedClearances = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('rank', '<=', $user->clearance_rank)
            ->orderBy('rank')
            ->get(['id', 'rank', 'code', 'name', 'color_hex'])
            ->toArray();

        $userCompartments = $user->compartments()->get(['compartments.id', 'compartments.code', 'compartments.name'])->toArray();
        $tags = Tag::query()->where('tenant_id', $user->tenant_id)->orderBy('name')->get(['id', 'name', 'color_hex'])->toArray();
        $smartViews = SavedSmartView::query()->orderBy('name')->get(['id', 'name', 'filter_json', 'is_shared'])->toArray();

        return Inertia::render('Documents/Index', [
            'cabinets' => $cabinets,
            'documents' => $searchResult['hits'],
            'searchMeta' => [
                'total' => $searchResult['total'],
                'facets' => $searchResult['facets'],
                'elapsed_ms' => $searchResult['elapsed_ms'],
                'engine' => $searchResult['engine'],
            ],
            'allowedClearances' => $allowedClearances,
            'userCompartments' => $userCompartments,
            'tags' => $tags,
            'smartViews' => $smartViews,
            'filters' => [
                'cabinet_id' => is_string($selectedCabinetId) ? $selectedCabinetId : null,
                'tag_id' => is_string($selectedTagId) ? $selectedTagId : null,
                'q' => $searchQuery,
            ],
        ]);
    }

    public function autocomplete(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $prefix = trim((string) $request->query('q', ''));

        return response()->json([
            'suggestions' => $this->search->autocomplete($user, $prefix, 8),
        ]);
    }

    public function store(StoreDocumentRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $classificationLevelId = $request->string('classification_level_id')->toString();
        $cabinetId = $request->string('cabinet_id')->toString();
        $folderId = $request->string('folder_id')->toString();
        $docNumber = $request->string('doc_number')->toString();
        $title = $request->string('title')->toString();
        $summaryInput = $request->input('summary');
        $summary = is_string($summaryInput) ? $summaryInput : null;
        $unitWideReadable = $request->boolean('unit_wide_readable', true);

        /** @var ClearanceLevel $level */
        $level = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', $classificationLevelId)
            ->firstOrFail();

        if ($level->rank > $user->clearance_rank) {
            throw ValidationException::withMessages([
                'classification_level_id' => 'Cannot classify a document above your own clearance rank.',
            ]);
        }

        /** @var list<string> $compartmentIds */
        $compartmentIds = [];
        $rawCompIds = $request->input('compartment_ids', []);
        if (is_array($rawCompIds)) {
            foreach ($rawCompIds as $cid) {
                if (is_string($cid) && $cid !== '') {
                    $compartmentIds[] = $cid;
                }
            }
        }

        if ($compartmentIds !== []) {
            $held = $user->activeCompartmentIds();
            if (array_diff($compartmentIds, $held) !== []) {
                throw ValidationException::withMessages([
                    'compartment_ids' => 'Cannot assign a compartment you do not personally hold.',
                ]);
            }
        }

        /** @var Cabinet $cabinet */
        $cabinet = Cabinet::query()->where('id', $cabinetId)->firstOrFail();

        if ($folderId !== '') {
            Folder::query()->where('id', $folderId)->where('cabinet_id', $cabinet->id)->firstOrFail();
        }

        $doc = DB::transaction(function () use ($user, $folderId, $docNumber, $title, $summary, $unitWideReadable, $level, $cabinet, $compartmentIds, $request): Document {
            /** @var Document $created */
            $created = Document::query()->create([
                'tenant_id' => $user->tenant_id,
                'cabinet_id' => $cabinet->id,
                'folder_id' => $folderId !== '' ? $folderId : null,
                'org_unit_id' => $user->org_unit_id,
                'owner_id' => $user->id,
                'classification_level_id' => $level->id,
                'classification_rank' => $level->rank,
                'doc_number' => $docNumber,
                'title' => $title,
                'summary' => $summary,
                'status' => DocumentStatus::Active,
                'unit_wide_readable' => $unitWideReadable,
            ]);

            if ($compartmentIds !== []) {
                $validIds = Compartment::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->whereIn('id', $compartmentIds)
                    ->pluck('id')
                    ->all();
                $created->compartments()->sync($validIds);
            }

            $this->audit->log(
                tenantId: $user->tenant_id,
                eventType: 'document.created',
                resourceType: 'document',
                resourceId: $created->id,
                actor: $user,
                classificationRank: $created->classification_rank,
                after: ['doc_number' => $created->doc_number, 'title' => $created->title, 'rank' => $created->classification_rank],
                request: $request,
            );

            return $created;
        });

        $this->search->indexDocument($doc);

        return redirect()->route('documents.show', $doc->id)->with('success', 'Document recorded securely.');
    }

    public function show(Request $request, Document $document): Response
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('view', $document);

        $decision = $this->policyEngine->authorize($user, 'view', $document, [
            'ip' => $request->ip(),
            'bypass_abac' => true,
        ]);

        $document->load([
            'classificationLevel',
            'orgUnit',
            'owner',
            'cabinet',
            'compartments',
            'aclGrants',
            'versions.creator',
            'currentVersion',
            'lock.holder',
            'tags',
            'correspondenceEntry',
            'signatureEnvelopes.requests.signer',
            'signatureEnvelopes.creator',
            'comments.author',
            'comments.replies.author',
            'annotations.creator',
            'retentionSchedule',
            'workflowInstances.definition',
            'workflowInstances.tasks.assignee',
        ]);

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: $decision->isBreakGlass ? 'document.viewed_break_glass' : 'document.viewed',
            resourceType: 'document',
            resourceId: $document->id,
            actor: $user,
            classificationRank: $document->classification_rank,
            after: ['doc_number' => $document->doc_number, 'break_glass' => $decision->isBreakGlass],
            request: $request,
        );

        $shareableUsers = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', '!=', $user->id)
            ->orderByDesc('clearance_rank')
            ->get(['id', 'name', 'email', 'rank_title', 'clearance_rank'])
            ->toArray();

        $allLevels = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('rank')
            ->get(['id', 'rank', 'code', 'name', 'color_hex', 'two_person_required'])
            ->toArray();

        $tenantTags = Tag::query()->where('tenant_id', $user->tenant_id)->orderBy('name')->get(['id', 'name', 'color_hex'])->toArray();

        $canDownload = $this->policyEngine->authorize($user, 'download', $document, ['bypass_abac' => true])->allowed
            && ($document->classificationLevel?->watermark_mode !== 'dynamic_no_download');

        // Live PAdES / CRL / Tamper Verification on every open when signed or PKI-verified
        $sigVerificationPayload = [
            'overall_status' => 'unsigned',
            'signatures_count' => 0,
            'verified_at' => null,
            'details' => ['signatures' => []],
        ];
        if ($document->currentVersion instanceof DocumentVersion && $document->currentVersion->mime_type === 'application/pdf') {
            $existingVer = SignatureVerification::query()
                ->where('document_version_id', $document->currentVersion->id)
                ->first();

            if ($document->signatureEnvelopes->isNotEmpty() || ($existingVer !== null && $existingVer->signatures_count > 0)) {
                $liveVer = $this->pades->verifyDocumentVersion($document->currentVersion);
                $sigVerificationPayload = [
                    'overall_status' => $liveVer->overall_status->value,
                    'signatures_count' => $liveVer->signatures_count,
                    'verified_at' => $liveVer->verified_at->toIso8601String(),
                    'details' => $liveVer->details_json,
                ];
            }
        }

        $qrWriter = new Writer(new ImageRenderer(new RendererStyle(128), new SvgImageBackEnd));
        $baseUrl = rtrim(config()->string('app.url', 'https://betterdoc.lan'), '/');

        $envelopesPayload = $document->signatureEnvelopes->map(static function (SignatureEnvelope $env) use ($user, $qrWriter, $baseUrl): array {
            $verifyUrl = "{$baseUrl}/verify/{$env->verification_token}";

            return [
                'id' => $env->id,
                'title' => $env->title,
                'mode' => $env->mode->value,
                'status' => $env->status->value,
                'verification_token' => $env->verification_token,
                'verify_url' => $verifyUrl,
                'qr_svg' => $qrWriter->writeString($verifyUrl),
                'creator_name' => $env->creator?->name,
                'has_certificate_pdf' => $env->certificate_storage_key !== null,
                'completed_at' => $env->completed_at?->toIso8601String(),
                'requests' => $env->requests->map(static fn (SignatureRequest $r): array => [
                    'id' => $r->id,
                    'signer_id' => $r->signer_id,
                    'signer_name' => trim(($r->signer?->rank_title ? $r->signer->rank_title.' ' : '').($r->signer->name ?? 'Officer')),
                    'sign_order' => $r->sign_order,
                    'tier' => $r->tier->value,
                    'status' => $r->status->value,
                    'field_name' => $r->field_name,
                    'field_coords' => $r->field_coords_json,
                    'cert_serial' => $r->cert_serial,
                    'tsa_token_serial' => $r->tsa_token_serial,
                    'signed_at' => $r->signed_at?->toIso8601String(),
                    'decline_reason' => $r->decline_reason,
                    'reminder_count' => $r->reminder_count,
                    'is_my_turn' => $r->signer_id === $user->id && $r->status->value === 'ready',
                ])->values()->all(),
            ];
        })->values()->all();

        $myActiveCerts = UserCertificate::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->latest()
            ->get(['id', 'serial_number', 'custody_mode', 'status', 'valid_to'])
            ->map(static fn (UserCertificate $c): array => [
                'id' => $c->id,
                'serial_number' => $c->serial_number,
                'custody_mode' => $c->custody_mode->value,
                'status' => $c->status->value,
                'valid_to' => $c->valid_to->toIso8601String(),
            ])
            ->values()
            ->all();

        $workflowDefinitions = WorkflowDefinition::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'key', 'name', 'sla_hours'])
            ->toArray();

        $retentionSchedules = RetentionSchedule::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'retention_days', 'disposition_action'])
            ->toArray();

        return Inertia::render('Documents/Show', [
            'document' => [
                'id' => $document->id,
                'doc_number' => $document->doc_number,
                'title' => $document->title,
                'summary' => $document->summary,
                'status' => $document->status->value,
                'classification_rank' => $document->classification_rank,
                'current_version_id' => $document->current_version_id,
                'classification' => [
                    'id' => $document->classificationLevel?->id,
                    'code' => $document->classificationLevel?->code,
                    'name' => $document->classificationLevel?->name,
                    'color_hex' => $document->classificationLevel?->color_hex,
                    'watermark_mode' => $document->classificationLevel?->watermark_mode,
                    'two_person_required' => $document->classificationLevel?->two_person_required,
                ],
                'cabinet_name' => $document->cabinet?->name,
                'org_unit_name' => $document->orgUnit?->name,
                'owner_name' => $document->owner?->name,
                'compartments' => $document->compartments->pluck('code')->values()->all(),
                'tags' => $document->tags->map(static fn (Tag $t): array => ['id' => $t->id, 'name' => $t->name, 'color_hex' => $t->color_hex])->values()->all(),
                'legal_hold_active' => $document->legal_hold_active,
                'legal_hold_reason' => $document->legal_hold_reason,
                'is_worm' => $document->is_worm,
                'worm_locked_at' => $document->worm_locked_at?->toIso8601String(),
                'worm_retention_until' => $document->worm_retention_until?->toIso8601String(),
                'retention_schedule' => $document->retentionSchedule ? [
                    'id' => $document->retentionSchedule->id,
                    'code' => $document->retentionSchedule->code,
                    'name' => $document->retentionSchedule->name,
                    'expires_at' => $document->retention_expires_at?->toIso8601String(),
                ] : null,
                'lock' => $document->lock && $document->lock->isActive() ? [
                    'locked_by_id' => $document->lock->locked_by,
                    'locked_by_name' => $document->lock->holder?->name,
                    'checkout_note' => $document->lock->checkout_note,
                    'expires_at' => $document->lock->expires_at->toIso8601String(),
                    'is_mine' => $document->lock->locked_by === $user->id,
                ] : null,
                'correspondence' => $document->correspondenceEntry ? [
                    'registry_ref_no' => $document->correspondenceEntry->registry_ref_no,
                    'direction' => $document->correspondenceEntry->direction->value,
                    'status' => $document->correspondenceEntry->status->value,
                ] : null,
                'versions' => $document->versions->map(static fn (DocumentVersion $v): array => [
                    'id' => $v->id,
                    'label' => $v->label(),
                    'version_major' => $v->version_major,
                    'version_minor' => $v->version_minor,
                    'is_current' => $v->is_current,
                    'original_filename' => $v->original_filename,
                    'mime_type' => $v->mime_type,
                    'file_size_bytes' => $v->file_size_bytes,
                    'sha256_hash' => $v->sha256_hash,
                    'av_status' => $v->av_status->value,
                    'cdr_status' => $v->cdr_status->value,
                    'ocr_status' => $v->ocr_status->value,
                    'page_count' => $v->page_count,
                    'version_note' => $v->version_note,
                    'creator_name' => $v->creator?->name,
                    'created_at' => $v->created_at?->toIso8601String(),
                ])->values()->all(),
                'grants' => $document->aclGrants->map(static fn (DocumentAclGrant $g): array => [
                    'id' => $g->id,
                    'principal_type' => $g->principal_type->value,
                    'principal_id' => $g->principal_id,
                    'perm_view' => $g->perm_view,
                    'perm_download' => $g->perm_download,
                    'perm_print' => $g->perm_print,
                    'perm_sign' => $g->perm_sign,
                    'perm_edit' => $g->perm_edit,
                    'expires_at' => $g->expires_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'watermarkOverlay' => [
                'viewer' => ($user->rank_title ? $user->rank_title.' ' : '').$user->name,
                'service_number' => $user->service_number ?? $user->email,
                'classification' => strtoupper($document->classificationLevel->name ?? 'CLASSIFIED'),
                'ip' => (string) $request->ip(),
                'timestamp' => now('UTC')->format('Y-m-d H:i:s \U\T\C'),
                'break_glass' => $decision->isBreakGlass,
            ],
            'isBreakGlass' => $decision->isBreakGlass,
            'shareableUsers' => $shareableUsers,
            'clearanceLevels' => $allLevels,
            'availableTags' => $tenantTags,
            'signatureVerification' => $sigVerificationPayload,
            'envelopes' => $envelopesPayload,
            'myCertificates' => $myActiveCerts,
            'comments' => $document->comments->map(static fn (DocumentComment $c): array => [
                'id' => $c->id,
                'body' => $c->body,
                'author_name' => trim(($c->author?->rank_title ? $c->author->rank_title.' ' : '').($c->author->name ?? 'User')),
                'resolved_at' => $c->resolved_at?->toIso8601String(),
                'suppressed_mentions_count' => is_array($c->suppressed_mention_ids_json) ? count($c->suppressed_mention_ids_json) : 0,
                'created_at' => $c->created_at?->toIso8601String(),
                'replies' => $c->replies->map(static fn (DocumentComment $r): array => [
                    'id' => $r->id,
                    'body' => $r->body,
                    'author_name' => trim(($r->author?->rank_title ? $r->author->rank_title.' ' : '').($r->author->name ?? 'User')),
                    'created_at' => $r->created_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'annotations' => $document->annotations->map(static fn (DocumentAnnotation $a): array => [
                'id' => $a->id,
                'version_id' => $a->version_id,
                'page_number' => $a->page_number,
                'kind' => $a->kind->value,
                'coords' => $a->coords_json,
                'content' => $a->content,
                'redacted_in_version_id' => $a->redacted_in_version_id,
                'creator_name' => $a->creator?->name,
            ])->values()->all(),
            'workflowInstances' => $document->workflowInstances->map(static fn (WorkflowInstance $wi): array => [
                'id' => $wi->id,
                'definition_name' => $wi->definition?->name,
                'status' => $wi->status->value,
                'sla_due_at' => $wi->sla_due_at?->toIso8601String(),
                'tasks' => $wi->tasks->map(static fn (WorkflowTask $wt): array => [
                    'id' => $wt->id,
                    'node_label' => $wt->node_label,
                    'assignee_name' => $wt->assignee?->name,
                    'status' => $wt->status->value,
                    'decision' => $wt->decision,
                    'approval_hash' => $wt->approval_hash,
                ])->values()->all(),
            ])->values()->all(),
            'workflowDefinitions' => $workflowDefinitions,
            'retentionSchedules' => $retentionSchedules,
            'printers' => Printer::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('is_online', true)
                ->orderBy('max_classification_rank')
                ->get(['id', 'name', 'location', 'max_classification_rank', 'supports_secure_release'])
                ->toArray(),
            'costCentres' => CostCentre::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'monthly_page_quota', 'pages_used_month'])
                ->toArray(),
            'capabilities' => [
                'can_edit' => $this->policyEngine->authorize($user, 'update', $document, ['bypass_abac' => true])->allowed,
                'can_share' => $this->policyEngine->authorize($user, 'share', $document, ['bypass_abac' => true])->allowed,
                'can_download' => $canDownload,
                'can_print' => $this->policyEngine->authorize($user, 'print', $document, ['bypass_abac' => true])->allowed,
                'can_sign' => $this->policyEngine->authorize($user, 'sign', $document, ['bypass_abac' => true])->allowed,
                'can_delete' => $this->policyEngine->authorize($user, 'delete', $document, ['bypass_abac' => true])->allowed
                    || $this->policyEngine->authorize($user, 'delete', $document, ['bypass_abac' => true])->requiresTwoPerson,
            ],
        ]);
    }

    public function streamVersion(Request $request, Document $document, DocumentVersion $version): SymfonyResponse
    {
        abort_unless($version->document_id === $document->id, 404);
        Gate::authorize('view', $document);

        $handle = $this->crypto->decryptVersionToTempHandle($version);
        $stat = fstat($handle);
        $size = is_array($stat) ? (int) $stat['size'] : 0;

        $rangeHeader = $request->header('Range');
        $start = 0;
        $end = max(0, $size - 1);
        $status = 200;

        if (is_string($rangeHeader) && preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches) === 1) {
            $start = (int) $matches[1];
            $end = $matches[2] !== '' ? min($end, (int) $matches[2]) : $end;
            if ($start > $end || $start >= $size) {
                fclose($handle);

                return response('', 416, ['Content-Range' => "bytes */{$size}"]);
            }
            $status = 206;
        }

        $length = $size > 0 ? ($end - $start + 1) : 0;
        if ($start > 0) {
            fseek($handle, $start);
        }

        $headers = [
            'Content-Type' => $version->mime_type,
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ];
        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return response()->stream(function () use ($handle, $length): void {
            $remaining = $length;
            while ($remaining > 0 && ! feof($handle)) {
                $chunk = fread($handle, min(65536, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($handle);
        }, $status, $headers);
    }

    public function downloadVersion(Request $request, Document $document, DocumentVersion $version): SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($version->document_id === $document->id, 404);
        Gate::authorize('download', $document);

        if ($document->classificationLevel?->watermark_mode === 'dynamic_no_download') {
            abort(403, 'Raw download is forbidden for Top Secret classification.');
        }

        $handle = $this->crypto->decryptVersionToTempHandle($version);
        $stat = fstat($handle);
        $size = is_array($stat) ? (int) $stat['size'] : 0;

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: 'document.downloaded',
            resourceType: 'document_version',
            resourceId: $version->id,
            actor: $user,
            classificationRank: $document->classification_rank,
            after: ['document_id' => $document->id, 'version' => $version->label()],
            request: $request,
        );

        return response()->streamDownload(function () use ($handle): void {
            while (! feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
            }
            fclose($handle);
        }, $version->original_filename, [
            'Content-Type' => $version->mime_type,
            'Content-Length' => (string) $size,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function checkout(Request $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('update', $document);

        $noteVal = $request->input('note');
        try {
            $this->versioning->checkout(
                $document,
                $user,
                is_string($noteVal) && $noteVal !== '' ? $noteVal : null,
                $request,
            );
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['checkout' => $e->getMessage()]);
        }

        return back()->with('success', 'Document checked out exclusively.');
    }

    public function checkin(Request $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('update', $document);

        try {
            $this->versioning->checkin($document, $user, $request);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['checkout' => $e->getMessage()]);
        }

        return back()->with('success', 'Document check-out lock released.');
    }

    public function compareVersions(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('view', $document);

        $request->validate([
            'from_version_id' => ['required', 'uuid'],
            'to_version_id' => ['required', 'uuid'],
        ]);

        /** @var DocumentVersion $from */
        $from = DocumentVersion::query()
            ->where('document_id', $document->id)
            ->where('id', $request->string('from_version_id')->toString())
            ->firstOrFail();

        /** @var DocumentVersion $to */
        $to = DocumentVersion::query()
            ->where('document_id', $document->id)
            ->where('id', $request->string('to_version_id')->toString())
            ->firstOrFail();

        return response()->json([
            'from' => $from->label(),
            'to' => $to->label(),
            'diff' => $this->versioning->diffVersions($from, $to),
        ]);
    }

    public function restoreVersion(Request $request, Document $document, DocumentVersion $version): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($version->document_id === $document->id, 404);
        Gate::authorize('update', $document);

        $restored = $this->versioning->restoreVersion($document, $version, $user, $request);
        $this->search->indexDocument($document->refresh());

        return back()->with('success', "Restored {$version->label()} as new {$restored->label()}.");
    }

    public function attachTag(Request $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('update', $document);

        $request->validate([
            'name' => ['required', 'string', 'max:64'],
        ]);

        $tag = Tag::query()->firstOrCreate(
            ['tenant_id' => $user->tenant_id, 'name' => trim($request->string('name')->toString())],
            ['color_hex' => '#0284C7']
        );

        $document->tags()->syncWithoutDetaching([$tag->id]);
        $this->search->indexDocument($document->refresh());

        return back()->with('success', "Tag '{$tag->name}' attached.");
    }

    public function share(ShareDocumentRequest $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('share', $document);

        $principalType = PrincipalType::from($request->string('principal_type')->toString());
        $principalId = $request->string('principal_id')->toString();

        $perms = [
            'perm_view' => $request->boolean('perm_view', true),
            'perm_download' => $request->boolean('perm_download'),
            'perm_print' => $request->boolean('perm_print'),
            'perm_sign' => $request->boolean('perm_sign'),
            'perm_edit' => $request->boolean('perm_edit'),
            'perm_share' => $request->boolean('perm_share'),
        ];

        try {
            $this->policyEngine->assertCanShareToRecipient(
                grantor: $user,
                document: $document,
                principalType: $principalType,
                principalId: $principalId,
                requestedPerms: $perms,
                request: $request,
            );
        } catch (InsufficientClearanceException $e) {
            throw ValidationException::withMessages([
                'principal_id' => $e->getMessage(),
            ]);
        }

        $expiresAtInput = $request->input('expires_at');

        $grant = DocumentAclGrant::query()->create([
            'tenant_id' => $user->tenant_id,
            'document_id' => $document->id,
            'principal_type' => $principalType,
            'principal_id' => $principalId,
            ...$perms,
            'granted_by' => $user->id,
            'expires_at' => is_string($expiresAtInput) && $expiresAtInput !== '' ? $expiresAtInput : null,
        ]);

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: 'document.shared',
            resourceType: 'document',
            resourceId: $document->id,
            actor: $user,
            classificationRank: $document->classification_rank,
            after: ['grant_id' => $grant->id, 'principal_type' => $principalType->value, 'principal_id' => $principalId],
            request: $request,
        );

        $this->search->indexDocument($document->refresh());

        return back()->with('success', 'Access grant created.');
    }

    public function reclassify(ReclassifyDocumentRequest $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('update', $document);

        $targetId = $request->string('target_classification_level_id')->toString();
        $reason = $request->string('reason')->toString();

        /** @var ClearanceLevel $target */
        $target = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', $targetId)
            ->firstOrFail();

        if ($target->rank === $document->classification_rank) {
            return back();
        }

        if ($target->rank < $document->classification_rank) {
            $currentLevel = $document->classificationLevel;
            $requiresTwoPerson = $currentLevel->two_person_required ?? false;

            if ($requiresTwoPerson) {
                $this->twoPerson->initiate(
                    requester: $user,
                    actionType: TwoPersonActionType::Declassify,
                    targetType: 'document',
                    targetId: $document->id,
                    targetClassificationRank: $document->classification_rank,
                    payload: ['target_classification_level_id' => $target->id, 'target_rank' => $target->rank],
                    reason: $reason,
                    request: $request,
                );

                return back()->with('success', 'Two-person declassification request submitted for independent co-approval.');
            }

            try {
                $this->policyEngine->assertWriteDownAllowed($user, $document->classification_rank, $target->rank);
            } catch (WriteDownViolationException $e) {
                $this->twoPerson->initiate(
                    requester: $user,
                    actionType: TwoPersonActionType::Declassify,
                    targetType: 'document',
                    targetId: $document->id,
                    targetClassificationRank: $document->classification_rank,
                    payload: ['target_classification_level_id' => $target->id, 'target_rank' => $target->rank],
                    reason: $reason,
                    request: $request,
                );

                return back()->with('success', $e->getMessage().' Request queued for two-person co-approval.');
            }
        }

        if ($target->rank > $user->clearance_rank) {
            throw ValidationException::withMessages([
                'target_classification_level_id' => 'Cannot reclassify document above your own clearance rank.',
            ]);
        }

        $before = ['classification_rank' => $document->classification_rank];
        $document->update([
            'classification_level_id' => $target->id,
            'classification_rank' => $target->rank,
        ]);

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: 'document.reclassified',
            resourceType: 'document',
            resourceId: $document->id,
            actor: $user,
            classificationRank: max($before['classification_rank'], $target->rank),
            before: $before,
            after: ['classification_rank' => $target->rank, 'reason' => $reason],
            request: $request,
        );

        // Synchronous search index visibility update so upgraded documents immediately leave lower searches
        $this->search->indexDocument($document->refresh());

        return back()->with('success', "Document classification updated to {$target->name}.");
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $decision = $this->policyEngine->authorize($user, 'delete', $document, ['bypass_abac' => true]);

        if (! $decision->allowed) {
            if ($decision->requiresTwoPerson) {
                $reasonInput = $request->input('reason');
                $reason = is_string($reasonInput) ? trim($reasonInput) : '';
                $this->twoPerson->initiate(
                    requester: $user,
                    actionType: TwoPersonActionType::DeleteSensitive,
                    targetType: 'document',
                    targetId: $document->id,
                    targetClassificationRank: $document->classification_rank,
                    payload: ['doc_number' => $document->doc_number],
                    reason: mb_strlen($reason) >= 10 ? $reason : 'Sensitive record deletion requested via vault.',
                    request: $request,
                );

                return back()->with('success', 'Deletion requires Two-Person Rule. Request submitted for co-approval.');
            }

            if ($decision->hideExistence) {
                abort(404);
            }

            abort(403, $decision->reason);
        }

        $document->delete();

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: 'document.deleted',
            resourceType: 'document',
            resourceId: $document->id,
            actor: $user,
            classificationRank: $document->classification_rank,
            before: ['doc_number' => $document->doc_number, 'title' => $document->title],
            request: $request,
        );

        return redirect()->route('documents.index')->with('success', 'Document moved to archive trash.');
    }
}
