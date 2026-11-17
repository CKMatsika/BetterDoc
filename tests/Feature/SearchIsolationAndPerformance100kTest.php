<?php

declare(strict_types=1);

use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Compartment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Search\DocumentSearchService;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('searches 100,000 real PostgreSQL documents under 1 second with zero restricted hits, counts, or autocomplete leakage', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    /** @var User $avm */
    $avm = User::query()->where('email', 'avm.chikwanda@afz.mil.zw')->firstOrFail();
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail();
    $dbContext = app(DbSecurityContext::class);

    /** @var array<int, string> $levelIds */
    $levelIds = ClearanceLevel::query()->where('tenant_id', $tenant->id)->pluck('id', 'rank')->all();
    $opsComp = Compartment::query()->where('tenant_id', $tenant->id)->where('code', 'OPS')->firstOrFail();

    $dbContext->runAs($avm, function () use ($tenant, $avm, $levelIds, $opsComp): void {
        $cab = Cabinet::query()->where('name', 'Administrative Orders')->firstOrFail();
        $now = now()->toDateTimeString();

        // Bulk insert 100,000 documents inside the test transaction in 2,500-row chunks
        $rows = [];
        for ($i = 1; $i <= 100000; $i++) {
            $rank = $i % 5;
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant->id,
                'cabinet_id' => $cab->id,
                'folder_id' => null,
                'org_unit_id' => $avm->org_unit_id,
                'owner_id' => $avm->id,
                'classification_level_id' => $levelIds[$rank],
                'classification_rank' => $rank,
                'doc_number' => sprintf('B100K/%06d', $i),
                'title' => "Benchmark Squadron Record {$i}",
                'summary' => "Routine operational log {$i}",
                'ocr_text' => "archive telemetry log batch {$i}",
                'status' => 'active',
                'unit_wide_readable' => true,
                'legal_hold_active' => false,
                'is_worm' => false,
                'client_e2ee' => false,
                'hr_restricted_from_subject' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 2500) {
                DB::table('documents')->insert($rows);
                $rows = [];
            }
        }

        // Insert 1 Secret needle owned by AVM + compartment link
        $secretId = (string) Str::uuid7();
        DB::table('documents')->insert([
            'id' => $secretId,
            'tenant_id' => $tenant->id,
            'cabinet_id' => $cab->id,
            'folder_id' => null,
            'org_unit_id' => $avm->org_unit_id,
            'owner_id' => $avm->id,
            'classification_level_id' => $levelIds[3],
            'classification_rank' => 3,
            'doc_number' => 'AFZ/COBALT/001',
            'title' => 'ProjectCobaltRaven Interceptor Deployment',
            'summary' => 'Secret radar positioning directive.',
            'ocr_text' => 'ProjectCobaltRaven classified frequency assignment',
            'status' => 'active',
            'unit_wide_readable' => true,
            'legal_hold_active' => false,
            'is_worm' => false,
            'client_e2ee' => false,
            'hr_restricted_from_subject' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('document_compartments')->insert([
            'document_id' => $secretId,
            'compartment_id' => $opsComp->id,
        ]);
    });

    // Insert 1 Unclassified needle as Corporal (PostgreSQL RLS enforces owner_id = current user on INSERT)
    $dbContext->runAs($cpl, function () use ($tenant, $cpl, $levelIds): void {
        $cab = Cabinet::query()->where('name', 'Administrative Orders')->firstOrFail();
        $now = now()->toDateTimeString();
        DB::table('documents')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'cabinet_id' => $cab->id,
            'folder_id' => null,
            'org_unit_id' => $cpl->org_unit_id,
            'owner_id' => $cpl->id,
            'classification_level_id' => $levelIds[0],
            'classification_rank' => 0,
            'doc_number' => 'AFZ/PUB/999',
            'title' => 'CanteenMenuSchedule November',
            'summary' => 'Public mess hall schedule.',
            'ocr_text' => 'CanteenMenuSchedule breakfast lunch dinner',
            'status' => 'active',
            'unit_wide_readable' => true,
            'legal_hold_active' => false,
            'is_worm' => false,
            'client_e2ee' => false,
            'hr_restricted_from_subject' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    });

    $searchService = app(DocumentSearchService::class);

    // 1. Cleared AVM finds Secret needle across 100k+ dataset in < 1000ms
    $avmResult = $dbContext->runAs($avm, fn (): array => $searchService->search($avm, 'ProjectCobaltRaven'));
    expect($avmResult['total'])->toBe(1)
        ->and($avmResult['hits'][0]['doc_number'])->toBe('AFZ/COBALT/001')
        ->and($avmResult['elapsed_ms'])->toBeLessThan(1000.0);

    // 2. Lower-cleared Corporal gets ZERO hits, ZERO facets, and ZERO autocomplete suggestions for Secret needle
    $cplSecretResult = $dbContext->runAs($cpl, fn (): array => $searchService->search($cpl, 'ProjectCobaltRaven'));
    $cplAutocomplete = $dbContext->runAs($cpl, fn (): array => $searchService->autocomplete($cpl, 'ProjectCobalt'));
    expect($cplSecretResult['total'])->toBe(0)
        ->and($cplSecretResult['facets'])->toBe([])
        ->and($cplAutocomplete)->toBe([])
        ->and($cplSecretResult['elapsed_ms'])->toBeLessThan(1000.0);

    // 3. Lower-cleared Corporal still finds Public needle quickly across 100k+ rows
    $cplPublicResult = $dbContext->runAs($cpl, fn (): array => $searchService->search($cpl, 'CanteenMenuSchedule'));
    expect($cplPublicResult['total'])->toBe(1)
        ->and($cplPublicResult['elapsed_ms'])->toBeLessThan(1000.0);
});
