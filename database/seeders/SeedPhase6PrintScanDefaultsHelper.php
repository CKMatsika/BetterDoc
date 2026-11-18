<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PrinterDriver;
use App\Enums\ProfileCode;
use App\Enums\ScanSourceType;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\CostCentre;
use App\Models\Printer;
use App\Models\ScanSource;
use App\Models\Tenant;
use App\Models\User;

class SeedPhase6PrintScanDefaultsHelper
{
    public static function seedDefaults(Tenant $tenant, User $actor): void
    {
        $cab = Cabinet::query()->where('tenant_id', $tenant->id)->first();
        $internalLevel = ClearanceLevel::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('rank')
            ->skip(1)
            ->first() ?? ClearanceLevel::query()->where('tenant_id', $tenant->id)->orderBy('rank')->first();

        // Seed Tenant RFID badges on realistic officers cleanly without overwriting custom bindings
        $badgeMap = match ($tenant->active_profile_code) {
            ProfileCode::Defence => [
                'wgcdr.moyo@afz.mil.zw' => 'BADGE-AFZ-0142',
                'avm.chikwanda@afz.mil.zw' => 'BADGE-AFZ-0001',
                'cpl.sibanda@afz.mil.zw' => 'BADGE-AFZ-1088',
            ],
            ProfileCode::Financial => [
                'cro.ncube@golden-knot.co.zw' => 'BADGE-GK-0002',
                'branch.hre@golden-knot.co.zw' => 'BADGE-GK-0105',
                'analyst.chigumba@golden-knot.co.zw' => 'BADGE-GK-0412',
            ],
            ProfileCode::Corporate => [
                'director@zimcorp.co.zw' => 'BADGE-ZC-0010',
                'ops.lead@zimcorp.co.zw' => 'BADGE-ZC-0102',
                'clerk@zimcorp.co.zw' => 'BADGE-ZC-0215',
            ],
            ProfileCode::Government => [],
        };

        foreach ($badgeMap as $email => $uid) {
            User::query()
                ->where('tenant_id', $tenant->id)
                ->where('email', $email)
                ->whereNull('rfid_badge_uid')
                ->update(['rfid_badge_uid' => $uid]);
        }

        // Seed Cost Centres
        $centres = match ($tenant->active_profile_code) {
            ProfileCode::Defence => [
                ['code' => 'CC-HQ-OPS', 'name' => 'Operations & Command Reproduction', 'quota' => 5000],
                ['code' => 'CC-SIG-SQN', 'name' => 'Signals Squadron Printing', 'quota' => 1500],
            ],
            ProfileCode::Financial => [
                ['code' => 'CC-HRE-BR', 'name' => 'Harare Branch Underwriting', 'quota' => 4000],
                ['code' => 'CC-TREASURY', 'name' => 'Treasury & RBZ Compliance Print', 'quota' => 2500],
            ],
            ProfileCode::Corporate => [
                ['code' => 'CC-FIN-AP', 'name' => 'Accounts Payable & Finance', 'quota' => 3500],
                ['code' => 'CC-OPS-FLEET', 'name' => 'Commercial Operations Print', 'quota' => 2000],
            ],
            ProfileCode::Government => [
                ['code' => 'CC-MIN-REG', 'name' => 'Registry & Gazette Print', 'quota' => 4000],
            ],
        };

        foreach ($centres as $cc) {
            CostCentre::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $cc['code']],
                [
                    'name' => $cc['name'],
                    'monthly_page_quota' => $cc['quota'],
                    'pages_used_month' => 0,
                    'is_active' => true,
                ]
            );
        }

        // Seed Printers across Clearance Ceilings (rank 1 open office vs rank 3/4 vault secure printer)
        $printers = match ($tenant->active_profile_code) {
            ProfileCode::Defence => [
                ['name' => 'Lobby MFP — General Duty', 'location' => 'Main Guardhouse Hall', 'queue' => 'afz-lobby-l1', 'max_rank' => 1],
                ['name' => 'Operations Vault Secure MFP', 'location' => 'HQ Ops Floor Secure Cage', 'queue' => 'afz-ops-secret', 'max_rank' => 3],
                ['name' => 'SCIF Top Secret Plotter/Printer', 'location' => 'HQ Command SCIF Room 4', 'queue' => 'afz-scif-ts', 'max_rank' => 4],
            ],
            ProfileCode::Financial => [
                ['name' => 'Teller Counter Printer', 'location' => 'First Street Branch Floor', 'queue' => 'gk-teller-l1', 'max_rank' => 1],
                ['name' => 'Treasury & AML Secure MFP', 'location' => 'Level 3 Compliance Vault', 'queue' => 'gk-treasury-sec', 'max_rank' => 3],
            ],
            ProfileCode::Corporate => [
                ['name' => 'Open Office Workgroup MFP', 'location' => 'Borrowdale West Floor 1', 'queue' => 'zc-office-l1', 'max_rank' => 1],
                ['name' => 'Executive Boardroom Secure Printer', 'location' => 'Executive Suite Floor 3', 'queue' => 'zc-exec-sec', 'max_rank' => 2],
            ],
            ProfileCode::Government => [
                ['name' => 'Registry Secure Release MFP', 'location' => 'Ministry Ground Floor', 'queue' => 'gov-reg-l1', 'max_rank' => 2],
            ],
        };

        foreach ($printers as $p) {
            Printer::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'cups_queue_name' => $p['queue']],
                [
                    'name' => $p['name'],
                    'location' => $p['location'],
                    'ipps_uri' => "http://cups:631/printers/{$p['queue']}",
                    'driver' => PrinterDriver::LocalSpool,
                    'max_classification_rank' => $p['max_rank'],
                    'supports_secure_release' => true,
                    'supports_duplex' => true,
                    'is_online' => true,
                    'capabilities_json' => ['duplex' => true, 'pin_release' => true, 'rfid_badge' => true],
                ]
            );
        }

        // Seed default LAN Hot Folder & Local Mail-In dropboxes + eSCL MFP source
        $sources = [
            [
                'name' => 'Central Feeder Batch Hot Folder',
                'source_type' => ScanSourceType::HotFolder,
                'endpoint_or_path' => $tenant->slug.'-inbox',
            ],
            [
                'name' => 'Local Mail-In Capture Relay',
                'source_type' => ScanSourceType::MailIn,
                'endpoint_or_path' => $tenant->slug.'-mail',
            ],
            [
                'name' => 'LAN eSCL / AirScan MFP Scanner',
                'source_type' => ScanSourceType::EsclAirscan,
                'endpoint_or_path' => 'http://localhost:8085',
            ],
        ];

        foreach ($sources as $s) {
            ScanSource::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $s['name']],
                [
                    'source_type' => $s['source_type'],
                    'endpoint_or_path' => $s['endpoint_or_path'],
                    'target_cabinet_id' => $cab?->id,
                    'default_classification_level_id' => $internalLevel?->id,
                    'auto_ocr' => true,
                    'auto_split_barcode' => true,
                    'auto_deskew' => true,
                    'is_active' => true,
                ]
            );
        }

        unset($actor);
    }
}
