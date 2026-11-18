<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\AirGapViolationException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Security\DbSecurityContext;
use App\Services\Sync\SyncGatewayService;
use Illuminate\Console\Command;

class SyncGatewayCommand extends Command
{
    protected $signature = 'betterdoc:sync:process {--tenant= : Optional tenant UUID}';

    protected $description = 'Process encrypted outbox replication events (hard-blocked when air-gapped)';

    public function handle(SyncGatewayService $sync, DbSecurityContext $dbContext): int
    {
        $tenantOpt = $this->option('tenant');
        $query = Tenant::query();
        if (is_string($tenantOpt) && $tenantOpt !== '') {
            $query->where('id', $tenantOpt);
        }

        foreach ($query->get() as $tenant) {
            $actor = User::query()->where('tenant_id', $tenant->id)->orderByDesc('clearance_rank')->first();
            if (! $actor instanceof User) {
                continue;
            }

            try {
                $res = $dbContext->runAs($actor, static fn (): array => $sync->processOutbox($tenant, $actor));
                $this->info("[SYNC] {$tenant->slug}: synced={$res['synced']} conflict_branched={$res['conflict_branched']} failed={$res['failed']}");
            } catch (AirGapViolationException|\RuntimeException $e) {
                $this->warn("[SYNC SKIPPED] {$tenant->slug}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
