<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BackupTier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Backup\BackupDrService;
use App\Services\Security\DbSecurityContext;
use Illuminate\Console\Command;

class RunBackupCommand extends Command
{
    protected $signature = 'betterdoc:backup:create {--tenant= : Optional tenant UUID} {--tier=tier1_local : 3-2-1 backup tier} {--verify-restore : Execute timed clean-machine restore verification drill}';

    protected $description = 'Create encrypted 3-2-1 tenant backups and optionally execute timed <2h restore verification drill';

    public function handle(BackupDrService $backups, DbSecurityContext $dbContext): int
    {
        $tierStr = $this->option('tier');
        $tier = is_string($tierStr) ? (BackupTier::tryFrom($tierStr) ?? BackupTier::Tier1Local) : BackupTier::Tier1Local;
        $verifyRestore = (bool) $this->option('verify-restore');

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

            $result = $dbContext->runAs($actor, static function () use ($backups, $tenant, $actor, $tier, $verifyRestore): array {
                $snapshot = $backups->createEncryptedSnapshot($tenant, $actor, $tier);
                $drill = $verifyRestore ? $backups->verifyAndRunRestoreDrill($snapshot, $tenant, $actor) : null;

                return ['snapshot' => $snapshot, 'drill' => $drill];
            });

            $snap = $result['snapshot'];
            $this->info("[BACKUP] {$tenant->slug}: snapshot {$snap->id} ({$snap->size_bytes} bytes)");

            if (is_array($result['drill'])) {
                $d = $result['drill'];
                $status = $d['valid'] ? 'PASS' : 'FAIL';
                $this->line("[RESTORE-DRILL:{$status}] docs={$d['restored_documents']} blobs={$d['restored_blobs']} duration_ms={$d['duration_ms']} rto_under_2h=".($d['rto_under_2h'] ? 'yes' : 'no'));
                if (! $d['valid']) {
                    return self::FAILURE;
                }
            }
        }

        return self::SUCCESS;
    }
}
