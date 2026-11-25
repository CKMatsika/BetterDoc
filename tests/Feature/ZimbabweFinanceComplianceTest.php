<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApInvoice;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\ApAutomationService;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\CorporateSeeder;
use Tests\TestCase;

class ZimbabweFinanceComplianceTest extends TestCase
{
    public function test_itf263_missing_triggers_30_percent_wht_and_valid_itf263_exempts_wht_with_rbz_zwg_fx_and_fdms_qr(): void
    {
        $this->seed([CorporateSeeder::class]);

        $tenant = Tenant::query()->where('slug', 'zimcorp-corporate')->firstOrFail();
        $admin = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', 'admin@zimcorp.co.zw')
            ->firstOrFail();

        // 1. Record RBZ Interbank USD/ZWG rate (1 USD = 27.25 ZWG)
        $this->actingAs($admin)
            ->withSession(['mfa_verified' => true])
            ->postJson('/finance/exchange-rates', [
                'quote_currency' => 'ZWG',
                'rate' => 27.25,
                'effective_date' => '2026-11-25',
                'source' => 'RBZ Interbank',
            ])
            ->assertCreated()
            ->assertJsonPath('exchange_rate.quote_currency', 'ZWG');

        /** @var array{vendor_no_itf: Vendor, vendor_with_itf: Vendor, document: Document} $setup */
        $setup = app(DbSecurityContext::class)->runAs($admin, function () use ($tenant, $admin): array {
            $cabinet = Cabinet::query()->where('tenant_id', $tenant->id)->firstOrFail();
            $level = ClearanceLevel::query()
                ->where('tenant_id', $tenant->id)
                ->where('rank', '<=', $admin->clearance_rank)
                ->orderBy('rank')
                ->firstOrFail();

            $doc = Document::query()->create([
                'tenant_id' => $tenant->id,
                'cabinet_id' => $cabinet->id,
                'org_unit_id' => $admin->org_unit_id,
                'owner_id' => $admin->id,
                'classification_level_id' => $level->id,
                'classification_rank' => $level->rank,
                'doc_number' => 'FIN/2026/ZIM-001',
                'title' => 'Zimbabwe Supplier Invoice Pack',
            ]);

            $vendorNoItf = Vendor::query()->create([
                'tenant_id' => $tenant->id,
                'code' => 'ZIM-NO-ITF',
                'name' => 'Bulawayo Timber Suppliers',
                'tax_id_tin' => '2009988776',
                'currency' => 'ZWG',
                'is_active' => true,
            ]);

            $vendorWithItf = Vendor::query()->create([
                'tenant_id' => $tenant->id,
                'code' => 'ZIM-VALID-ITF',
                'name' => 'Harare Solar Engineering',
                'tax_id_tin' => '2001122334',
                'currency' => 'USD',
                'is_active' => true,
            ]);

            return [
                'vendor_no_itf' => $vendorNoItf,
                'vendor_with_itf' => $vendorWithItf,
                'document' => $doc,
            ];
        });

        // 2. Update statutory compliance pack on second vendor (valid ITF263 + PRAZ + NSSA)
        $this->actingAs($admin)
            ->withSession(['mfa_verified' => true])
            ->patchJson("/finance/vendors/{$setup['vendor_with_itf']->id}/compliance", [
                'itf263_certificate_no' => 'ITF263-2026-88991',
                'itf263_expires_at' => '2027-06-30',
                'itf263_document_id' => $setup['document']->id,
                'praz_registration_no' => 'PRAZ-2026-4451',
                'praz_expires_at' => '2027-12-31',
                'nssa_clearance_no' => 'NSSA-2026-7712',
                'nssa_expires_at' => '2027-03-31',
            ])
            ->assertOk()
            ->assertJsonPath('compliance.itf263_valid', true)
            ->assertJsonPath('compliance.wht_required', false);

        // 3. Capture ZWG invoice for vendor WITHOUT ITF263 -> 30% WHT applied + RBZ ZWG/USD conversion locked
        /** @var ApInvoice $whtInvoice */
        $whtInvoice = app(DbSecurityContext::class)->runAs($admin, function () use ($admin, $setup): ApInvoice {
            return app(ApAutomationService::class)->captureAndMatch(
                actor: $admin,
                vendor: $setup['vendor_no_itf']->refresh(),
                document: $setup['document'],
                po: null,
                header: [
                    'invoice_number' => 'INV-ZWG-1001',
                    'invoice_date' => '2026-11-25',
                    'currency' => 'ZWG',
                    'subtotal_cents' => 272500, // ZWG 2,725.00
                    'vat_cents' => 0,
                    'total_cents' => 272500,
                ],
                lines: [
                    ['sku' => 'TIMBER', 'description' => 'Structural Timber', 'qty_invoiced' => 1.0, 'unit_price_cents' => 272500],
                ],
            );
        });

        $this->assertTrue($whtInvoice->wht_applicable);
        $this->assertSame(3000, $whtInvoice->wht_rate_bps);
        $this->assertSame(81750, $whtInvoice->wht_cents); // 30% of 272500
        $this->assertSame(190750, $whtInvoice->net_payable_cents);
        $this->assertEqualsWithDelta(27.25, $whtInvoice->exchange_rate_to_usd, 0.0001);
        $this->assertSame(10000, $whtInvoice->usd_equivalent_cents); // USD 100.00

        // 4. Capture USD invoice for vendor WITH valid ITF263 -> 0% WHT
        /** @var ApInvoice $exemptInvoice */
        $exemptInvoice = app(DbSecurityContext::class)->runAs($admin, function () use ($admin, $setup): ApInvoice {
            return app(ApAutomationService::class)->captureAndMatch(
                actor: $admin,
                vendor: $setup['vendor_with_itf']->refresh(),
                document: $setup['document'],
                po: null,
                header: [
                    'invoice_number' => 'INV-USD-2002',
                    'invoice_date' => '2026-11-25',
                    'currency' => 'USD',
                    'subtotal_cents' => 50000,
                    'vat_cents' => 7500,
                    'total_cents' => 57500,
                ],
                lines: [
                    ['sku' => 'SOLAR', 'description' => 'Inverter Installation', 'qty_invoiced' => 1.0, 'unit_price_cents' => 50000],
                ],
            );
        });

        $this->assertFalse($exemptInvoice->wht_applicable);
        $this->assertSame(0, $exemptInvoice->wht_cents);
        $this->assertSame(57500, $exemptInvoice->net_payable_cents);

        // 5. Verify a genuine ZIMRA FDMS QR URL on the invoice & confirm duplicate QR is rejected
        $fdmsQr = 'https://fdms.zimra.co.zw/0000012345251120260000000987ABCD1234EFGH5678';
        $this->actingAs($admin)
            ->withSession(['mfa_verified' => true])
            ->postJson("/finance/invoices/{$exemptInvoice->id}/verify-fdms-qr", [
                'qr_payload' => $fdmsQr,
            ])
            ->assertOk()
            ->assertJsonPath('fdms_qr_verified', true)
            ->assertJsonPath('fdms_verification.device_id', '12345')
            ->assertJsonPath('fdms_verification.receipt_global_no', '987');

        // Reusing the same ZIMRA FDMS QR on a different invoice must fail (anti-fraud guard)
        $this->actingAs($admin)
            ->withSession(['mfa_verified' => true])
            ->postJson("/finance/invoices/{$whtInvoice->id}/verify-fdms-qr", [
                'qr_payload' => $fdmsQr,
            ])
            ->assertStatus(422);
    }
}
