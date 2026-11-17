<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\User;
use App\Services\Security\ClearancePolicyEngine;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('tenant.{tenantId}.user.{userId}', static function (User $user, string $tenantId, string $userId): bool {
    return (string) $user->tenant_id === $tenantId && (string) $user->id === $userId;
});

Broadcast::channel('document.{documentId}', static function (User $user, string $documentId): bool|array {
    /** @var Document|null $document */
    $document = Document::query()->where('id', $documentId)->first();
    if ($document === null) {
        return false;
    }

    $decision = app(ClearancePolicyEngine::class)->authorize($user, 'view', $document, [
        'bypass_abac' => true,
    ]);

    if (! $decision->allowed) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => ($user->rank_title ? $user->rank_title.' ' : '').$user->name,
        'clearance_rank' => $user->clearance_rank,
        'org_unit' => $user->orgUnit?->code,
    ];
});
