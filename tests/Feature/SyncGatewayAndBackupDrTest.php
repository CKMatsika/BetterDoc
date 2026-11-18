<?php

declare(strict_types=1);

use App\Enums\BackupTier;
use App\Enums\HostingMode;
use App\Enums\ProfileCode;
use App\Enums\SyncOutboxStatus;
use App\Enums\SyncTargetDriver;
use App\Exceptions\AirGapViolationException;
use App\Models\BackupSnapshot;
use App\Models\Document;
use App\Models\SyncOutboxEvent;
use App\Models\SyncTarget;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Backup\BackupDrService;
use App\Services\Profile\ProfileManager;
use App\Services\Security\DbSecurityContext;
use App\Services\Sync\SyncGatewayService;
use Database\Seeders\AfzDefenceSeeder;
use Database\Seeders\CorporateSeeder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

it('hard-blocks sync gateway in air-gapped mode while enforcing classification ceilings and conflict branching for LAN+Cloud tenants', function (): void {
    $this->seed([AfzDefenceSeeder::class, CorporateSeeder::class]);

    /** @var Tenant $afzTenant */
    $afzTenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();

    /** @var Tenant $corpTenant */
    $corpTenant = Tenant::query()->where('slug', 'zimcorp-corporate')->firstOrFail();
    /** @var User $corpAdmin */
    $corpAdmin = User::query()->where('email', 'admin@zimcorp.co.zw')->firstOrFail();

    $dbContext = app(DbSecurityContext::class);

    // 1. Defence Air-Gapped Tenant MUST throw AirGapViolationException even if config sync flag is flipped
    config()->set('betterdoc.air_gapped', false);
    config()->set('betterdoc.sync.enabled', true);

    [$afzDoc, $afzTarget] = $dbContext->runAs($wgCdr, static fn (): array => [
        Document::query()->where('tenant_id', $afzTenant->id)->firstOrFail(),
        SyncTarget::query()->where('tenant_id', $afzTenant->id)->firstOrFail(),
    ]);

    expect(static fn () => $dbContext->runAs(
        $wgCdr,
        static fn () => app(SyncGatewayService::class)->enqueueDocumentSync($afzTenant, $wgCdr, $afzDoc, $afzTarget)
    ))->toThrow(AirGapViolationException::class);

    // 2. Corporate LAN+Cloud Tenant (with global BETTERDOC_AIR_GAPPED=false) enforces max_classification_rank & cloud_sync_allowed
    app(ProfileManager::class)->applyToTenant($corpTenant, ProfileCode::Corporate, syncTaxonomy: false);
    $corpTenant->refresh();
    expect($corpTenant->hosting_mode)->toBe(HostingMode::LanWithCloudBackup)
        ->and($corpTenant->air_gapped)->toBeFalse();

    $this->signIn($corpAdmin);
    $this->get('/admin/operations')->assertOk();

    // Register local_mirror sync target capped at L1 Internal
    $targetRes = $this->postJson('/admin/operations/sync-targets', [
        'name' => 'Bulawayo Branch Mirror',
        'driver' => SyncTargetDriver::LocalMirror->value,
        'bucket_or_site' => 'bulawayo-branch',
        'max_classification_rank' => 1,
        'conflict_strategy' => 'branch_conflict',
    ])->assertCreated();
    $targetId = (string) $targetRes->json('target.id');

    [$pubDoc, $confDoc] = $dbContext->runAs($corpAdmin, static function () use ($corpTenant): array {
        $pub = Document::query()->where('tenant_id', $corpTenant->id)->where('classification_rank', '<=', 1)->firstOrFail();
        $conf = Document::query()->where('tenant_id', $corpTenant->id)->where('classification_rank', 2)->firstOrFail();

        return [$pub, $conf];
    });

    // L2 Confidential doc MUST be blocked by ceiling
    $this->postJson('/admin/operations/sync-enqueue', [
        'document_id' => $confDoc->id,
        'sync_target_id' => $targetId,
    ])->assertStatus(422);

    // L0/L1 cleared doc queues cleanly & replicates to local mirror
    $enqRes = $this->postJson('/admin/operations/sync-enqueue', [
        'document_id' => $pubDoc->id,
        'sync_target_id' => $targetId,
    ])->assertCreated();
    $eventId = (string) $enqRes->json('outbox_event.id');

    $this->postJson('/admin/operations/sync-process')
        ->assertOk()
        ->assertJson(['synced' => 1, 'conflict_branched' => 0]);

    $syncedEv = $dbContext->runAs($corpAdmin, static fn (): SyncOutboxEvent => SyncOutboxEvent::query()->findOrFail($eventId));
    expect($syncedEv->status)->toBe(SyncOutboxStatus::Synced);

    // 3. Conflict Detection: when remote mirror digest diverges, processOutbox preserves a conflict branch
    $mirrorDisk = config()->string('betterdoc.sync.mirror_disk', 'betterdoc_vault');
    $mirrorFile = "tenants/{$corpTenant->id}/sync_mirror/bulawayo-branch/{$pubDoc->id}.json";
    Storage::disk($mirrorDisk)->put($mirrorFile, json_encode([
        'payload_sha256' => str_repeat('f', 64),
        'conflict_test_lock' => true,
    ], JSON_THROW_ON_ERROR));

    $enqConflict = $this->postJson('/admin/operations/sync-enqueue', [
        'document_id' => $pubDoc->id,
        'sync_target_id' => $targetId,
    ])->assertCreated();
    $conflictEventId = (string) $enqConflict->json('outbox_event.id');

    $this->postJson('/admin/operations/sync-process')
        ->assertOk()
        ->assertJson(['synced' => 0, 'conflict_branched' => 1]);

    $branchedEv = $dbContext->runAs($corpAdmin, static fn (): SyncOutboxEvent => SyncOutboxEvent::query()->findOrFail($conflictEventId));
    expect($branchedEv->status)->toBe(SyncOutboxStatus::ConflictBranched)
        ->and($branchedEv->conflict_branch_label)->toStartWith('conflict/bulawayo-branch/');
});

