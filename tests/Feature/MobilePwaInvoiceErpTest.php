<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\ErpSystemType;
use App\Models\ApInvoice;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\ErpBridgeJob;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Integration\ErpBridgeService;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\CorporateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobilePwaInvoiceErpTest extends TestCase
{
    public function test_mobile_pwa_captures_invoice_detects_via_idp_queues_sage_pastel_bridge_and_archives(): void
    {
        $this->seed([CorporateSeeder::class]);
        $tenant = Tenant::query()->where('slug', 'zimcorp-corporate')->firstOrFail();
        $admin = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', 'admin@zimcorp.co.zw')
            ->firstOrFail();

        /** @var array{cabinet: Cabinet, level: ClearanceLevel, agent_id: string} $setup */
        $setup = app(DbSecurityContext::class)->runAs($admin, function () use ($tenant, $admin): array {
            $cabinet = Cabinet::query()->where('tenant_id', $tenant->id)->firstOrFail();
            $level = ClearanceLevel::query()
                ->where('tenant_id', $tenant->id)
                ->where('rank', '<=', $admin->clearance_rank)
                ->orderBy('rank')
                ->firstOrFail();

            $paired = app(ErpBridgeService::class)->initiatePairing(
                actor: $admin,
                name: 'Harare Pastel Mobile Bridge',
                erpTarget: 'sage_pastel_evolution',
            );

            return [
                'cabinet' => $cabinet,
                'level' => $level,
                'agent_id' => $paired['agent']->id,
            ];
        });

        // 1. Mobile PWA page loads cleanly
        $this->actingAs($admin)
            ->withSession(['mfa_verified' => true])
            ->get('/mobile')
            ->assertOk();

        // 2. Snap & upload an invoice document (plain text PDF-normalized sample with invoice fields)
        $invoiceNumber = 'INV-MOB-'.random_int(10000, 99999);
        $sampleText = implode("\n", [
            'TAX INVOICE',
            'Vendor: Harare Industrial Supplies Ltd',
            "Invoice Number: {$invoiceNumber}",
            'Invoice Date: 2026-11-25',
            'Currency: USD',
            'Subtotal: 400.00',
            'VAT (15%): 60.00',
            'Total Due: 460.00',
            'ZIMRA TIN: 2001456789',
        ]);

        // Create a minimal valid PDF containing the invoice text so OCR/text extraction picks it up
        $pdfContent = $this->buildMinimalPdf($sampleText);
        $file = UploadedFile::fake()->createWithContent('mobile-invoice-snap.pdf', $pdfContent);

        $captureResp = $this->actingAs($admin)
            ->withSession(['mfa_verified' => true])
            ->postJson('/mobile/capture', [
                'file' => $file,
                'cabinet_id' => $setup['cabinet']->id,
                'classification_level_id' => $setup['level']->id,
                'title' => 'Harare Industrial Tax Invoice',
                'auto_deskew' => true,
            ])
            ->assertCreated();

        $captureResp->assertJsonPath('is_invoice', true);
        $captureResp->assertJsonPath('suggested_invoice.invoice_number', $invoiceNumber);
        $captureResp->assertJsonPath('suggested_invoice.total_cents', 46000);

        $docId = (string) $captureResp->json('document.id');
        $this->assertNotSame('', $docId);

        // 3. Confirm & push to Sage Pastel LAN Bridge + Archive + WORM Lock
        $processResp = $this->actingAs($admin)
            ->withSession(['mfa_verified' => true])
            ->postJson("/mobile/documents/{$docId}/process-invoice", [
                'vendor_name' => 'Harare Industrial Supplies Ltd',
                'invoice_number' => $invoiceNumber,
                'invoice_date' => '2026-11-25',
                'currency' => 'USD',
                'subtotal_cents' => 40000,
                'vat_cents' => 6000,
                'total_cents' => 46000,
                'auto_approve' => true,
                'erp_target_type' => 'bridge',
                'erp_target_id' => $setup['agent_id'],
                'archive_document' => true,
                'apply_worm_lock' => true,
            ])
            ->assertOk();

        $processResp->assertJsonPath('ap_invoice.status', 'approved');
        $processResp->assertJsonPath('erp_result.status', 'queued_for_lan_bridge');
        $processResp->assertJsonPath('document.status', DocumentStatus::Archived->value);
        $processResp->assertJsonPath('document.is_worm', true);

        $bridgeJobId = (string) $processResp->json('erp_result.bridge_job_id');
        $apInvoiceId = (string) $processResp->json('ap_invoice.id');

        app(DbSecurityContext::class)->runAs($admin, function () use ($docId, $bridgeJobId, $apInvoiceId): void {
            /** @var Document $doc */
            $doc = Document::query()->findOrFail($docId);
            $this->assertSame(DocumentStatus::Archived, $doc->status);
            $this->assertTrue($doc->is_worm);
            $this->assertSame($apInvoiceId, $doc->metadata_json['mobile_erp_processing']['ap_invoice_id'] ?? null);

            /** @var ApInvoice $inv */
            $inv = ApInvoice::query()->findOrFail($apInvoiceId);
            $this->assertSame('approved', $inv->status->value);

            /** @var ErpBridgeJob $job */
            $job = ErpBridgeJob::query()->findOrFail($bridgeJobId);
            $this->assertSame('push_invoices', $job->job_type);
            $this->assertSame('queued', $job->status);
        });
    }

    public function test_sanctum_mobile_api_pushes_invoice_to_xero_and_archives_ready_for_flutter(): void
    {
        $this->seed([CorporateSeeder::class]);
        $tenant = Tenant::query()->where('slug', 'zimcorp-corporate')->firstOrFail();
        $admin = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', 'admin@zimcorp.co.zw')
            ->firstOrFail();

        $originalAirGap = (bool) $tenant->air_gapped;
        $originalAllowlist = $tenant->external_allowlist_hosts;
        $originalGlobalAirGap = config()->boolean('betterdoc.air_gapped', true);
        $originalGlobalAllowlist = config('betterdoc.external_allowlist_hosts', []);

        config()->set('betterdoc.air_gapped', false);
        config()->set('betterdoc.external_allowlist_hosts', ['api.xero.com']);
        $tenant->update([
            'air_gapped' => false,
            'external_allowlist_hosts' => ['api.xero.com'],
        ]);

        try {
            /** @var array{cabinet: Cabinet, level: ClearanceLevel, connection: IntegrationConnection} $setup */
            $setup = app(DbSecurityContext::class)->runAs($admin, function () use ($tenant, $admin): array {
                $cabinet = Cabinet::query()->where('tenant_id', $tenant->id)->firstOrFail();
                $level = ClearanceLevel::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('rank', '<=', $admin->clearance_rank)
                    ->orderBy('rank')
                    ->firstOrFail();

                /** @var IntegrationConnection $connection */
                $connection = IntegrationConnection::query()->create([
                    'tenant_id' => $tenant->id,
                    'created_by' => $admin->id,
                    'name' => 'ZimCorp Xero Accounting',
                    'system_type' => ErpSystemType::Xero,
                    'base_url' => 'https://api.xero.com/api.xro/2.0',
                    'credentials_encrypted' => json_encode([
                        'access_token' => 'xero-mobile-test-token',
                        'xero_tenant_id' => 'xero-tenant-123',
                    ], JSON_THROW_ON_ERROR),
                    'mapping_config_json' => [
                        'default_account_code' => '400',
                        'default_tax_type' => 'INPUT2',
                    ],
                    'is_active' => true,
                ]);

                return [
                    'cabinet' => $cabinet,
                    'level' => $level,
                    'connection' => $connection,
                ];
            });

            $token = $admin->createToken('flutter-mobile-test', ['documents:read', 'documents:write', 'finance:sync'])->plainTextToken;

            // 1. Bootstrap endpoint
            $this->withToken($token)
                ->getJson('/api/v1/mobile/bootstrap')
                ->assertOk()
                ->assertJsonStructure(['user', 'cabinets', 'allowed_clearances', 'vendors', 'erp_targets']);

            // 2. Mobile API capture with force_invoice
            $file = UploadedFile::fake()->createWithContent('receipt.pdf', $this->buildMinimalPdf('Fuel Receipt USD 120.00'));
            $captureResp = $this->withToken($token)
                ->postJson('/api/v1/mobile/capture', [
                    'file' => $file,
                    'cabinet_id' => $setup['cabinet']->id,
                    'classification_level_id' => $setup['level']->id,
                    'title' => 'Mobile Fuel Receipt',
                    'force_invoice' => true,
                ])
                ->assertCreated();

            $docId = (string) $captureResp->json('document.id');
            $invoiceNumber = 'INV-XERO-'.random_int(10000, 99999);

            Http::fake([
                'https://api.xero.com/api.xro/2.0/Invoices' => Http::response([
                    'Invoices' => [
                        ['InvoiceID' => 'xero-bill-999', 'InvoiceNumber' => $invoiceNumber],
                    ],
                ], 200),
            ]);

            // 3. Process invoice to Xero and archive
            $processResp = $this->withToken($token)
                ->postJson("/api/v1/mobile/documents/{$docId}/process-invoice", [
                    'vendor_name' => 'TotalEnergies Zimbabwe',
                    'invoice_number' => $invoiceNumber,
                    'invoice_date' => '2026-11-25',
                    'currency' => 'USD',
                    'subtotal_cents' => 10000,
                    'vat_cents' => 2000,
                    'total_cents' => 12000,
                    'auto_approve' => true,
                    'erp_target_type' => 'connection',
                    'erp_target_id' => $setup['connection']->id,
                    'archive_document' => true,
                ])
                ->assertOk();

            $processResp->assertJsonPath('erp_result.status', 'completed');
            $processResp->assertJsonPath('document.status', DocumentStatus::Archived->value);

            // 4. Quick mobile document rename via PATCH
            $this->withToken($token)
                ->patchJson("/api/v1/mobile/documents/{$docId}", [
                    'title' => 'Archived Fuel Receipt — Verified',
                ])
                ->assertOk()
                ->assertJsonPath('document.title', 'Archived Fuel Receipt — Verified');
        } finally {
            config()->set('betterdoc.air_gapped', $originalGlobalAirGap);
            config()->set('betterdoc.external_allowlist_hosts', $originalGlobalAllowlist);
            $tenant->update([
                'air_gapped' => $originalAirGap,
                'external_allowlist_hosts' => $originalAllowlist,
            ]);
        }
    }

    private function buildMinimalPdf(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $lines = explode("\n", $escaped);
        $stream = "BT\n/F1 12 Tf\n50 750 Td\n";
        foreach ($lines as $idx => $line) {
            if ($idx > 0) {
                $stream .= "0 -16 Td\n";
            }
            $stream .= "({$line}) Tj\n";
        }
        $stream .= "ET\n";

        $objects = [];
        $objects[] = '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj';
        $objects[] = '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj';
        $objects[] = '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj';
        $objects[] = '4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj';
        $objects[] = '5 0 obj << /Length '.strlen($stream)." >> stream\n{$stream}endstream endobj";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj."\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF\n";

        return $pdf;
    }
}
