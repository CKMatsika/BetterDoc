<?php

declare(strict_types=1);

namespace App\Services\Integration\Connectors;

use App\Enums\ErpSystemType;
use App\Exceptions\AirGapViolationException;
use App\Models\IntegrationConnection;
use App\Models\IntegrationSyncRun;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Integration\IntegrationWebhookService;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ErpConnectorManager
{
    public function __construct(
        private readonly XeroErpConnector $xero,
        private readonly NetSuiteErpConnector $netsuite,
        private readonly ImmutableAuditLogger $audit,
        private readonly IntegrationWebhookService $webhooks,
    ) {}

    public function resolve(ErpSystemType $systemType): ErpConnectorInterface
    {
        return match ($systemType) {
            ErpSystemType::Xero => $this->xero,
            ErpSystemType::Netsuite => $this->netsuite,
            default => throw new RuntimeException("No native cloud ERP connector registered for '{$systemType->value}'."),
        };
    }

    public function runSync(
        IntegrationConnection $connection,
        User $actor,
        string $direction,
        ?Request $request = null,
    ): IntegrationSyncRun {
        /** @var IntegrationSyncRun $run */
        $run = IntegrationSyncRun::query()->create([
            'tenant_id' => $connection->tenant_id,
            'integration_connection_id' => $connection->id,
            'triggered_by' => $actor->id,
            'direction' => $direction,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $connector = $this->resolve($connection->system_type);

            if ($direction === 'test_connection') {
                $res = $connector->testConnection($connection);
                $run->update([
                    'status' => $res['ok'] ? 'completed' : 'failed',
                    'records_processed' => $res['ok'] ? 1 : 0,
                    'summary_json' => ['message' => $res['message'], ...$res['details']],
                    'finished_at' => now(),
                ]);
            } elseif ($direction === 'pull_vendors') {
                $res = $connector->pullVendors($connection, $actor);
                $run->update([
                    'status' => 'completed',
                    'records_processed' => $res['processed'],
                    'records_failed' => $res['failed'],
                    'summary_json' => $res['details'],
                    'finished_at' => now(),
                ]);
            } elseif ($direction === 'pull_purchase_orders') {
                $res = $connector->pullPurchaseOrders($connection, $actor);
                $run->update([
                    'status' => 'completed',
                    'records_processed' => $res['processed'],
                    'records_failed' => $res['failed'],
                    'summary_json' => $res['details'],
                    'finished_at' => now(),
                ]);
            } elseif ($direction === 'push_invoices') {
                $res = $connector->pushApprovedInvoices($connection, $actor);
                $run->update([
                    'status' => 'completed',
                    'records_processed' => $res['processed'],
                    'records_failed' => $res['failed'],
                    'summary_json' => $res['details'],
                    'finished_at' => now(),
                ]);
            } else {
                throw new RuntimeException("Unsupported sync direction '{$direction}'.");
            }

            $connection->update(['last_sync_at' => now()]);

            $this->audit->log(
                tenantId: $connection->tenant_id,
                eventType: 'integration.erp_sync_completed',
                resourceType: 'integration_sync_run',
                resourceId: $run->id,
                actor: $actor,
                classificationRank: $actor->clearance_rank,
                after: [
                    'system_type' => $connection->system_type->value,
                    'direction' => $direction,
                    'processed' => $run->records_processed,
                    'failed' => $run->records_failed,
                ],
                request: $request,
            );

            $this->webhooks->emit(
                tenantId: $connection->tenant_id,
                eventType: 'erp.sync_completed',
                data: [
                    'sync_run_id' => $run->id,
                    'connection_id' => $connection->id,
                    'system_type' => $connection->system_type->value,
                    'direction' => $direction,
                    'records_processed' => $run->records_processed,
                    'records_failed' => $run->records_failed,
                ],
                actor: $actor,
            );
        } catch (AirGapViolationException $e) {
            $run->update([
                'status' => 'blocked_airgap',
                'error_message' => substr($e->getMessage(), 0, 480),
                'finished_at' => now(),
            ]);

            $this->audit->log(
                tenantId: $connection->tenant_id,
                eventType: 'integration.erp_sync_blocked_airgap',
                resourceType: 'integration_sync_run',
                resourceId: $run->id,
                actor: $actor,
                classificationRank: $actor->clearance_rank,
                after: ['system_type' => $connection->system_type->value, 'direction' => $direction, 'error' => $e->getMessage()],
                request: $request,
            );
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error_message' => substr($e->getMessage(), 0, 480),
                'finished_at' => now(),
            ]);
        }

        return $run->refresh();
    }
}
