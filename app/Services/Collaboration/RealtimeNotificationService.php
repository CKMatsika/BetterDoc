<?php

declare(strict_types=1);

namespace App\Services\Collaboration;

use App\Enums\SentinelRole;
use App\Events\DocumentActivityBroadcast;
use App\Events\TenantUserAlertBroadcast;
use App\Models\BreakGlassSession;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class RealtimeNotificationService
{
    /**
     * Persist a clearance-verified notification and dispatch a non-classified Reverb signal.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function notifyUser(
        User $recipient,
        string $type,
        string $title,
        string $body,
        ?string $documentId = null,
        ?string $actionUrl = null,
        array $metadata = [],
        ?int $classificationRank = null,
    ): UserNotification {
        /** @var UserNotification $notification */
        $notification = UserNotification::query()->create([
            'tenant_id' => $recipient->tenant_id,
            'user_id' => $recipient->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'document_id' => $documentId,
            'action_url' => $actionUrl,
            'metadata_json' => $metadata,
        ]);

        // Never leak classified document titles or bodies over pub/sub frames for Confidential+ records.
        $broadcastTitle = ($classificationRank !== null && $classificationRank >= 2)
            ? 'Protected Clearance Alert (Open Inbox)'
            : $title;

        $this->dispatchSafe(new TenantUserAlertBroadcast(
            tenantId: $recipient->tenant_id,
            userId: $recipient->id,
            payload: [
                'notification_id' => $notification->id,
                'type' => $type,
                'title' => $broadcastTitle,
                'document_id' => $documentId,
                'action_url' => $actionUrl,
                'created_at' => $notification->created_at?->toIso8601String(),
            ],
        ));

        return $notification;
    }

    /**
     * Notify all Auditors and System Admins when emergency break-glass access is requested or activated.
     */
    public function notifyBreakGlassAlert(BreakGlassSession $session, string $stage): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($session->tenant_id);

        $watchers = User::query()
            ->where('tenant_id', $session->tenant_id)
            ->whereHas('roles', static function ($q): void {
                $q->whereIn('name', [SentinelRole::Auditor->value, SentinelRole::SystemAdmin->value]);
            })
            ->get();

        foreach ($watchers as $watcher) {
            $this->notifyUser(
                recipient: $watcher,
                type: "security.break_glass_{$stage}",
                title: $stage === 'activated'
                    ? 'SECURITY ALERT: Emergency Break-Glass Activated'
                    : 'Security Notice: Break-Glass Access Requested',
                body: "Break-glass session {$session->id} for document {$session->document_id} ({$stage}). Review audit ledger immediately.",
                documentId: $session->document_id,
                actionUrl: '/approvals',
                metadata: [
                    'break_glass_session_id' => $session->id,
                    'stage' => $stage,
                    'beneficiary_user_id' => $session->user_id,
                ],
                classificationRank: 4,
            );
        }
    }

    /**
     * Broadcast a non-sensitive activity ping to users subscribed to presence-document.{id}.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function broadcastDocumentActivity(string $documentId, string $eventType, array $metadata = []): void
    {
        $this->dispatchSafe(new DocumentActivityBroadcast(
            documentId: $documentId,
            eventType: $eventType,
            metadata: $metadata,
        ));
    }

    private function dispatchSafe(object $event): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        try {
            event($event);
        } catch (Throwable $e) {
            Log::warning('Sentinel Reverb broadcast skipped cleanly.', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
