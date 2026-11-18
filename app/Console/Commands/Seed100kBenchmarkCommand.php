<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Seed100kBenchmarkCommand extends Command
{
    protected $signature = 'betterdoc:benchmark:seed-100k {--count=100000 : Total synthetic documents to seed}';

    protected $description = 'Seed 100,000 synthetic documents into PostgreSQL to benchmark <1s search & RLS isolation.';

    public function handle(): int
    {
        $count = max(1000, (int) $this->option('count'));
        $tenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
        $owner = User::query()->where('email', 'avm.chikwanda@afz.mil.zw')->firstOrFail();
        $cabinet = Cabinet::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        /** @var array<int, string> $levelIds */
        $levelIds = ClearanceLevel::query()
            ->where('tenant_id', $tenant->id)
            ->pluck('id', 'rank')
            ->all();

        $batchSize = 2000;
        $now = now()->toDateTimeString();
        $conn = DB::connection('pgsql_admin');

        $this->info("Seeding {$count} synthetic records into {$tenant->slug}...");
        $started = microtime(true);

        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rank = $i % 5;
            $levelId = $levelIds[$rank] ?? reset($levelIds);
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant->id,
                'cabinet_id' => $cabinet->id,
                'folder_id' => null,
                'org_unit_id' => $owner->org_unit_id,
                'owner_id' => $owner->id,
                'classification_level_id' => $levelId,
                'classification_rank' => $rank,
                'doc_number' => sprintf('BENCH/%06d', $i),
                'title' => "Synthetic Logistics & Airspace Record #{$i} Sector ".($i % 17),
                'summary' => "Routine operational log entry {$i} for automated archive benchmark.",
                'ocr_text' => "archive batch {$i} squadron readiness telemetry maintenance",
                'status' => 'active',
                'unit_wide_readable' => true,
                'legal_hold_active' => false,
                'is_worm' => false,
                'client_e2ee' => false,
                'hr_restricted_from_subject' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= $batchSize) {
                $conn->table('documents')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $conn->table('documents')->insert($rows);
        }

        $elapsed = round(microtime(true) - $started, 2);
        $this->info("Seeded {$count} records in {$elapsed}s.");

        return self::SUCCESS;
    }
}
