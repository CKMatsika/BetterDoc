<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\TwoPersonViolationException;
use App\Models\BreakGlassSession;
use App\Models\Document;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Collaboration\RealtimeNotificationService;
use Illuminate\Http\Request;

class BreakGlassService
{
    public function __construct(
        private readonly ImmutableAuditLogger $audit,
        private readonly RealtimeNotificationService $notifications,
    ) {}

    public function requestAccess(
        User $requester,
        string $documentId,
        string $justification,
        int $durationHours = 2,
        ?Request $request = null,
    ): BreakGlassSession {
        if (mb_strlen(trim($justification)) < 30) {
            throw new TwoPersonViolationException('Break-glass emergency justification must be at least 30 characters.');
        }

        $maxHours = config()->integer('sentinel.security.break_glass_max_hours', 4);
        $hours = max(1, min($durationHours, $maxHours));

        $session = BreakGlassSession::query()->create([
            'tenant_id' => $requester->tenant_id,
            'user_id' => $requester->id,
            'document_id' => $documentId,
            'justification' => trim($justification),
            'status' => 'pending_first',
            'expires_at' => now()->addHours($hours),
        ]);

        $this->audit->log(
            tenantId: $requester->tenant_id,
            eventType: 'security.break_glass_requested',
            resourceType: 'document',
            resourceId: $documentId,
            actor: $requester,
            classificationRank: 4,
            after: [
                'break_glass_session_id' => $session->id,
                'justification' => $session->justification,
                'requested_hours' => $hours,
            ],
            request: $request,
        );

        $this->notifications->notifyBreakGlassAlert($session, 'requested');

        return $session;
    }

    public function approveFirst(
        BreakGlassSession $bgSession,
        User $approver,
        ?UserSession $userSession = null,
        ?Request $request = null,
    ): BreakGlassSession {
        $this->validateApprover($bgSession, $approver, $userSession);

        if ($bgSession->status !== 'pending_first') {
            throw new TwoPersonViolationException('Break-glass session is not awaiting first approval.');
        }

        $bgSession->update([
            'first_approver_id' => $approver->id,
            'status' => 'pending_second',
        ]);

        $this->audit->log(
            tenantId: $bgSession->tenant_id,
            eventType: 'security.break_glass_first_approved',
            resourceType: 'document',
            resourceId: $bgSession->document_id,
            actor: $approver,
            classificationRank: 4,
            after: ['break_glass_session_id' => $bgSession->id],
            request: $request,
        );

        $this->notifications->notifyBreakGlassAlert($bgSession, 'first_approved');

        return $bgSession->refresh();
    }

    public function approveSecond(
        BreakGlassSession $bgSession,
        User $approver,
        ?UserSession $userSession = null,
        int $durationHours = 2,
        ?Request $request = null,
    ): BreakGlassSession {
        $this->validateApprover($bgSession, $approver, $userSession);

        if ($bgSession->status !== 'pending_second') {
            throw new TwoPersonViolationException('Break-glass session is not awaiting second approval.');
        }

        if ($bgSession->first_approver_id === $approver->id) {
            throw new TwoPersonViolationException('Second break-glass approver must be distinct from the first approver.');
        }

        $maxHours = config()->integer('sentinel.security.break_glass_max_hours', 4);
        $hours = max(1, min($durationHours, $maxHours));

        $bgSession->update([
            'second_approver_id' => $approver->id,
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addHours($hours),
        ]);

        $this->audit->log(
            tenantId: $bgSession->tenant_id,
            eventType: 'security.break_glass_activated',
            resourceType: 'document',
            resourceId: $bgSession->document_id,
            actor: $approver,
            classificationRank: 4,
            after: [
                'break_glass_session_id' => $bgSession->id,
                'beneficiary_user_id' => $bgSession->user_id,
                'first_approver_id' => $bgSession->first_approver_id,
                'second_approver_id' => $approver->id,
                'expires_at' => $bgSession->expires_at?->toIso8601String(),
            ],
            request: $request,
        );

        $this->notifications->notifyBreakGlassAlert($bgSession, 'activated');

        return $bgSession->refresh();
    }

    public function revoke(BreakGlassSession $bgSession, User $actor, ?Request $request = null): BreakGlassSession
    {
        if ($bgSession->tenant_id !== $actor->tenant_id) {
            throw new TwoPersonViolationException('Cross-tenant revocation forbidden.');
        }

        $bgSession->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $this->audit->log(
            tenantId: $bgSession->tenant_id,
            eventType: 'security.break_glass_revoked',
            resourceType: 'document',
            resourceId: $bgSession->document_id,
            actor: $actor,
            classificationRank: 4,
            after: ['break_glass_session_id' => $bgSession->id],
            request: $request,
        );

        $this->notifications->notifyBreakGlassAlert($bgSession, 'revoked');

        return $bgSession->refresh();
    }

    private function validateApprover(BreakGlassSession $bgSession, User $approver, ?UserSession $userSession): void
    {
        if ($bgSession->tenant_id !== $approver->tenant_id) {
            throw new TwoPersonViolationException('Cross-tenant break-glass approval is forbidden.');
        }

        if ($bgSession->user_id === $approver->id) {
            throw new TwoPersonViolationException('Requester cannot approve their own break-glass request.');
        }

        $ttl = config()->integer('sentinel.security.step_up_mfa_ttl_minutes', 5);
        if ($approver->mfa_enforced && ($userSession === null || ! $userSession->isMfaFresh($ttl))) {
            throw new TwoPersonViolationException('Fresh MFA step-up verification is required for break-glass approval.');
        }

        /** @var Document|null $doc */
        $doc = Document::withoutGlobalScopes()->where('id', $bgSession->document_id)->first();
        if ($doc !== null && $approver->clearance_rank < $doc->classification_rank) {
            throw new TwoPersonViolationException('Approver clearance rank is insufficient to authorize access to this document.');
        }
    }
}
