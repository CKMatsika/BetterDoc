<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BetterDocRole;
use App\Enums\DispositionAction;
use App\Enums\RetentionTrigger;
use App\Enums\TwoPersonActionType;
use App\Models\ClearanceLevel;
use App\Models\DispositionReview;
use App\Models\Document;
use App\Models\RetentionSchedule;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Dms\GovernanceService;
use App\Services\Security\ClearanceRevocationService;
use App\Services\Security\TwoPersonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class GovernanceController extends Controller
{
    public function __construct(
        private readonly GovernanceService $governance,
        private readonly TwoPersonService $twoPerson,
        private readonly ClearanceRevocationService $revocation,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $schedules = RetentionSchedule::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('code')
            ->get()
            ->map(static fn (RetentionSchedule $s): array => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'trigger_event' => $s->trigger_event->value,
                'retention_days' => $s->retention_days,
                'disposition_action' => $s->disposition_action->value,
                'description' => $s->description,
            ])
            ->values()
            ->all();

        $governedDocs = Document::query()
            ->with(['retentionSchedule', 'classificationLevel'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(40)
            ->get()
            ->map(static fn (Document $d): array => [
                'id' => $d->id,
                'doc_number' => $d->doc_number,
                'title' => $d->title,
                'classification_rank' => $d->classification_rank,
                'classification_name' => $d->classificationLevel?->name,
                'retention_schedule_code' => $d->retentionSchedule?->code,
                'retention_expires_at' => $d->retention_expires_at?->toIso8601String(),
                'legal_hold_active' => $d->legal_hold_active,
                'legal_hold_reason' => $d->legal_hold_reason,
                'is_worm' => $d->is_worm,
                'worm_locked_at' => $d->worm_locked_at?->toIso8601String(),
                'worm_retention_until' => $d->worm_retention_until?->toIso8601String(),
            ])
            ->values()
            ->all();

        $reviews = DispositionReview::query()
            ->with(['document', 'retentionSchedule', 'initiator'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(30)
            ->get()
            ->map(static fn (DispositionReview $r): array => [
                'id' => $r->id,
                'doc_number' => $r->document?->doc_number,
                'document_title' => $r->document?->title,
                'schedule_code' => $r->retentionSchedule?->code,
                'proposed_action' => $r->proposed_action->value,
                'status' => $r->status,
                'initiator_name' => $r->initiator?->name,
                'two_person_request_id' => $r->two_person_request_id,
                'notes' => $r->notes,
            ])
            ->values()
            ->all();

        $users = $user->hasRole(BetterDocRole::SystemAdmin->value)
            ? User::query()
                ->with(['clearanceLevel', 'compartments'])
                ->where('tenant_id', $user->tenant_id)
                ->orderByDesc('clearance_rank')
                ->get()
                ->map(static fn (User $u): array => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'rank_title' => $u->rank_title,
                    'clearance_rank' => $u->clearance_rank,
                    'clearance_name' => $u->clearanceLevel?->name,
                    'compartments' => $u->compartments->pluck('code')->values()->all(),
                ])
                ->values()
                ->all()
            : [];

        $clearanceLevels = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('rank')
            ->get(['id', 'rank', 'code', 'name'])
            ->toArray();

        return Inertia::render('Governance/Index', [
            'schedules' => $schedules,
            'governedDocuments' => $governedDocs,
            'dispositionReviews' => $reviews,
            'managedUsers' => $users,
            'clearanceLevels' => $clearanceLevels,
            'canManageGovernance' => $user->hasAnyRole([BetterDocRole::RecordsOfficer->value, BetterDocRole::SystemAdmin->value]),
            'isSystemAdmin' => $user->hasRole(BetterDocRole::SystemAdmin->value),
        ]);
    }

    public function storeSchedule(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyRole([BetterDocRole::RecordsOfficer->value, BetterDocRole::SystemAdmin->value]), 403);

        /** @var array{code: string, name: string, trigger_event: string, retention_days: int, disposition_action: string, description?: string|null} $validated */
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', 'alpha_dash'],
            'name' => ['required', 'string', 'max:160'],
            'trigger_event' => ['required', Rule::in(array_column(RetentionTrigger::cases(), 'value'))],
            'retention_days' => ['required', 'integer', 'min:1', 'max:36500'],
            'disposition_action' => ['required', Rule::in(array_column(DispositionAction::cases(), 'value'))],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        RetentionSchedule::query()->updateOrCreate(
            ['tenant_id' => $user->tenant_id, 'code' => strtoupper($validated['code'])],
            [
                'name' => $validated['name'],
                'trigger_event' => RetentionTrigger::from($validated['trigger_event']),
                'retention_days' => (int) $validated['retention_days'],
                'disposition_action' => DispositionAction::from($validated['disposition_action']),
                'description' => $validated['description'] ?? null,
                'is_active' => true,
            ]
        );

        return back()->with('success', 'Retention schedule saved.');
    }

    public function assignRetention(Request $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('update', $document);

        /** @var array{retention_schedule_id: string} $validated */
        $validated = $request->validate([
            'retention_schedule_id' => ['required', 'uuid'],
        ]);

        /** @var RetentionSchedule $schedule */
        $schedule = RetentionSchedule::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', $validated['retention_schedule_id'])
            ->firstOrFail();

        $this->governance->assignRetentionSchedule($document, $schedule, $user, $request);

        return back()->with('success', "Retention schedule '{$schedule->code}' applied.");
    }

    public function toggleLegalHold(Request $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyRole([BetterDocRole::RecordsOfficer->value, BetterDocRole::Approver->value]), 403);
        Gate::authorize('view', $document);

        /** @var array{active: bool, reason: string} $validated */
        $validated = $request->validate([
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->governance->setLegalHold($document, (bool) $validated['active'], $validated['reason'], $user, $request);

        return back()->with('success', $validated['active'] ? 'Legal Hold activated on record.' : 'Legal Hold released.');
    }

    public function lockWorm(Request $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyRole([BetterDocRole::RecordsOfficer->value, BetterDocRole::Approver->value]), 403);
        Gate::authorize('update', $document);

        $this->governance->applyWormLock($document, $user, null, $request);

        return back()->with('success', 'Record permanently sealed in WORM compliance storage mode.');
    }

    public function initiateDisposition(Request $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyRole([BetterDocRole::RecordsOfficer->value, BetterDocRole::Approver->value]), 403);
        Gate::authorize('view', $document);

        /** @var array{action: string, notes: string} $validated */
        $validated = $request->validate([
            'action' => ['required', Rule::in(array_column(DispositionAction::cases(), 'value'))],
            'notes' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $this->governance->initiateDispositionReview(
                document: $document,
                initiator: $user,
                action: DispositionAction::from($validated['action']),
                notes: $validated['notes'],
                request: $request,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['disposition' => $e->getMessage()]);
        }

        return back()->with('success', 'Disposition review queued for mandatory Two-Person co-approval.');
    }

    /**
     * Synchronously downgrade a user's clearance or initiate Two-Person elevation if higher than current rank.
     */
    public function updateUserClearance(Request $request, User $targetUser): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($actor->hasRole(BetterDocRole::SystemAdmin->value) && $targetUser->tenant_id === $actor->tenant_id, 403);

        /** @var array{clearance_level_id: string, reason: string} $validated */
        $validated = $request->validate([
            'clearance_level_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        /** @var ClearanceLevel $targetLevel */
        $targetLevel = ClearanceLevel::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('id', $validated['clearance_level_id'])
            ->firstOrFail();

        if ($targetLevel->rank > $targetUser->clearance_rank) {
            $this->twoPerson->initiate(
                requester: $actor,
                actionType: TwoPersonActionType::ElevateClearance,
                targetType: 'user',
                targetId: $targetUser->id,
                targetClassificationRank: $targetLevel->rank,
                payload: ['target_clearance_level_id' => $targetLevel->id],
                reason: $validated['reason'],
                request: $request,
            );

            return back()->with('success', 'Clearance elevation requires Two-Person Rule. Request queued for independent approval.');
        }

        if ($targetLevel->rank === $targetUser->clearance_rank) {
            return back();
        }

        $before = ['clearance_rank' => $targetUser->clearance_rank];
        $targetUser->update([
            'clearance_level_id' => $targetLevel->id,
            'clearance_rank' => $targetLevel->rank,
        ]);

        $this->audit->log(
            tenantId: $targetUser->tenant_id,
            eventType: 'user.clearance_downgraded',
            resourceType: 'user',
            resourceId: $targetUser->id,
            actor: $actor,
            classificationRank: $before['clearance_rank'],
            before: $before,
            after: ['clearance_rank' => $targetLevel->rank, 'reason' => $validated['reason']],
            request: $request,
        );

        $stats = $this->revocation->revokeOutOfClearanceAccess($targetUser->refresh(), $actor, 'clearance_downgraded', $request);

        return back()->with(
            'success',
            "Clearance downgraded to {$targetLevel->name}. Synchronously revoked {$stats['revoked_wopi_tokens']} WOPI session(s)."
        );
    }
}
