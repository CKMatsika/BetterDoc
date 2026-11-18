<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SentinelRole;
use App\Models\ScanSource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PrintScan\ScanIngestionService;
use App\Services\Security\DbSecurityContext;
use Illuminate\Console\Command;

class PollScanSources extends Command
{
    protected $signature = 'sentinel:scan:poll {--tenant= : Optional tenant UUID to scope scan polling}';

    protected $description = 'Poll active LAN hot folders, local mail-in dropboxes, and eSCL/AirScan scanners to split & catalog batches';

    public function handle(ScanIngestionService $ingestion, DbSecurityContext $dbContext): int
    {
        $tenantOpt = $this->option('tenant');
        $query = Tenant::query();
        if (is_string($tenantOpt) && $tenantOpt !== '') {
            $query->where('id', $tenantOpt);
        }

        $totalDocs = 0;

        foreach ($query->get() as $tenant) {
            $actor = User::query()
                ->where('tenant_id', $tenant->id)
                ->whereHas('roles', static function ($q): void {
                    $q->whereIn('name', [SentinelRole::RecordsOfficer->value, SentinelRole::SystemAdmin->value]);
                })
                ->first() ?? User::query()->where('tenant_id', $tenant->id)->first();

            if (! $actor instanceof User) {
                continue;
            }

            $docsCreated = $dbContext->runAs($actor, static function () use ($tenant, $actor, $ingestion): int {
                $sources = ScanSource::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('is_active', true)
                    ->get();

                $count = 0;
                foreach ($sources as $source) {
                    $batches = $ingestion->pollSource($source, $actor);
                    foreach ($batches as $batch) {
                        $count += $batch->split_documents_count;
                    }
                }

                return $count;
            });

            $totalDocs += $docsCreated;
        }

        $this->info("Scan polling completed: {$totalDocs} document(s) ingested.");

        return self::SUCCESS;
    }
}
