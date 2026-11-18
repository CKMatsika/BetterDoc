<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Contracts\ContractLifecycleService;
use App\Services\Security\DbSecurityContext;
use Illuminate\Console\Command;

class ScanContractAlerts extends Command
{
    protected $signature = 'betterdoc:contracts:scan-alerts {--tenant= : Optional tenant UUID to scope check}';

    protected $description = 'Scan contract expiry windows and overdue obligations and notify assigned owners';

    public function handle(ContractLifecycleService $contracts, DbSecurityContext $dbContext): int
    {
        $tenantOpt = $this->option('tenant');
        $query = Tenant::query();
        if (is_string($tenantOpt) && $tenantOpt !== '') {
            $query->where('id', $tenantOpt);
        }

        $totalExpiring = 0;
        foreach ($query->get() as $tenant) {
            $actor = User::query()
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('clearance_rank')
                ->first();
            if (! $actor instanceof User) {
                continue;
            }

            $res = $dbContext->runAs($actor, static fn (): array => $contracts->scanAlerts($tenant->id));
            $totalExpiring += $res['expiring_soon'] + $res['expired'] + $res['overdue_obligations'];
        }

        $this->info("Contract lifecycle scan completed: {$totalExpiring} alert(s) processed.");

        return self::SUCCESS;
    }
}
