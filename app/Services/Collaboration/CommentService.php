<?php

declare(strict_types=1);

namespace App\Services\Collaboration;

use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Security\ClearancePolicyEngine;
use Illuminate\Http\Request;

class CommentService
{
    public function __construct(
        private readonly ClearancePolicyEngine $policyEngine,
        private readonly RealtimeNotificationService $notifications,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * Post a document comment, enforcing MAC/Compartment filtering BEFORE notifying any @mentioned user.
     *
     * @param  list<string>  $explicitMentionIds
     */
    public function createComment(
        Document $document,
        User $author,
        string $body,
        ?string $parentId = null,
        array $explicitMentionIds = [],
        ?Request $request = null,
    ): DocumentComment {
        $candidateIds = $this->resolveCandidateMentionIds($document->tenant_id, $body, $explicitMentionIds);

        /** @var list<string> $allowedMentions */
        $allowedMentions = [];
        /** @var list<string> $suppressedMentions */
        $suppressedMentions = [];

        foreach ($candidateIds as $candidateId) {
            if ($candidateId === $author->id) {
                continue;
            }

            /** @var User|null $candidate */
            $candidate = User::query()
                ->where('tenant_id', $document->tenant_id)
                ->where('id', $candidateId)
                ->first();

            if ($candidate === null) {
                continue;
            }

            // CRITICAL: Check full MAC + Compartments + HR Shield + Unit/ACL BEFORE generating any notification or broadcast.
            $decision = $this->policyEngine->authorize($candidate, 'view', $document, [
                'bypass_abac' => true,
            ]);

            if ($decision->allowed) {
                $allowedMentions[] = $candidate->id;
            } else {
                $suppressedMentions[] = $candidate->id;
            }
        }

        /** @var DocumentComment $comment */
        $comment = DocumentComment::query()->create([
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
            'parent_id' => $parentId,
            'user_id' => $author->id,
            'body' => trim($body),
            'mentioned_user_ids_json' => $allowedMentions,
            'suppressed_mention_ids_json' => $suppressedMentions,
        ]);

        foreach ($allowedMentions as $allowedUserId) {
            /** @var User|null $recipient */
            $recipient = User::query()->where('id', $allowedUserId)->first();
            if ($recipient !== null) {
                $this->notifications->notifyUser(
                    recipient: $recipient,
                    type: 'document.mentioned',
                    title: "Mentioned on {$document->doc_number}",
                    body: "{$author->name} mentioned you in a comment on {$document->doc_number}.",
                    documentId: $document->id,
                    actionUrl: "/documents/{$document->id}",
                    metadata: ['comment_id' => $comment->id, 'author_id' => $author->id],
                    classificationRank: $document->classification_rank,
                );
            }
        }

        if ($suppressedMentions !== []) {
            $this->audit->log(
                tenantId: $document->tenant_id,
                eventType: 'comment.mention_suppressed_clearance',
                resourceType: 'document',
                resourceId: $document->id,
                actor: $author,
                classificationRank: $document->classification_rank,
                after: ['comment_id' => $comment->id, 'suppressed_count' => count($suppressedMentions)],
                request: $request,
            );
        }

        $this->audit->log(
            tenantId: $document->tenant_id,
            eventType: 'document.comment_created',
            resourceType: 'document',
            resourceId: $document->id,
            actor: $author,
            classificationRank: $document->classification_rank,
            after: ['comment_id' => $comment->id, 'allowed_mentions_count' => count($allowedMentions)],
            request: $request,
        );

        $this->notifications->broadcastDocumentActivity($document->id, 'comment.created', [
            'comment_id' => $comment->id,
        ]);

        return $comment->load('author');
    }

    /**
     * Resolve explicit user IDs plus @handle tokens matching service_number or email local-part.
     *
     * @param  list<string>  $explicitMentionIds
     * @return list<string>
     */
    private function resolveCandidateMentionIds(string $tenantId, string $body, array $explicitMentionIds): array
    {
        $ids = array_values(array_unique(array_filter($explicitMentionIds, static fn (string $v): bool => $v !== '')));

        if (preg_match_all('/@([A-Za-z0-9._-]+)/', $body, $matches) > 0) {
            /** @var list<string> $tokens */
            $tokens = $matches[1];
            foreach ($tokens as $token) {
                $matched = User::query()
                    ->where('tenant_id', $tenantId)
                    ->where(function ($q) use ($token): void {
                        $q->whereRaw('LOWER(service_number) = ?', [mb_strtolower($token)])
                            ->orWhereRaw('LOWER(email) LIKE ?', [mb_strtolower($token).'@%'])
                            ->orWhereRaw('LOWER(email) = ?', [mb_strtolower($token)]);
                    })
                    ->pluck('id')
                    ->all();

                foreach ($matched as $uid) {
                    if (is_string($uid) && ! in_array($uid, $ids, true)) {
                        $ids[] = $uid;
                    }
                }
            }
        }

        return $ids;
    }
}
