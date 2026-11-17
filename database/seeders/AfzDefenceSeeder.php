<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CertificateCustodyMode;
use App\Enums\CorrespondenceDirection;
use App\Enums\DocumentStatus;
use App\Enums\ProfileCode;
use App\Enums\SentinelRole;
use App\Enums\TwoPersonActionType;
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
use App\Services\Dms\CorrespondenceRegistryService;
use App\Services\Pki\InternalCaService;
use App\Services\Profile\ProfileManager;
use App\Services\Security\DbSecurityContext;
use App\Services\Security\TwoPersonService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AfzDefenceSeeder extends Seeder
{
    public function run(): void
    {
        $demoPassword = config()->string('sentinel.demo_password', 'SentinelDemo!2026');
        $profiles = app(ProfileManager::class);
        $audit = app(ImmutableAuditLogger::class);
        $totp = app(TotpMfaService::class);
        $twoPerson = app(TwoPersonService::class);
        $dbContext = app(DbSecurityContext::class);

        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'afz-defence'],
            [
                'name' => 'Air Force Command Document Registry',
                'hosting_mode' => 'air_gapped_on_prem',
                'air_gapped' => true,
                'active_profile_code' => ProfileCode::Defence,
            ]
        );

        $profiles->applyToTenant($tenant, ProfileCode::Defence, syncTaxonomy: true);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        foreach (SentinelRole::values() as $roleName) {
            Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
            ]);
        }

        $hq = OrgUnit::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'AFZ-HQ'],
            ['name' => 'Air Force Headquarters', 'unit_type' => 'command', 'path' => 'afz']
        );
        $opsWing = OrgUnit::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'OPS-WING'],
            ['parent_id' => $hq->id, 'name' => 'Operations Wing', 'unit_type' => 'wing', 'path' => 'afz.ops']
        );
        $sigSqn = OrgUnit::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'SIG-SQN'],
            ['parent_id' => $opsWing->id, 'name' => 'No. 1 Signals Squadron', 'unit_type' => 'squadron', 'path' => 'afz.ops.sig']
        );
        $logWing = OrgUnit::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'LOG-WING'],
            ['parent_id' => $hq->id, 'name' => 'Logistics & Maintenance Wing', 'unit_type' => 'wing', 'path' => 'afz.log']
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
            $secret = $totp->generateSecret();
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
                    'two_factor_secret' => Crypt::encryptString($secret),
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

        $avm = $createUser('avm.chikwanda@afz.mil.zw', 'M. Chikwanda', 'AFZ-0001', 'Air Vice-Marshal', $hq, 4, SentinelRole::Approver, ['OPS', 'INTEL', 'PERS', 'LOG']);
        $wgCdr = $createUser('wgcdr.moyo@afz.mil.zw', 'T. Moyo', 'AFZ-0142', 'Wing Commander', $opsWing, 3, SentinelRole::RecordsOfficer, ['OPS', 'INTEL']);
        $sqnLdr = $createUser('sqnldr.ndlovu@afz.mil.zw', 'R. Ndlovu', 'AFZ-0488', 'Squadron Leader', $sigSqn, 2, SentinelRole::Author, ['OPS']);
        $cpl = $createUser('cpl.sibanda@afz.mil.zw', 'K. Sibanda', 'AFZ-2190', 'Corporal', $sigSqn, 1, SentinelRole::Reader, []);
        $sysAdmin = $createUser('fltlt.nyoni@afz.mil.zw', 'P. Nyoni', 'AFZ-0891', 'Flight Lieutenant', $hq, 1, SentinelRole::SystemAdmin, []);
        $auditor = $createUser('gpcapt.dube@afz.mil.zw', 'V. Dube', 'AFZ-0305', 'Group Captain', $hq, 2, SentinelRole::Auditor, []);

        $secretOpsDoc = $dbContext->runAs($avm, function () use ($tenant, $sigSqn, $opsWing, $hq, $levels, $compartments, $audit, $dbContext, $avm, $wgCdr, $sqnLdr, $cpl, $twoPerson): Document {
            $cabGeneral = Cabinet::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Administrative Orders'],
                ['org_unit_id' => $sigSqn->id, 'description' => 'Routine base orders and duty rosters.', 'default_classification_rank' => 0]
            );
            $cabOps = Cabinet::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Operational Signals Registry'],
                ['org_unit_id' => $opsWing->id, 'description' => 'Wing operational directives and flight taskings.', 'default_classification_rank' => 2]
            );
            $cabCommand = Cabinet::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Command Strategic Vault'],
                ['org_unit_id' => $hq->id, 'description' => 'Air Defence posture and strategic directives.', 'default_classification_rank' => 3]
            );

            $folderOrders = Folder::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'cabinet_id' => $cabGeneral->id, 'name' => '2026 Base Notices'],
                ['org_unit_id' => $sigSqn->id, 'path' => 'afz.ops.sig.notices', 'default_classification_rank' => 0]
            );
            $folderOps = Folder::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'cabinet_id' => $cabOps->id, 'name' => '2026 Operational Taskings'],
                ['org_unit_id' => $opsWing->id, 'path' => 'afz.ops.taskings', 'default_classification_rank' => 2]
            );
            $folderIntel = Folder::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'cabinet_id' => $cabCommand->id, 'name' => 'SIGINT & Radar Posture'],
                ['org_unit_id' => $hq->id, 'path' => 'afz.hq.sigint', 'default_classification_rank' => 3]
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
                bool $unitWide = true,
            ) use ($tenant, $levels, $compartments, $audit, $dbContext): Document {
                return $dbContext->runAs($owner, function () use ($docNumber, $title, $summary, $cabinet, $folder, $unit, $owner, $rank, $compCodes, $unitWide, $tenant, $levels, $compartments, $audit): Document {
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
                            'unit_wide_readable' => $unitWide,
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

                    return $doc;
                });
            };

            $rosterDoc = $seedDoc('AFZ/SIG/2026/001', 'Parade & Shift Duty Roster — November 2026', 'Standard weekly duty roster for Signals Squadron personnel.', $cabGeneral, $folderOrders, $sigSqn, $cpl, 0, []);
            $seedDoc('AFZ/SIG/2026/014', 'VHF Radio Frequency Maintenance Schedule', 'Scheduled preventive maintenance windows for ground-to-air transceivers.', $cabGeneral, $folderOrders, $sigSqn, $sqnLdr, 1, []);
            $secretOps = $seedDoc('AFZ/OPS/2026/088', 'Operation Zambezi Airspace Patrol Plan', 'Sector patrol corridors, transponder schedules, and scramble readiness times.', $cabOps, $folderOps, $opsWing, $wgCdr, 3, ['OPS']);
            $seedDoc('AFZ/INTEL/2026/019', 'Northern Corridor Radar Coverage Assessment', 'Signal propagation blind-spot evaluation and electronic counter-measure baseline.', $cabCommand, $folderIntel, $hq, $wgCdr, 3, ['OPS', 'INTEL'], false);
            $seedDoc('AFZ/CMD/2026/003', 'Strategic Air Defence Command Contingency Annex A', 'Command-level force posture and contingency mobilization protocols.', $cabCommand, $folderIntel, $hq, $avm, 4, ['OPS', 'INTEL']);

            $registry = app(CorrespondenceRegistryService::class);
            if (! DB::table('correspondence_registry')->where('document_id', $rosterDoc->id)->exists()) {
                $registry->register($cpl, $rosterDoc, CorrespondenceDirection::Internal, 'Signals Squadron HQ', 'FOLIO-SIG-11', 'BAR-SIG-0001', 'Internal Dispatch', now()->addDays(5)->toDateString());
            }
            if (! DB::table('correspondence_registry')->where('document_id', $secretOps->id)->exists()) {
                $registry->register($wgCdr, $secretOps, CorrespondenceDirection::Outgoing, 'Joint Operations Command', 'FOLIO-OPS-88', 'BAR-OPS-0088', 'Diplomatic / Command Courier', now()->addDays(2)->toDateString());
            }

            if (! DB::table('two_person_requests')->where('tenant_id', $tenant->id)->where('target_id', $secretOps->id)->exists()) {
                $twoPerson->initiate(
                    requester: $wgCdr,
                    actionType: TwoPersonActionType::Declassify,
                    targetType: 'document',
                    targetId: $secretOps->id,
                    targetClassificationRank: $secretOps->classification_rank,
                    payload: ['target_classification_level_id' => $levels[2]->id, 'target_rank' => 2],
                    reason: 'Downgrade completed training exercise patrol annex to Confidential for wing debrief archive.',
                );
            }

            SeedPhase4DefaultsHelper::seedDefaults($tenant, $wgCdr);

            return $secretOps;
        });

        if (! app()->runningUnitTests()) {
            $dbContext->runAs($wgCdr, function () use ($tenant, $wgCdr, $avm, $sqnLdr): void {
                $caService = app(InternalCaService::class);
                $caService->ensureBootstrapped($tenant, $wgCdr);
                foreach ([$avm, $wgCdr, $sqnLdr] as $certHolder) {
                    if ($certHolder->certificates()->doesntExist()) {
                        $caService->issueUserCertificate($certHolder, CertificateCustodyMode::HsmManaged, $wgCdr);
                    }
                }
            });
        }

        unset($secretOpsDoc, $sysAdmin, $auditor, $logWing);
    }
}
