<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ZimraFiscalStatus;
use App\Models\ApInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Finance\ApAutomationService;
use App\Services\Security\DbSecurityContext;
use Illuminate\Console\Command;

class SyncZimraFiscalQueue extends Command
{
    protected $signature = 'betterdoc:zimra:sync-fiscal {--tenant= : Optional tenant UUID to sync offline ZIMRA FDMS queue}';

    protected $description = 'Replay queued_offline AP invoices cleanly against ZIMRA FDMS endpoint when LAN/connectivity is active';

    public function handle(ApAutomationService $ap, DbSecurityContext $dbContext): int
    {
        $tenantOpt = $this->option('tenant');
        $query = Tenant::query();
        if (is_string($tenantOpt) && $tenantOpt !== '') {
            $query->where('id', $tenantOpt);
        }

        $synced = 0;
        foreach ($query->get() as $tenant) {
            $actor = User::query()->where('tenant_id', $tenant->id)->orderByDesc('clearance_rank')->first();
            if (! $actor instanceof User) {
                continue;
            }

            $count = $dbContext->runAs($actor, static function () use ($tenant, $actor, $ap): int {
                $pending = ApInvoice::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('zimra_fiscal_status', ZimraFiscalStatus::QueuedOffline->value)
                    ->limit(50)
                    ->get();

                $ok = 0;
                foreach ($pending as $inv) {
                    $res = $ap->fiscalizeZimra($inv, $actor);
                    if ($res['status'] === ZimraFiscalStatus::Fiscalized) {
                        $ok++;
                    }
                }

                return $ok;
            });

            $synced += $count;
        }

        $this->info("ZIMRA FDMS queue sync finished: {$synced} invoice(s) fiscalized.");

        return self::SUCCESS;
    }
}
