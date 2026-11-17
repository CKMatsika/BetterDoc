<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EnsureAuditPartitionsCommand extends Command
{
    protected $signature = 'sentinel:audit:ensure-partitions {--months=12 : Number of future months to pre-create}';

    protected $description = 'Ensure monthly declarative PostgreSQL partitions exist for audit_events.';

    public function handle(): int
    {
        $conn = DB::connection('pgsql_admin');
        if ($conn->getDriverName() !== 'pgsql') {
            $this->info('Partition management applies to PostgreSQL only.');

            return self::SUCCESS;
        }

        $months = max(1, (int) $this->option('months'));
        $start = Carbon::now('UTC')->startOfMonth();

        for ($i = 0; $i <= $months; $i++) {
            $from = $start->copy()->addMonths($i);
            $to = $from->copy()->addMonth();
            $suffix = $from->format('Y_m');

            $conn->statement(sprintf(
                "CREATE TABLE IF NOT EXISTS audit_events_%s PARTITION OF audit_events FOR VALUES FROM ('%s') TO ('%s')",
                $suffix,
                $from->format('Y-m-d H:i:s+00'),
                $to->format('Y-m-d H:i:s+00')
            ));
        }

        $this->info("Ensured {$months} future monthly audit_events partitions.");

        return self::SUCCESS;
    }
}
