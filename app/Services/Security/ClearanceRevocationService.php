<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\Document;
use App\Models\DocumentLock;
use App\Models\User;
use App\Models\WopiToken;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Collaboration\RealtimeNotificationService;
use Illuminate\Http\Request;

class ClearanceRevocationService
{
    public function __construct(
        private readonly ClearancePolicyEngine $policyEngine,
        private readonly DbSecurityContext $dbContext,
        private readonly ImmutableAuditLogger $audit,
        private readonly RealtimeNotificationService $notifications,
    ) {}

    /**
     * Synchronously revoke out-of-clearance WOPI co-editing tokens, WOPI locks, and notify client tabs
     * whenever a user's clearance rank decreases, a compartment expires/is removed, or unit changes.
     *
     * @return array{revoked_wopi_tokens: int, unlocked_wopi_docs: int}
     */
    public function revokeOutOfClearanceAccess(
        User $subjectUser,
        ?User $actor = null,
        string $reason = 'clearance_changed',
        ?Request $request = null,
    ): array {
        $revokedTokens = 0;
        $unlockedDocs = 0;

        $this->dbContext->runAs($subjectUser, function () use ($subjectUser, $actor, $reason, $request, &$revokedTokens, &$unlockedDocs): void {
            $activeTokens = WopiToken::query()
                ->where('tenant_id', $subjectUser->tenant_id)
                ->where('user_id', $subjectUser->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->get();

            foreach ($activeTokens as $token) {
                /** @var Document|null $doc */
                $doc = Document::query()->where('id', $token->document_id)->first();

                $viewAllowed = $doc !== null && $this->policyEngine->authorize($subjectUser, 'view', $doc, [
                    'bypass_abac' => true,
                ])->allowed;

                $editStillAllowed = $viewAllowed && (! $token->can_write || $this->policyEngine->authorize($subjectUser, 'update', $doc, [
                    'bypass_abac' => true,
                ])->allowed);

                if (! $viewAllowed || ! $editStillAllowed) {
                    $token->update(['revoked_at' => now()]);
                    $revokedTokens++;

                    $deletedLocks = DocumentLock::query()
                        ->where('document_id', $token->document_id)
                        ->where('locked_by', $subjectUser->id)
                        ->where('lock_type', 'wopi')
                        ->delete();

                    if (is_int($deletedLocks)) {
                        $unlockedDocs += $deletedLocks;
                    }
                }
            }

            if ($revokedTokens > 0 || $unlockedDocs > 0) {
                $this->audit->log(
                    tenantId: $subjectUser->tenant_id,
                    eventType: 'security.clearance_sessions_revoked',
                    resourceType: 'user',
                    resourceId: $subjectUser->id,
                    actor: $actor ?? $subjectUser,
                    classificationRank: $subjectUser->clearance_rank,
                    after: [
                        'reason' => $reason,
                        'revoked_wopi_tokens' => $revokedTokens,
                        'unlocked_wopi_docs' => $unlockedDocs,
                    ],
                    request: $request,
                );
            }
        });

        $this->notifications->notifyUser(
            recipient: $subjectUser,
            type: 'security.clearance_updated',
            title: 'Security Clearance Context Updated',
            body: "Your clearance or compartment profile changed ({$reason}). Active out-of-clearance editing sessions were terminated.",
            actionUrl: '/documents',
            metadata: [
                'reason' => $reason,
                'revoked_wopi_tokens' => $revokedTokens,
            ],
            classificationRank: 1,
        );

        return [
            'revoked_wopi_tokens' => $revokedTokens,
            'unlocked_wopi_docs' => $unlockedDocs,
        ];
    }
}
