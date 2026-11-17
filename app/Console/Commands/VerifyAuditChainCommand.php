<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Audit\ImmutableAuditLogger;
use Illuminate\Console\Command;

class VerifyAuditChainCommand extends Command
{
    protected $signature = 'sentinel:audit:verify {--tenant= : Optional tenant slug to verify}';

    protected $description = 'Verify SHA-256 cryptographic hash-chain integrity of the immutable audit ledger.';

    public function handle(ImmutableAuditLogger $audit): int
    {
        $slug = $this->option('tenant');
        $tenants = $slug !== null
            ? Tenant::query()->where('slug', (string) $slug)->get()
            : Tenant::query()->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found to verify.');

            return self::SUCCESS;
        }

        $hasFailures = false;

        foreach ($tenants as $tenant) {
            $result = $audit->verifyChain($tenant);
            if ($result['valid']) {
                $this->info(sprintf(
                    '[PASS] %s (%s): %d events verified. Head: %s',
                    $tenant->name,
                    $tenant->slug,
                    $result['total_events'],
                    $result['head_hash'] ?? 'GENESIS'
                ));
            } else {
                $hasFailures = true;
                $this->error(sprintf(
                    '[FAIL] %s (%s): %s',
                    $tenant->name,
                    $tenant->slug,
                    $result['reason'] ?? 'Chain verification failed'
                ));
            }
        }

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }
}
