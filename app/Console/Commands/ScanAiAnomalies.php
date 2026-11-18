<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Ai\AnomalyDetectionService;
use App\Services\Security\DbSecurityContext;
use Illuminate\Console\Command;

class ScanAiAnomalies extends Command
{
    protected $signature = 'betterdoc:ai:scan-anomalies {--tenant= : Optional tenant UUID to scope scan}';

    protected $description = 'Scan audit ledger and IDP records for bulk downloads, after-hours Secret+ access, and duplicate invoice anomalies';

    public function handle(AnomalyDetectionService $detector, DbSecurityContext $dbContext): int
    {
        $tenantOpt = $this->option('tenant');
        $query = Tenant::query();
        if (is_string($tenantOpt) && $tenantOpt !== '') {
            $query->where('id', $tenantOpt);
        }

        $totalAlerts = 0;
        foreach ($query->get() as $tenant) {
            $alerts = $dbContext->runForTenant($tenant->id, static fn (): array => $detector->scanTenant($tenant->id));
            $totalAlerts += count($alerts);
        }

        $this->info("Anomaly scan completed: {$totalAlerts} new alert(s) raised.");

        return self::SUCCESS;
    }
}
