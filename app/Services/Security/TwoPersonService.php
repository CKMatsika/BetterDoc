<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\TwoPersonActionType;
use App\Enums\TwoPersonStatus;
use App\Exceptions\TwoPersonViolationException;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\TwoPersonRequest;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Audit\ImmutableAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TwoPersonService
{
    public function __construct(
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function initiate(
        User $requester,
        TwoPersonActionType $actionType,
        string $targetType,
        string $targetId,
        int $targetClassificationRank,
        array $payload,
        string $reason,
        ?Request $request = null,
    ): TwoPersonRequest {
        if (mb_strlen(trim($reason)) < 10) {
            throw new TwoPersonViolationException('A clear justification (at least 10 characters) is required.');
        }

        $ttlHours = config()->integer('sentinel.security.two_person_ttl_hours', 24);

        $tpRequest = TwoPersonRequest::query()->create([
            'tenant_id' => $requester->tenant_id,
            'action_type' => $actionType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_classification_rank' => $targetClassificationRank,
            'payload_json' => $payload,
            'reason' => trim($reason),
            'requested_by' => $requester->id,
            'status' => TwoPersonStatus::Pending,
            'expires_at' => now()->addHours($ttlHours),
        ]);

        $this->audit->log(
            tenantId: $requester->tenant_id,
            eventType: 'two_person.initiated',
            resourceType: $targetType,
            resourceId: $targetId,
            actor: $requester,
            classificationRank: $targetClassificationRank,
            after: [
                'two_person_request_id' => $tpRequest->id,
                'action_type' => $actionType->value,
                'reason' => $tpRequest->reason,
            ],
            request: $request,
        );

        return $tpRequest;
    }

    public function approve(
        TwoPersonRequest $tpRequest,
        User $approver,
        ?UserSession $session = null,
        ?string $note = null,
        ?Request $request = null,
    ): TwoPersonRequest {
        if ($tpRequest->tenant_id !== $approver->tenant_id) {
            throw new TwoPersonViolationException('Cross-tenant approval is forbidden.');
        }

        if ($tpRequest->status !== TwoPersonStatus::Pending) {
            throw new TwoPersonViolationException('Request is no longer pending.');
        }

        if ($tpRequest->expires_at->isPast()) {
            $tpRequest->update(['status' => TwoPersonStatus::Expired]);
            throw new TwoPersonViolationException('This two-person approval request has expired.');
        }

        if ($tpRequest->requested_by === $approver->id) {
            throw new TwoPersonViolationException('Two-person rule violation: requester cannot approve their own request.');
        }

        if ($approver->clearance_rank < $tpRequest->target_classification_rank) {
            throw new TwoPersonViolationException('Approver clearance rank is below the target resource classification.');
        }

        $ttl = config()->integer('sentinel.security.step_up_mfa_ttl_minutes', 5);
        if ($approver->mfa_enforced && ($session === null || ! $session->isMfaFresh($ttl))) {
            throw new TwoPersonViolationException('Fresh MFA step-up verification is required before co-signing.');
        }

        return DB::transaction(function () use ($tpRequest, $approver, $note, $request): TwoPersonRequest {
            $this->executeApprovedAction($tpRequest, $approver, $request);

            $tpRequest->update([
                'approved_by' => $approver->id,
                'status' => TwoPersonStatus::Approved,
                'decision_note' => $note,
                'decided_at' => now(),
            ]);

            $this->audit->log(
                tenantId: $tpRequest->tenant_id,
                eventType: 'two_person.approved',
                resourceType: $tpRequest->target_type,
                resourceId: $tpRequest->target_id,
                actor: $approver,
                classificationRank: $tpRequest->target_classification_rank,
                after: [
                    'two_person_request_id' => $tpRequest->id,
                    'action_type' => $tpRequest->action_type->value,
                    'requested_by' => $tpRequest->requested_by,
                    'note' => $note,
                ],
                request: $request,
            );

            return $tpRequest->refresh();
        });
    }

    public function reject(
        TwoPersonRequest $tpRequest,
        User $approver,
        string $note,
        ?Request $request = null,
    ): TwoPersonRequest {
        if ($tpRequest->tenant_id !== $approver->tenant_id || $tpRequest->requested_by === $approver->id) {
            throw new TwoPersonViolationException('Invalid two-person rejection actor.');
        }

        if ($tpRequest->status !== TwoPersonStatus::Pending) {
            throw new TwoPersonViolationException('Request is no longer pending.');
        }

        $tpRequest->update([
            'approved_by' => $approver->id,
            'status' => TwoPersonStatus::Rejected,
            'decision_note' => $note,
            'decided_at' => now(),
        ]);

        $this->audit->log(
            tenantId: $tpRequest->tenant_id,
            eventType: 'two_person.rejected',
            resourceType: $tpRequest->target_type,
            resourceId: $tpRequest->target_id,
            actor: $approver,
            classificationRank: $tpRequest->target_classification_rank,
            after: ['two_person_request_id' => $tpRequest->id, 'note' => $note],
            request: $request,
        );

        return $tpRequest->refresh();
    }

    private function executeApprovedAction(TwoPersonRequest $tpRequest, User $approver, ?Request $request): void
    {
        $payload = $tpRequest->payload_json;

        if ($tpRequest->action_type === TwoPersonActionType::Declassify) {
            $doc = Document::query()->where('id', $tpRequest->target_id)->firstOrFail();
            $targetLevelId = is_string($payload['target_classification_level_id'] ?? null)
                ? $payload['target_classification_level_id']
                : '';
            $targetLevel = ClearanceLevel::query()
                ->where('tenant_id', $tpRequest->tenant_id)
                ->where('id', $targetLevelId)
                ->firstOrFail();

            $before = ['classification_rank' => $doc->classification_rank, 'classification_level_id' => $doc->classification_level_id];
            $doc->update([
                'classification_level_id' => $targetLevel->id,
                'classification_rank' => $targetLevel->rank,
            ]);

            $this->audit->log(
                tenantId: $doc->tenant_id,
                eventType: 'document.declassified',
                resourceType: 'document',
                resourceId: $doc->id,
                actor: $approver,
                classificationRank: max($before['classification_rank'], $targetLevel->rank),
                before: $before,
                after: ['classification_rank' => $targetLevel->rank, 'classification_level_id' => $targetLevel->id],
                request: $request,
            );
        } elseif ($tpRequest->action_type === TwoPersonActionType::DeleteSensitive) {
            $doc = Document::query()->where('id', $tpRequest->target_id)->firstOrFail();
            if ($doc->legal_hold_active || $doc->is_worm) {
                throw new TwoPersonViolationException('Cannot delete record under Legal Hold or WORM lock.');
            }
            $doc->delete();

            $this->audit->log(
                tenantId: $doc->tenant_id,
                eventType: 'document.deleted_two_person',
                resourceType: 'document',
                resourceId: $doc->id,
                actor: $approver,
                classificationRank: $doc->classification_rank,
                before: ['title' => $doc->title, 'doc_number' => $doc->doc_number],
                request: $request,
            );
        } elseif ($tpRequest->action_type === TwoPersonActionType::ElevateClearance) {
            $targetUser = User::query()->where('tenant_id', $tpRequest->tenant_id)->where('id', $tpRequest->target_id)->firstOrFail();
            $targetClearanceId = is_string($payload['target_clearance_level_id'] ?? null)
                ? $payload['target_clearance_level_id']
                : '';
            $targetLevel = ClearanceLevel::query()
                ->where('tenant_id', $tpRequest->tenant_id)
                ->where('id', $targetClearanceId)
                ->firstOrFail();

            $before = ['clearance_rank' => $targetUser->clearance_rank];
            $targetUser->update([
                'clearance_level_id' => $targetLevel->id,
                'clearance_rank' => $targetLevel->rank,
            ]);

            $this->audit->log(
                tenantId: $targetUser->tenant_id,
                eventType: 'user.clearance_elevated',
                resourceType: 'user',
                resourceId: $targetUser->id,
                actor: $approver,
                classificationRank: $targetLevel->rank,
                before: $before,
                after: ['clearance_rank' => $targetLevel->rank],
                request: $request,
            );
        }
    }
}
