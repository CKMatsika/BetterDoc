<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IntegrationDelivery;
use App\Services\Integration\IntegrationWebhookService;
use App\Services\Security\DbSecurityContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchIntegrationDeliveryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $deliveryId,
        public readonly string $tenantId,
    ) {}

    public function handle(DbSecurityContext $dbContext, IntegrationWebhookService $webhooks): void
    {
        $dbContext->runForTenant($this->tenantId, function () use ($webhooks): void {
            /** @var IntegrationDelivery|null $delivery */
            $delivery = IntegrationDelivery::query()->with('webhook')->find($this->deliveryId);
            if ($delivery === null) {
                return;
            }

            $webhooks->deliver($delivery);
        });
    }
}