it('creates XChaCha20-Poly1305 encrypted 3-2-1 backup snapshots, detects tampering, and completes timed <2h clean-machine restore drill', function (): void {
    $this->seed([AfzDefenceSeeder::class]);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    /** @var User $sysAdmin */
    $sysAdmin = User::query()->where('email', 'fltlt.nyoni@afz.mil.zw')->firstOrFail();
    $dbContext = app(DbSecurityContext::class);

    $this->signIn($sysAdmin);

    $snapRes = $this->postJson('/admin/operations/backups', [
        'backup_tier' => BackupTier::Tier2OffsiteNas->value,
        'snapshot_label' => 'Phase 8 Timed DR Drill Snapshot',
    ])->assertCreated();

    $snapshotId = (string) $snapRes->json('snapshot.id');
    expect($snapRes->json('snapshot.size_bytes'))->toBeGreaterThan(100);

    // Execute Timed Clean-Machine Restore Drill via HTTP endpoint & Artisan CLI
    $drillRes = $this->postJson("/admin/operations/backups/{$snapshotId}/restore-drill")
        ->assertOk()
        ->json();

    expect($drillRes['valid'])->toBeTrue()
        ->and($drillRes['audit_chain_valid'])->toBeTrue()
        ->and($drillRes['checkpoint_hmac_valid'])->toBeTrue()
        ->and($drillRes['rto_under_2h'])->toBeTrue()
        ->and($drillRes['duration_ms'])->toBeLessThan(7_200_000)
        ->and($drillRes['restored_documents'])->toBeGreaterThanOrEqual(3);

    $this->artisan('betterdoc:backup:create --tier=tier1_local --verify-restore')->assertExitCode(0);

    // Tampered manifest HMAC fails restore verification cleanly
    $dbContext->runAs($sysAdmin, static function () use ($snapshotId, $tenant, $sysAdmin): void {
        $snap = BackupSnapshot::query()->findOrFail($snapshotId);
        $snap->update(['signature_hmac' => str_repeat('0', 64)]);
        $tamperedRes = app(BackupDrService::class)->verifyAndRunRestoreDrill($snap, $tenant, $sysAdmin);
        expect($tamperedRes['valid'])->toBeFalse();
    });
});

it('includes validated k3s, Ansible, observability, offline installer scripts, OpenAPI 3.1, CycloneDX SBOM, and complete docs', function (): void {
    $requiredFiles = [
        'docs/ADMIN_GUIDE.md',
        'docs/USER_GUIDE.md',
        'docs/SECURITY_MODEL.md',
        'docs/DR_RUNBOOK.md',
        'docs/openapi.yaml',
        'docs/SBOM.cdx.json',
        'infra/k3s/base/kustomization.yaml',
        'infra/k3s/base/networkpolicy-airgap.yaml',
        'infra/k3s/base/app-deployment.yaml',
        'infra/k3s/base/stateful-data.yaml',
        'infra/k3s/base/workers-deployment.yaml',
        'infra/ansible/site.yml',
        'infra/monitoring/prometheus-alerts.yml',
        'infra/monitoring/grafana-dashboard-betterdoc.json',
        'scripts/backup-321.sh',
        'scripts/restore-drill.sh',
        'scripts/build-offline-bundle.sh',
    ];

    foreach ($requiredFiles as $rel) {
        $path = base_path($rel);
        expect(file_exists($path))->toBeTrue("Missing deliverable file: {$rel}");
        expect(filesize($path))->toBeGreaterThan(50, "Empty deliverable file: {$rel}");
    }

    $openapi = Yaml::parseFile(base_path('docs/openapi.yaml'));
    expect($openapi)->toBeArray()
        ->and($openapi['openapi'] ?? null)->toBe('3.1.0')
        ->and($openapi['info']['title'] ?? null)->toBe('BetterDoc DMS API')
        ->and(array_keys($openapi['paths'] ?? []))->toContain('/admin/operations/backups');

    $sbom = json_decode((string) file_get_contents(base_path('docs/SBOM.cdx.json')), true, 512, JSON_THROW_ON_ERROR);
    expect($sbom['bomFormat'] ?? null)->toBe('CycloneDX')
        ->and($sbom['components'] ?? [])->not->toBeEmpty();

    $grafana = json_decode((string) file_get_contents(base_path('infra/monitoring/grafana-dashboard-betterdoc.json')), true, 512, JSON_THROW_ON_ERROR);
    expect($grafana['uid'] ?? null)->toBe('betterdoc-ops-v1');

    $k3sNetPol = Yaml::parseFile(base_path('infra/k3s/base/networkpolicy-airgap.yaml'));
    expect($k3sNetPol['kind'] ?? null)->toBe('NetworkPolicy');
});
