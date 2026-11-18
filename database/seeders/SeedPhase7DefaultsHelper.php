<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProfileCode;
use App\Enums\PurchaseOrderStatus;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Contract;
use App\Models\ContractClause;
use App\Models\Document;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Contracts\ContractLifecycleService;
use App\Services\Hr\HrVaultService;
use App\Services\Security\DbSecurityContext;

class SeedPhase7DefaultsHelper
{
    public static function seedDefaults(Tenant $tenant, User $actor): void
    {
        app(DbSecurityContext::class)->runAs($actor, static function () use ($tenant, $actor): void {
            self::seedUnderContext($tenant, $actor);
        });
    }

    private static function seedUnderContext(Tenant $tenant, User $actor): void
    {
        $contracts = app(ContractLifecycleService::class);
        $hr = app(HrVaultService::class);

        // 1. Searchable Standard Clause Library
        $clauses = [
            [
                'title' => 'Indemnity & Liability Cap (ZW Law)',
                'category' => 'liability',
                'clause_text' => 'Each party limits aggregate direct damages to 100% of annual contract fees, governed by the laws of the Republic of Zimbabwe.',
            ],
            [
                'title' => 'Termination for Convenience (30-Day Notice)',
                'category' => 'termination',
                'clause_text' => 'Either party may terminate without cause upon 30 calendar days written notice with pro-rata settlement of accepted deliverables.',
            ],
            [
                'title' => 'Cyber & Data Protection Act Secrecy Undertaking',
                'category' => 'compliance',
                'clause_text' => 'All counterparty personnel handling controlled records shall maintain strict confidentiality under the Cyber and Data Protection Act [Chapter 12:07].',
            ],
        ];

        foreach ($clauses as $cl) {
            if (! ContractClause::query()
                ->where('tenant_id', $tenant->id)
                ->where('title', $cl['title'])
                ->exists()) {
                $contracts->addClause(
                    actor: $actor,
                    contract: null,
                    title: $cl['title'],
                    category: $cl['category'],
                    clauseText: $cl['clause_text'],
                    isStandardTemplate: true,
                );
            }
        }

        // 2. Sample Vendor, Purchase Order & Goods Receipt (3-Way AP Match Ready)
        [$vCode, $vName, $tin, $poNumber, $grnNumber] = match ($tenant->active_profile_code) {
            ProfileCode::Defence => [
                'VND-AFZ-01',
                'Zimbabwe Defence Systems Supply',
                '2000194822',
                'PO-AFZ-2026-011',
                'GRN-AFZ-2026-011',
            ],
            ProfileCode::Financial => [
                'VND-GK-01',
                'Harare Core Banking Solutions Ltd',
                '2000841109',
                'PO-GK-2026-019',
                'GRN-GK-2026-019',
            ],
            ProfileCode::Corporate => [
                'VND-ZC-01',
                'Harare Logistics & Fleet Ltd',
                '2000553201',
                'PO-ZC-2026-042',
                'GRN-ZC-2026-042',
            ],
            ProfileCode::Government => [
                'VND-GOV-01',
                'Public Printing & Stationery Corp',
                '2000110099',
                'PO-GOV-2026-001',
                'GRN-GOV-2026-001',
            ],
        };

        /** @var Vendor $vendor */
        $vendor = Vendor::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => $vCode],
            [
                'name' => $vName,
                'tax_id_tin' => $tin,
                'vat_number' => '10045901',
                'currency' => 'USD',
                'peppol_endpoint_id' => '0195:zw'.$tin,
                'email' => 'accounts@'.strtolower(preg_replace('/[^a-z]/', '', $vCode) ?: 'vendor').'.co.zw',
                'is_active' => true,
            ]
        );

        $po = PurchaseOrder::query()
            ->where('tenant_id', $tenant->id)
            ->where('po_number', $poNumber)
            ->first();

        if ($po === null) {
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()->create([
                'tenant_id' => $tenant->id,
                'vendor_id' => $vendor->id,
                'created_by' => $actor->id,
                'po_number' => $poNumber,
                'status' => PurchaseOrderStatus::Received,
                'currency' => 'USD',
                'subtotal_cents' => 250000,
                'tax_cents' => 37500,
                'total_cents' => 287500,
                'issued_at' => now('UTC')->subDays(7)->toDateString(),
            ]);

            /** @var PurchaseOrderLine $poLine */
            $poLine = PurchaseOrderLine::query()->create([
                'tenant_id' => $tenant->id,
                'purchase_order_id' => $po->id,
                'line_number' => 1,
                'sku' => 'SVC-MAINT-Q4',
                'description' => 'Q4 Enterprise Hardware & Compliance Support Retainer',
                'qty_ordered' => 10,
                'unit_price_cents' => 25000,
                'tax_rate_bps' => 1500,
            ]);

            /** @var GoodsReceipt $grn */
            $grn = GoodsReceipt::query()->create([
                'tenant_id' => $tenant->id,
                'purchase_order_id' => $po->id,
                'received_by' => $actor->id,
                'grn_number' => $grnNumber,
                'received_at' => now()->subDays(2),
                'notes' => 'Full 10-unit milestone acceptance verified.',
            ]);

            GoodsReceiptLine::query()->create([
                'tenant_id' => $tenant->id,
                'goods_receipt_id' => $grn->id,
                'purchase_order_line_id' => $poLine->id,
                'qty_received' => 10,
            ]);
        }

        // 3. Seeded Active Contract + Scheduled Obligation
        $doc = Document::query()
            ->where('tenant_id', $tenant->id)
            ->where('classification_rank', '<=', $actor->clearance_rank)
            ->orderBy('classification_rank')
            ->first();

        $contractNo = match ($tenant->active_profile_code) {
            ProfileCode::Defence => 'CNT-AFZ-2026-004',
            ProfileCode::Financial => 'CNT-GK-2026-012',
            ProfileCode::Government => 'CNT-GOV-2026-001',
            ProfileCode::Corporate => 'CNT-ZC-2026-108',
        };

        if ($doc instanceof Document && ! Contract::query()->where('tenant_id', $tenant->id)->where('contract_number', $contractNo)->exists()) {
            $contract = $contracts->registerContract(
                actor: $actor,
                document: $doc,
                vendor: $vendor,
                data: [
                    'contract_number' => $contractNo,
                    'title' => "{$vName} Master Service Agreement",
                    'counterparty_name' => $vName,
                    'effective_date' => now('UTC')->subMonths(11)->toDateString(),
                    'expiry_date' => now('UTC')->addDays(20)->toDateString(),
                    'renewal_notice_days' => 30,
                    'value_cents' => 2875000,
                    'currency' => 'USD',
                    'auto_renew' => false,
                ],
            );

            $contracts->addObligation(
                actor: $actor,
                contract: $contract,
                title: 'Annual ZIMRA ITF263 Tax Clearance Certificate Renewal Check',
                dueDate: now('UTC')->addDays(10)->toDateString(),
            );
        }

        // 4. HR Personnel File + Visible Employee Payslip (Alongside Restricted Inquiry)
        $cabinet = Cabinet::query()->where('tenant_id', $tenant->id)->orderBy('name')->first();
        $targetEmail = match ($tenant->active_profile_code) {
            ProfileCode::Defence => 'fltlt.nyoni@afz.mil.zw',
            ProfileCode::Financial => 'analyst.chigumba@golden-knot.co.zw',
            ProfileCode::Government => 'clerk@government.local',
            ProfileCode::Corporate => 'clerk@zimcorp.co.zw',
        };
        $subject = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', $targetEmail)
            ->first()
            ?? User::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', '!=', $actor->id)
                ->orderBy('clearance_rank')
                ->orderBy('email')
                ->first();

        if ($cabinet instanceof Cabinet && $subject instanceof User) {
            $maxAllowedRank = min($actor->clearance_rank, $subject->clearance_rank);
            $level = ClearanceLevel::query()
                ->where('tenant_id', $tenant->id)
                ->where('rank', '<=', $maxAllowedRank)
                ->orderBy('rank', 'desc')
                ->first();

            $empNo = match ($tenant->active_profile_code) {
                ProfileCode::Defence => 'EMP-AFZ-1088',
                ProfileCode::Financial => 'EMP-GK-0412',
                ProfileCode::Government => 'EMP-GOV-0100',
                ProfileCode::Corporate => 'EMP-ZC-0215',
            };

            $empFile = $hr->ensureEmployeeProfile(
                hrActor: $actor,
                employee: $subject,
                hrCabinet: $cabinet,
                employeeNumber: $empNo,
                departmentName: 'Operations & Credit Support',
                jobTitle: $subject->name,
                hireDate: '2024-02-01',
            );

            if ($level instanceof ClearanceLevel && ! Document::query()
                ->where('tenant_id', $tenant->id)
                ->where('hr_subject_user_id', $subject->id)
                ->where('hr_restricted_from_subject', false)
                ->exists()) {
                $hr->fileHrDocument(
                    hrActor: $actor,
                    employeeFile: $empFile,
                    hrCabinet: $cabinet,
                    level: $level,
                    category: 'payslip',
                    title: "Monthly Payslip — {$subject->name} (October 2026)",
                    contentText: "Employee {$empNo}\nGross Pay: USD 1,850.00\nPAYE & NSSA Deductions Applied\nNet Salary Disbursed.",
                    restrictedFromSubject: false,
                );
            }
        }
    }
}
