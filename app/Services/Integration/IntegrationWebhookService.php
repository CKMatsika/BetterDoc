<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Exceptions\AirGapViolationException;
use App\Jobs\DispatchIntegrationDeliveryJob;
use App\Models\IntegrationDelivery;
use App\Models\IntegrationWebhook;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use Illuminate\Support\Facades\Http;
use Throwable;

class IntegrationWebhookService
{
    public function __construct(
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * @return list<string>
     */
    public static function supportedEvents(): array
    {
        return [
            'document.ingested',
            'document.approved',
            'signature.completed',
            'ap.invoice_matched',
            'ap.invoice_approved',
            'erp.sync_completed',
            'webhook.test',
        ];
    }

    /**
     * Queue or immediately dispatch HMAC-signed webhooks for all matching active tenant subscriptions.
     *
     * @param  array<string, mixed>  $data
     */
    public function emit(
        string $tenantId,
        string $eventType,
        array $data,
        ?User $actor = null,
        bool $syncDispatch = false,
    ): int {
        $webhooks = IntegrationWebhook::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        $queued = 0;

        /** @var IntegrationWebhook $webhook */
        foreach ($webhooks as $webhook) {
            $events = $webhook->events_json;
            if (! in_array($eventType, $events, true) && ! in_array('*', $events, true)) {
                continue;
            }

            /** @var IntegrationDelivery $delivery */
            $delivery = IntegrationDelivery::query()->create([
                'tenant_id' => $tenantId,
                'integration_webhook_id' => $webhook->id,
                'event_type' => $eventType,
                'payload_json' => [
                    'event' => $eventType,
                    'tenant_id' => $tenantId,
                    'occurred_at' => now('UTC')->toIso8601String(),
                    'data' => $data,
                ],
                'status' => 'pending',
                'attempts' => 0,
            ]);

            if ($syncDispatch) {
                $this->deliver($delivery, $actor);
            } else {
                DispatchIntegrationDeliveryJob::dispatch($delivery->id, $tenantId);
            }

            $queued++;
        }

        return $queued;
    }

    public function deliver(IntegrationDelivery $delivery, ?User $actor = null): IntegrationDelivery
    {
        /** @var IntegrationWebhook|null $webhook */
        $webhook = $delivery->webhook;
        if ($webhook === null || ! $webhook->is_active) {
            $delivery->update([
                'status' => 'failed',
                'attempts' => $delivery->attempts + 1,
                'response_excerpt' => 'Webhook disabled or removed.',
                'dispatched_at' => now(),
            ]);

            return $delivery->refresh();
        }

        $body = json_encode($delivery->payload_json, JSON_THROW_ON_ERROR);
        $timestamp = (string) now('UTC')->timestamp;
        $secret = (string) $webhook->secret_encrypted;
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-BetterDoc-Event' => $delivery->event_type,
                'X-BetterDoc-Delivery' => $delivery->id,
                'X-BetterDoc-Timestamp' => $timestamp,
                'X-BetterDoc-Signature' => "sha256={$signature}",
            ])
                ->timeout(10)
                ->withBody($body, 'application/json')
                ->post($webhook->target_url);

            $status = $response->successful() ? 'delivered' : 'failed';
            $excerpt = substr((string) $response->body(), 0, 450);

            $delivery->update([
                'status' => $status,
                'attempts' => $delivery->attempts + 1,
                'response_code' => $response->status(),
                'response_excerpt' => $excerpt !== '' ? $excerpt : null,
                'dispatched_at' => now(),
            ]);

            $webhook->update([
                'last_dispatched_at' => now(),
                'last_status_code' => $response->status(),
                'failure_count' => $response->successful() ? 0 : $webhook->failure_count + 1,
            ]);
        } catch (AirGapViolationException $e) {
            $delivery->update([
                'status' => 'blocked_airgap',
                'attempts' => $delivery->attempts + 1,
                'response_excerpt' => substr($e->getMessage(), 0, 450),
                'dispatched_at' => now(),
            ]);

            $this->audit->log(
                tenantId: $delivery->tenant_id,
                eventType: 'integration.webhook_blocked_airgap',
                resourceType: 'integration_delivery',
                resourceId: $delivery->id,
                actor: $actor,
                classificationRank: 1,
                after: ['target_url' => $webhook->target_url, 'event' => $delivery->event_type],
            );
        } catch (Throwable $e) {
            $delivery->update([
                'status' => 'failed',
                'attempts' => $delivery->attempts + 1,
                'response_excerpt' => substr($e->getMessage(), 0, 450),
                'dispatched_at' => now(),
            ]);

            $webhook->update([
                'last_dispatched_at' => now(),
                'failure_count' => $webhook->failure_count + 1,
            ]);
        }

        return $delivery->refresh();
    }
}
