<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\TwoPersonViolationException;
use App\Models\BreakGlassSession;
use App\Models\TwoPersonRequest;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Security\BreakGlassService;
use App\Services\Security\TwoPersonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalController extends Controller
{
    public function __construct(
        private readonly TwoPersonService $twoPerson,
        private readonly BreakGlassService $breakGlass,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $requests = TwoPersonRequest::query()
            ->with(['requester', 'approver'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(static fn (TwoPersonRequest $r): array => [
                'id' => $r->id,
                'action_type' => $r->action_type->value,
                'target_type' => $r->target_type,
                'target_id' => $r->target_id,
                'target_classification_rank' => $r->target_classification_rank,
                'reason' => $r->reason,
                'status' => $r->status->value,
                'requested_by_id' => $r->requested_by,
                'requester_name' => $r->requester?->name,
                'approver_name' => $r->approver?->name,
                'can_approve' => $r->status->value === 'pending'
                    && $r->requested_by !== $user->id
                    && $user->clearance_rank >= $r->target_classification_rank,
                'created_at' => $r->created_at?->toIso8601String(),
                'expires_at' => $r->expires_at->toIso8601String(),
            ])
            ->values()
            ->all();

        $breakGlassSessions = BreakGlassSession::query()
            ->with(['user', 'firstApprover', 'secondApprover'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(30)
            ->get()
            ->map(static fn (BreakGlassSession $bg): array => [
                'id' => $bg->id,
                'user_id' => $bg->user_id,
                'user_name' => $bg->user?->name,
                'document_id' => $bg->document_id,
                'justification' => $bg->justification,
                'status' => $bg->status,
                'first_approver_name' => $bg->firstApprover?->name,
                'second_approver_name' => $bg->secondApprover?->name,
                'expires_at' => $bg->expires_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('Approvals/Index', [
            'twoPersonRequests' => $requests,
            'breakGlassSessions' => $breakGlassSessions,
        ]);
    }

    public function approve(Request $request, TwoPersonRequest $twoPersonRequest): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $session = $this->currentSession($request, $user);

        $noteVal = $request->input('note');

        try {
            $this->twoPerson->approve(
                tpRequest: $twoPersonRequest,
                approver: $user,
                session: $session,
                note: is_string($noteVal) && $noteVal !== '' ? $noteVal : null,
                request: $request,
            );
        } catch (TwoPersonViolationException $e) {
            throw ValidationException::withMessages(['approval' => $e->getMessage()]);
        }

        return back()->with('success', 'Two-person request approved and executed atomically.');
    }

    public function reject(Request $request, TwoPersonRequest $twoPersonRequest): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{note: string} $validated */
        $validated = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $this->twoPerson->reject($twoPersonRequest, $user, $validated['note'], $request);
        } catch (TwoPersonViolationException $e) {
            throw ValidationException::withMessages(['approval' => $e->getMessage()]);
        }

        return back()->with('success', 'Request rejected.');
    }

    public function requestBreakGlass(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{document_id: string, justification: string, duration_hours?: int} $validated */
        $validated = $request->validate([
            'document_id' => ['required', 'uuid'],
            'justification' => ['required', 'string', 'min:30', 'max:1000'],
            'duration_hours' => ['nullable', 'integer', 'min:1', 'max:4'],
        ]);

        try {
            $this->breakGlass->requestAccess(
                requester: $user,
                documentId: $validated['document_id'],
                justification: $validated['justification'],
                durationHours: (int) ($validated['duration_hours'] ?? 2),
                request: $request,
            );
        } catch (TwoPersonViolationException $e) {
            throw ValidationException::withMessages(['justification' => $e->getMessage()]);
        }

        return back()->with('success', 'Emergency Break-Glass request logged. Awaiting dual authorization.');
    }

    public function approveBreakGlassFirst(Request $request, BreakGlassSession $breakGlassSession): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $session = $this->currentSession($request, $user);

        try {
            $this->breakGlass->approveFirst($breakGlassSession, $user, $session, $request);
        } catch (TwoPersonViolationException $e) {
            throw ValidationException::withMessages(['break_glass' => $e->getMessage()]);
        }

        return back()->with('success', 'First break-glass approval recorded.');
    }

    public function approveBreakGlassSecond(Request $request, BreakGlassSession $breakGlassSession): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $session = $this->currentSession($request, $user);

        try {
            $this->breakGlass->approveSecond($breakGlassSession, $user, $session, 2, $request);
        } catch (TwoPersonViolationException $e) {
            throw ValidationException::withMessages(['break_glass' => $e->getMessage()]);
        }

        return back()->with('success', 'Break-glass session activated and logged loudly.');
    }

    private function currentSession(Request $request, User $user): ?UserSession
    {
        return UserSession::query()
            ->where('user_id', $user->id)
            ->where('session_id', $request->session()->getId())
            ->whereNull('revoked_at')
            ->first();
    }
}
