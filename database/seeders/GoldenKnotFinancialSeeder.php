<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BetterDocRole;
use App\Enums\CertificateCustodyMode;
use App\Enums\DocumentStatus;
use App\Enums\HostingMode;
use App\Enums\ProfileCode;
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

class GoldenKnotFinancialSeeder extends Seeder
{
    public function run(): void
    {
        $demoPassword = config()->string('betterdoc.demo_password', 'BetterDocDemo!2026');
        $profiles = app(ProfileManager::class);
        $audit = app(ImmutableAuditLogger::class);
        $totp = app(TotpMfaService::class);
        $dbContext = app(DbSecurityContext::class);

        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'golden-knot'],
            [
                'name' => 'Golden Knot Financial Holdings',
                'hosting_mode' => HostingMode::LanWithCloudBackup->value,
                'air_gapped' => (bool) config('betterdoc.air_gapped', true),
                'active_profile_code' => ProfileCode::Financial,
            ]
        );

        $profiles->applyToTenant($tenant, ProfileCode::Financial, syncTaxonomy: true);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        foreach (BetterDocRole::values() as $roleName) {
            Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
            ]);
        }

        $hq = OrgUnit::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'GK-HQ'],
            ['name' => 'Head Office Harare', 'unit_type' => 'headquarters', 'path' => 'gk']
        );
        $region = OrgUnit::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'GK-HRE'],
            ['parent_id' => $hq->id, 'name' => 'Harare Metro Region', 'unit_type' => 'region', 'path' => 'gk.hre']
        );
        $branch = OrgUnit::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'GK-BRW'],
            ['parent_id' => $region->id, 'name' => 'Borrowdale Branch', 'unit_type' => 'branch', 'path' => 'gk.hre.brw']
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
            BetterDocRole $role,
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
                    'mfa_enforced' => true,
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

        $sysAdmin = $createUser('sysadmin@golden-knot.co.zw', 'I. Ncube', 'GK-0005', 'IT Security Lead', $hq, 2, BetterDocRole::SystemAdmin, ['KYC_AML', 'CREDIT', 'TREASURY', 'HR']);
        $cro = $createUser('cro.gumbo@golden-knot.co.zw', 'N. Gumbo', 'GK-0010', 'Chief Risk Officer', $hq, 3, BetterDocRole::Approver, ['KYC_AML', 'CREDIT', 'TREASURY', 'HR']);
        $branchMgr = $createUser('mgr.mutasa@golden-knot.co.zw', 'S. Mutasa', 'GK-0104', 'Branch Manager', $branch, 2, BetterDocRole::RecordsOfficer, ['KYC_AML', 'CREDIT']);
        $analyst = $createUser('analyst.chigumba@golden-knot.co.zw', 'T. Chigumba', 'GK-0412', 'Credit Analyst', $branch, 1, BetterDocRole::Author, []);

        $dbContext->runAs($cro, function () use ($tenant, $branch, $hq, $levels, $compartments, $audit, $dbContext, $cro, $branchMgr, $analyst): void {
            $cabLoans = Cabinet::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Credit & KYC Dossiers'],
                ['org_unit_id' => $branch->id, 'description' => 'SME loan origination and RBZ AML KYC packs.', 'default_classification_rank' => 1]
            );
            $cabTreasury = Cabinet::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Treasury & ZIMRA Fiscal Vault'],
                ['org_unit_id' => $hq->id, 'description' => 'AP invoices, tax clearance, and treasury mandates.', 'default_classification_rank' => 2]
            );

            $folderKyc = Folder::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'cabinet_id' => $cabLoans->id, 'name' => '2026 Corporate Borrowers'],
                ['org_unit_id' => $branch->id, 'path' => 'gk.hre.brw.kyc', 'default_classification_rank' => 1]
            );
            $folderAml = Folder::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'cabinet_id' => $cabTreasury->id, 'name' => 'FIU & AML Filings'],
                ['org_unit_id' => $hq->id, 'path' => 'gk.hq.aml', 'default_classification_rank' => 3]
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
                ?User $hrSubject = null,
                bool $hrRestricted = false,
            ) use ($tenant, $levels, $compartments, $audit, $dbContext): void {
                $dbContext->runAs($owner, function () use ($docNumber, $title, $summary, $cabinet, $folder, $unit, $owner, $rank, $compCodes, $hrSubject, $hrRestricted, $tenant, $levels, $compartments, $audit): void {
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
                            'hr_subject_user_id' => $hrSubject?->id,
                            'hr_restricted_from_subject' => $hrRestricted,
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

            $seedDoc('GK/PUB/2026/001', 'SME Microfinance Schedule of Charges Q4 2026', 'Published customer fee schedule compliant with RBZ guidelines.', $cabLoans, $folderKyc, $branch, $analyst, 0, []);
            $seedDoc('GK/CRD/2026/210', 'Mashonaland Agri-Inputs Facility Appraisal', 'Internal credit scoring memo and collateral schedule.', $cabLoans, $folderKyc, $branch, $branchMgr, 2, ['CREDIT']);
            $seedDoc('GK/AML/2026/007', 'STR Filing — Cross-Border Remittance Anomaly', 'Restricted Financial Intelligence Unit suspicious transaction filing.', $cabTreasury, $folderAml, $hq, $cro, 3, ['KYC_AML']);
            $seedDoc('GK/HR/2026/044', 'Confidential HR Inquiry — Credit Policy Deviation', 'Restricted HR review shielded from the subject employee.', $cabTreasury, $folderAml, $branch, $cro, 2, ['HR'], $analyst, true);

            SeedPhase4DefaultsHelper::seedDefaults($tenant, $cro);
            SeedPhase5AiDefaultsHelper::seedDefaults($tenant, $cro);
            SeedPhase6PrintScanDefaultsHelper::seedDefaults($tenant, $cro);
            SeedPhase7DefaultsHelper::seedDefaults($tenant, $cro);
            SeedPhase8OperationsHelper::seedDefaults($tenant, $cro);
            SeedBrandingHelper::seedDefaults($tenant);
        });

        if (! app()->runningUnitTests()) {
            $dbContext->runAs($sysAdmin, function () use ($tenant, $sysAdmin, $cro, $branchMgr): void {
                $caService = app(InternalCaService::class);
                $caService->ensureBootstrapped($tenant, $sysAdmin);
                foreach ([$sysAdmin, $cro, $branchMgr] as $certHolder) {
                    if ($certHolder->certificates()->doesntExist()) {
                        $caService->issueUserCertificate($certHolder, CertificateCustodyMode::HsmManaged, $sysAdmin);
                    }
                }
            });
        }

        $audit->createCheckpoint($tenant);
    }
}
