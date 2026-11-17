<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CertificateCustodyMode;
use App\Enums\DocumentStatus;
use App\Enums\HostingMode;
use App\Enums\ProfileCode;
use App\Enums\SentinelRole;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Compartment;
use App\Models\Document;
use App\Models\Folder;
use App\Models\OrgUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Auth\TotpMfaService;
use App\Services\Pki\InternalCaService;
use App\Services\Profile\ProfileManager;
use App\Services\Security\DbSecurityContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CorporateSeeder extends Seeder
{
    public function run(): void
    {
        $demoPassword = config()->string('sentinel.demo_password', 'SentinelDemo!2026');
        $profiles = app(ProfileManager::class);
        $audit = app(ImmutableAuditLogger::class);
        $totp = app(TotpMfaService::class);
        $dbContext = app(DbSecurityContext::class);

        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'zimcorp-corporate'],
            [
                'name' => 'ZimCorp Commercial Holdings',
                'hosting_mode' => HostingMode::LanWithCloudBackup->value,
                'air_gapped' => (bool) config('sentinel.air_gapped', true),
                'active_profile_code' => ProfileCode::Corporate,
            ]
        );

        $profiles->applyToTenant($tenant, ProfileCode::Corporate, syncTaxonomy: true);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        foreach (SentinelRole::values() as $roleName) {
            Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
            ]);
        }

        $hq = OrgUnit::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'ZC-HQ'],
            ['name' => 'Group Head Office — Harare', 'unit_type' => 'headquarters', 'path' => 'zc']
        );
        $fin = OrgUnit::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'ZC-FIN'],
            ['parent_id' => $hq->id, 'name' => 'Finance & Procurement', 'unit_type' => 'department', 'path' => 'zc.fin']
        );
        $ops = OrgUnit::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'ZC-OPS'],
            ['parent_id' => $hq->id, 'name' => 'Commercial Operations', 'unit_type' => 'department', 'path' => 'zc.ops']
        );

        /** @var array<int, ClearanceLevel> $levels */
        $levels = ClearanceLevel::query()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('rank')
            ->all();

        /** @var array<string, Compartment> $compartments */
        $compartments = Compartment::query()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('code')
            ->all();

        $createUser = function (
            string $email,
            string $name,
            string $serviceNo,
            string $rankTitle,
            OrgUnit $unit,
            int $rank,
            SentinelRole $role,
            array $compCodes,
        ) use ($tenant, $levels, $compartments, $demoPassword, $totp): User {
            $level = $levels[$rank];
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'tenant_id' => $tenant->id,
                    'org_unit_id' => $unit->id,
                    'clearance_level_id' => $level->id,
                    'clearance_rank' => $level->rank,
                    'service_number' => $serviceNo,
                    'rank_title' => $rankTitle,
                    'name' => $name,
                    'password' => Hash::make($demoPassword),
                    'mfa_enforced' => false,
                    'two_factor_secret' => Crypt::encryptString($totp->generateSecret()),
                    'two_factor_recovery_codes' => Crypt::encryptString(json_encode($totp->generateRecoveryCodes(), JSON_THROW_ON_ERROR)),
                    'two_factor_confirmed_at' => now(),
                ]
            );

            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
            $user->syncRoles([$role->value]);

            $compIds = [];
            foreach ($compCodes as $code) {
                if (is_string($code) && isset($compartments[$code])) {
                    $compIds[] = $compartments[$code]->id;
                }
            }
            $user->compartments()->sync($compIds);

            return $user;
        };

        $sysAdmin = $createUser('admin@zimcorp.co.zw', 'K. Chitando', 'ZC-0001', 'IT & Systems Admin', $hq, 2, SentinelRole::SystemAdmin, ['EXEC', 'FINANCE']);
        $director = $createUser('director@zimcorp.co.zw', 'R. Makoni', 'ZC-0010', 'Finance Director', $fin, 2, SentinelRole::Approver, ['EXEC', 'FINANCE']);
        $opsLead = $createUser('ops.lead@zimcorp.co.zw', 'P. Dube', 'ZC-0102', 'Operations Manager', $ops, 1, SentinelRole::RecordsOfficer, ['FINANCE']);
        $clerk = $createUser('clerk@zimcorp.co.zw', 'M. Mupfumi', 'ZC-0215', 'Procurement Officer', $fin, 1, SentinelRole::Author, []);

        $dbContext->runAs($director, function () use ($tenant, $hq, $fin, $ops, $levels, $compartments, $audit, $dbContext, $director, $opsLead, $clerk): void {
            $cabExec = Cabinet::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Executive & Board Vault'],
                ['org_unit_id' => $hq->id, 'description' => 'Board packs, M&A diligence, and executive governance records.', 'default_classification_rank' => 2]
            );
            $cabCommercial = Cabinet::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Procurement & Commercial Contracts'],
                ['org_unit_id' => $fin->id, 'description' => 'Vendor contracts, purchase orders, and ZIMRA AP tax packs.', 'default_classification_rank' => 1]
            );

            $folderBoard = Folder::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'cabinet_id' => $cabExec->id, 'name' => '2026 Board Packs'],
                ['org_unit_id' => $hq->id, 'path' => 'zc.hq.board', 'default_classification_rank' => 2]
            );
            $folderVendors = Folder::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'cabinet_id' => $cabCommercial->id, 'name' => 'Vendor Invoices & SLAs'],
                ['org_unit_id' => $fin->id, 'path' => 'zc.fin.vendors', 'default_classification_rank' => 1]
            );

            $seedDoc = function (
                string $docNumber,
                string $title,
                string $summary,
                Cabinet $cabinet,
                Folder $folder,
                OrgUnit $unit,
                User $owner,
                int $rank,
                array $compCodes,
            ) use ($tenant, $levels, $compartments, $audit, $dbContext): void {
                $dbContext->runAs($owner, function () use ($docNumber, $title, $summary, $cabinet, $folder, $unit, $owner, $rank, $compCodes, $tenant, $levels, $compartments, $audit): void {
                    $doc = Document::query()->firstOrCreate(
                        ['tenant_id' => $tenant->id, 'doc_number' => $docNumber],
                        [
                            'cabinet_id' => $cabinet->id,
                            'folder_id' => $folder->id,
                            'org_unit_id' => $unit->id,
                            'owner_id' => $owner->id,
                            'classification_level_id' => $levels[$rank]->id,
                            'classification_rank' => $rank,
                            'title' => $title,
                            'summary' => $summary,
                            'status' => DocumentStatus::Active,
                            'unit_wide_readable' => true,
                        ]
                    );

                    $compIds = [];
                    foreach ($compCodes as $code) {
                        if (is_string($code) && isset($compartments[$code])) {
                            $compIds[] = $compartments[$code]->id;
                        }
                    }
                    $doc->compartments()->sync($compIds);

                    SeedPdfHelper::attachInitialPdfIfMissing($tenant, $doc, $docNumber, $title, $summary, $rank, $owner);

                    $audit->log(
                        tenantId: $tenant->id,
                        eventType: 'document.seeded',
                        resourceType: 'document',
                        resourceId: $doc->id,
                        actor: $owner,
                        classificationRank: $rank,
                        after: ['doc_number' => $docNumber, 'title' => $title],
                    );
                });
            };

            $seedDoc('ZC/PUB/2026/001', 'ZimCorp Supplier Onboarding & Ethics Charter 2026', 'Published corporate procurement standards and vendor onboarding checklist.', $cabCommercial, $folderVendors, $ops, $clerk, 0, []);
            $seedDoc('ZC/FIN/2026/042', 'Q4 Vendor Payable Batch — Harare Logistics Ltd', 'Matched 3-way invoice batch and ZIMRA tax clearance verification.', $cabCommercial, $folderVendors, $fin, $opsLead, 1, ['FINANCE']);
            $seedDoc('ZC/CON/2026/108', 'Annual Commercial Fleet Maintenance Agreement', 'Signed service-level schedule for regional delivery fleet maintenance.', $cabCommercial, $folderVendors, $ops, $opsLead, 1, []);
            $seedDoc('ZC/EXEC/2026/009', 'Board Acquisition & Capex Allocation Memorandum Q4', 'Confidential executive capex review and warehouse expansion budget.', $cabExec, $folderBoard, $hq, $director, 2, ['EXEC']);

            SeedPhase4DefaultsHelper::seedDefaults($tenant, $director);
        });

        if (! app()->runningUnitTests()) {
            $dbContext->runAs($sysAdmin, function () use ($tenant, $sysAdmin, $director, $opsLead): void {
                $caService = app(InternalCaService::class);
                $caService->ensureBootstrapped($tenant, $sysAdmin);
                foreach ([$sysAdmin, $director, $opsLead] as $certHolder) {
                    if ($certHolder->certificates()->doesntExist()) {
                        $caService->issueUserCertificate($certHolder, CertificateCustodyMode::HsmManaged, $sysAdmin);
                    }
                }
            });
        }

        $audit->createCheckpoint($tenant);
    }
}
