<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BackupTier;
use App\Enums\SyncTargetDriver;
use App\Models\BackupSnapshot;
use App\Models\SyncTarget;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Backup\BackupDrService;
use App\Services\Security\DbSecurityContext;

class SeedPhase8OperationsHelper
{
    public static function seedDefaults(Tenant $tenant, User $actor): void
    {
        app(DbSecurityContext::class)->runAs($actor, static function () use ($tenant, $actor): void {
            SyncTarget::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Secondary DR Site Replica'],
                [
                    'driver' => SyncTargetDriver::LocalMirror,
                    'endpoint_url' => null,
                    'bucket_or_site' => $tenant->slug.'-dr-harare',
                    'max_classification_rank' => $tenant->air_gapped ? 0 : 1,
                    'conflict_strategy' => 'branch_conflict',
                    'enabled' => ! $tenant->air_gapped,
                ]
            );

            if (! BackupSnapshot::query()->where('tenant_id', $tenant->id)->exists()) {
                $snapshot = app(BackupDrService::class)->createEncryptedSnapshot(
                    tenant: $tenant,
                    actor: $actor,
                    tier: BackupTier::Tier1Local,
                    label: "Baseline Tier-1 Snapshot — {$tenant->slug}",
                );

                app(BackupDrService::class)->verifyAndRunRestoreDrill($snapshot, $tenant, $actor);
            }
        });
    }
}
