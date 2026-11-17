<?php

declare(strict_types=1);

namespace App\Services\Dms;

use App\Enums\DispositionAction;
use App\Enums\TwoPersonActionType;
use App\Models\DispositionReview;
use App\Models\Document;
use App\Models\RetentionSchedule;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Security\TwoPersonService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GovernanceService
{
    public function __construct(
        private readonly TwoPersonService $twoPerson,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function assignRetentionSchedule(
        Document $document,
        RetentionSchedule $schedule,
        User $actor,
        ?Request $request = null,
    ): Document {
        if ($document->tenant_id !== $schedule->tenant_id) {
            throw new RuntimeException('Cross-tenant retention schedule forbidden.');
        }

        $expiresAt = now()->addDays(max(1, $schedule->retention_days));

        $document->update([
            'retention_schedule_id' => $schedule->id,
            'retention_expires_at' => $expiresAt,
        ]);

        $this->audit->log(
            tenantId: $document->tenant_id,
            eventType: 'governance.retention_assigned',
            resourceType: 'document',
            resourceId: $document->id,
            actor: $actor,
            classificationRank: $document->classification_rank,
            after: [
                'schedule_code' => $schedule->code,
                'retention_days' => $schedule->retention_days,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            request: $request,
        );

        return $document->refresh();
    }

    public function setLegalHold(
        Document $document,
        bool $active,
        string $reason,
        User $actor,
        ?Request $request = null,
    ): Document {
        $document->update([
            'legal_hold_active' => $active,
            'legal_hold_reason' => $active ? trim($reason) : null,
        ]);

        $this->audit->log(
            tenantId: $document->tenant_id,
            eventType: $active ? 'governance.legal_hold_placed' : 'governance.legal_hold_released',
            resourceType: 'document',
            resourceId: $document->id,
            actor: $actor,
            classificationRank: $document->classification_rank,
            after: ['legal_hold_active' => $active, 'reason' => trim($reason)],
            request: $request,
        );

        return $document->refresh();
    }

    /**
     * Permanently mark a document as Write-Once Read-Many (WORM) with tamper-evident vault lock manifest.
     */
    public function applyWormLock(
        Document $document,
        User $actor,
        ?Carbon $retainUntil = null,
        ?Request $request = null,
    ): Document {
        if ($document->is_worm) {
            return $document;
        }

        $lockUntil = $retainUntil ?? now()->addYears(7);
        $lockedAt = now();

        $document->update([
            'is_worm' => true,
            'worm_locked_at' => $lockedAt,
            'worm_retention_until' => $lockUntil,
        ]);

        $currentVersion = $document->currentVersion;
        if ($currentVersion !== null) {
            $manifestKey = "tenants/{$document->tenant_id}/docs/{$document->id}/worm_compliance.lock.json";
            $manifest = json_encode([
                'document_id' => $document->id,
                'doc_number' => $document->doc_number,
                'version_id' => $currentVersion->id,
                'sha256_hash' => $currentVersion->sha256_hash,
                'worm_mode' => 'COMPLIANCE',
                'locked_at' => $lockedAt->toIso8601String(),
                'retain_until' => $lockUntil->toIso8601String(),
                'locked_by' => $actor->id,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

            Storage::disk($currentVersion->storage_disk)->put($manifestKey, $manifest, [
                'ObjectLockMode' => 'COMPLIANCE',
                'ObjectLockRetainUntilDate' => $lockUntil->toIso8601String(),
            ]);
        }

        $this->audit->log(
            tenantId: $document->tenant_id,
            eventType: 'governance.worm_locked',
            resourceType: 'document',
            resourceId: $document->id,
            actor: $actor,
            classificationRank: $document->classification_rank,
            after: [
                'is_worm' => true,
                'worm_retention_until' => $lockUntil->toIso8601String(),
            ],
            request: $request,
        );

        return $document->refresh();
    }

    /**
     * Initiate end-of-retention disposition review backed by Two-Person Rule approval.
     */
    public function initiateDispositionReview(
        Document $document,
        User $initiator,
        DispositionAction $action,
        string $notes,
        ?Request $request = null,
    ): DispositionReview {
        if ($document->legal_hold_active) {
            throw new RuntimeException('Cannot dispose document while Legal Hold is active.');
        }

        if ($document->is_worm && $document->worm_retention_until !== null && $document->worm_retention_until->isFuture()) {
            throw new RuntimeException('Cannot dispose document inside immutable WORM retention window.');
        }

        $schedule = $document->retentionSchedule;
        if ($schedule === null) {
            throw new RuntimeException('Document must have an assigned Retention Schedule before disposition.');
        }

        $tpRequest = $this->twoPerson->initiate(
            requester: $initiator,
            actionType: TwoPersonActionType::DisposeRecord,
            targetType: 'document',
            targetId: $document->id,
            targetClassificationRank: $document->classification_rank,
            payload: [
                'doc_number' => $document->doc_number,
                'disposition_action' => $action->value,
                'retention_schedule_id' => $schedule->id,
            ],
            reason: $notes,
            request: $request,
        );

        /** @var DispositionReview $review */
        $review = DispositionReview::query()->create([
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
            'retention_schedule_id' => $schedule->id,
            'initiated_by' => $initiator->id,
            'two_person_request_id' => $tpRequest->id,
            'proposed_action' => $action,
            'status' => 'pending_two_person',
            'notes' => $notes,
        ]);

        return $review;
    }
}
