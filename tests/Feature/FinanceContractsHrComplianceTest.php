<?php

declare(strict_types=1);

use App\Enums\ApInvoiceStatus;
use App\Enums\ContractStatus;
use App\Enums\ObligationStatus;
use App\Enums\ZimraFiscalStatus;
use App\Models\ApInvoice;
use App\Models\Contract;
use App\Models\ContractObligation;
use App\Models\Document;
use App\Models\HrEmployeeFile;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Vendor;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Search\DocumentSearchService;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;
use Database\Seeders\GoldenKnotFinancialSeeder;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('executes 3-way AP matching, flags qty/price variance, blocks duplicate vendor invoices, exports UBL 2.1 XML, and fiscalizes ZIMRA FDMS', function (): void {
    $this->seed([GoldenKnotFinancialSeeder::class]);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'golden-knot')->firstOrFail();
    /** @var User $cro */
    $cro = User::query()->where('email', 'cro.gumbo@golden-knot.co.zw')->firstOrFail();
    $dbContext = app(DbSecurityContext::class);

    [$vendor, $po, $cabinetId, $levelId] = $dbContext->runAs($cro, static function () use ($tenant): array {
        $v = Vendor::query()->where('tenant_id', $tenant->id)->where('code', 'VND-GK-01')->firstOrFail();
        $p = PurchaseOrder::query()->where('tenant_id', $tenant->id)->where('po_number', 'PO-GK-2026-019')->firstOrFail();
        $doc = Document::query()->where('tenant_id', $tenant->id)->firstOrFail();

        return [$v, $p, $doc->cabinet_id, $doc->classification_level_id];
    });

    $this->signIn($cro);

    // Ensure Finance Inertia page loads
    $this->get('/finance')->assertOk();

    // 1. Exact 3-way match against seeded PO + GRN (10 units @ 25,000 cents = 250,000 + 37,500 VAT = 287,500)
    $exactRes = $this->postJson('/finance/invoices', [
        'vendor_id' => $vendor->id,
        'purchase_order_id' => $po->id,
        'cabinet_id' => $cabinetId,
        'classification_level_id' => $levelId,
        'invoice_number' => 'INV-GK-MATCH-001',
        'invoice_date' => '2026-11-18',
        'subtotal_cents' => 250000,
        'vat_cents' => 37500,
        'total_cents' => 287500,
        'match_mode' => '3_way',
        'lines' => [
            ['sku' => 'SVC-MAINT-Q4', 'description' => 'Q4 Support', 'qty_invoiced' => 10, 'unit_price_cents' => 25000],
        ],
    ])->assertCreated();

    $matchedId = (string) $exactRes->json('invoice.id');
    expect($exactRes->json('invoice.status'))->toBe(ApInvoiceStatus::Matched->value);

    // 2. Price Variance beyond 2% tolerance -> flagged + requires override justification from Approver
    $priceVarRes = $this->postJson('/finance/invoices', [
        'vendor_id' => $vendor->id,
        'purchase_order_id' => $po->id,
        'cabinet_id' => $cabinetId,
        'classification_level_id' => $levelId,
        'invoice_number' => 'INV-GK-PRICEVAR-002',
        'invoice_date' => '2026-11-18',
        'subtotal_cents' => 310000,
        'vat_cents' => 46500,
        'total_cents' => 356500,
        'match_mode' => '3_way',
        'lines' => [
            ['sku' => 'SVC-MAINT-Q4', 'description' => 'Q4 Support Price Surge', 'qty_invoiced' => 10, 'unit_price_cents' => 31000],
        ],
    ])->assertCreated();

    $priceVarId = (string) $priceVarRes->json('invoice.id');
    expect($priceVarRes->json('invoice.status'))->toBe(ApInvoiceStatus::VarianceFlagged->value);

    // Approving variance without justification must fail
    $this->postJson("/finance/invoices/{$priceVarId}/approve", [])->assertStatus(422);

    // Approving variance WITH justification succeeds
    $this->postJson("/finance/invoices/{$priceVarId}/approve", [
        'override_reason' => 'Executive FX escalation waiver approved.',
    ])->assertOk()->assertJsonPath('invoice.status', ApInvoiceStatus::Approved->value);

    // 3. Quantity Variance / Missing GRN in 3-way mode -> flagged
    $noGrnPoRes = $this->postJson('/finance/purchase-orders', [
        'vendor_id' => $vendor->id,
        'po_number' => 'PO-GK-NOGRN-99',
        'lines' => [
            ['sku' => 'HW-UPS-3KVA', 'description' => 'Branch UPS Unit', 'qty_ordered' => 4, 'unit_price_cents' => 50000],
        ],
    ])->assertCreated();
    $noGrnPoId = (string) $noGrnPoRes->json('purchase_order.id');

    $qtyVarRes = $this->postJson('/finance/invoices', [
        'vendor_id' => $vendor->id,
        'purchase_order_id' => $noGrnPoId,
        'cabinet_id' => $cabinetId,
        'classification_level_id' => $levelId,
        'invoice_number' => 'INV-GK-NOGRN-003',
        'invoice_date' => '2026-11-18',
        'subtotal_cents' => 200000,
        'vat_cents' => 30000,
        'total_cents' => 230000,
        'match_mode' => '3_way',
        'lines' => [
            ['sku' => 'HW-UPS-3KVA', 'description' => 'Branch UPS Unit', 'qty_invoiced' => 4, 'unit_price_cents' => 50000],
        ],
    ])->assertCreated();
    expect($qtyVarRes->json('invoice.status'))->toBe(ApInvoiceStatus::VarianceFlagged->value);

    // 4. Duplicate Vendor Invoice Blocking (even case-normalized)
    $dupRes = $this->postJson('/finance/invoices', [
        'vendor_id' => $vendor->id,
        'purchase_order_id' => $po->id,
        'cabinet_id' => $cabinetId,
        'classification_level_id' => $levelId,
        'invoice_number' => 'inv-gk-match-001',
        'invoice_date' => '2026-11-18',
        'subtotal_cents' => 250000,
        'vat_cents' => 37500,
        'total_cents' => 287500,
        'match_mode' => '3_way',
        'lines' => [
            ['sku' => 'SVC-MAINT-Q4', 'description' => 'Duplicate Attempt', 'qty_invoiced' => 10, 'unit_price_cents' => 25000],
        ],
    ])->assertCreated();
    $dupId = (string) $dupRes->json('invoice.id');
    expect($dupRes->json('invoice.status'))->toBe(ApInvoiceStatus::DuplicateBlocked->value);
    $this->postJson("/finance/invoices/{$dupId}/approve", ['override_reason' => 'Attempt'])
        ->assertStatus(422);

    // 5. UBL 2.1 / PEPPOL BIS 3.0 XML Export
    $ublResp = $this->get("/finance/invoices/{$matchedId}/ubl")->assertOk();
    $dom = new DOMDocument;
    expect($dom->loadXML($ublResp->getContent()))->toBeTrue();
    expect($dom->documentElement?->namespaceURI)->toBe('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2')
        ->and($ublResp->getContent())->toContain('INV-GK-MATCH-001')
        ->and($ublResp->getContent())->toContain('2875.00');

    // 6. ZIMRA FDMS Offline Queue + Command Replay Fiscalization
    config()->set('betterdoc.finance.zimra.driver', 'offline_queue');
    $this->postJson("/finance/invoices/{$matchedId}/fiscalize")
        ->assertOk()
        ->assertJsonPath('zimra_fiscal_status', ZimraFiscalStatus::QueuedOffline->value);

    config()->set('betterdoc.finance.zimra.driver', 'fdms_http');
    config()->set('betterdoc.finance.zimra.fdms_url', 'http://127.0.0.1:8095');
    Http::fake([
        'http://127.0.0.1:8095/api/v1/submit-receipt' => Http::response([
            'receiptGlobalNo' => 'FDMS-900123',
            'qrCode' => 'FDMS|FDMS-ZW-001|FDMS-900123|OK',
        ], 200),
    ]);

    $this->artisan('betterdoc:zimra:sync-fiscal')->assertExitCode(0);

    $refreshed = $dbContext->runAs($cro, static fn (): ApInvoice => ApInvoice::query()->findOrFail($matchedId));
    expect($refreshed->zimra_fiscal_status)->toBe(ZimraFiscalStatus::Fiscalized)
        ->and($refreshed->zimra_receipt_no)->toBe('FDMS-900123');
});

it('scans expiring contracts, overdue obligations, dispatches alerts, and searches standard clauses', function (): void {
    $this->seed([GoldenKnotFinancialSeeder::class]);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'golden-knot')->firstOrFail();
    /** @var User $cro */
    $cro = User::query()->where('email', 'cro.gumbo@golden-knot.co.zw')->firstOrFail();
    $dbContext = app(DbSecurityContext::class);

    $doc = $dbContext->runAs($cro, static fn (): Document => Document::query()->where('tenant_id', $tenant->id)->firstOrFail());

    $this->signIn($cro);

    // Clause library search loads cleanly
    $clausePage = $this->get('/contracts?q=indemnity')->assertOk();
    $clausePage->assertSee('Indemnity');

    // Seed an Active contract entering its notice window + an Open obligation in the past
    [$contractId, $obId] = $dbContext->runAs($cro, static function () use ($tenant, $cro, $doc): array {
        $contract = Contract::query()->create([
            'tenant_id' => $tenant->id,
            'document_id' => $doc->id,
            'contract_number' => 'CNT-TEST-SCAN-01',
            'title' => 'Core Switch Support Contract',
            'counterparty_name' => 'Harare Networks Pvt Ltd',
            'effective_date' => now('UTC')->subMonths(11)->toDateString(),
            'expiry_date' => now('UTC')->addDays(7)->toDateString(),
            'renewal_notice_days' => 30,
            'value_cents' => 150000,
            'currency' => 'USD',
            'status' => ContractStatus::Active,
            'auto_renew' => false,
        ]);

        $ob = ContractObligation::query()->create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'owner_id' => $cro->id,
            'title' => 'Submit Signed Compliance Certificate',
            'due_date' => now('UTC')->subDays(2)->toDateString(),
            'status' => ObligationStatus::Open,
        ]);

        return [$contract->id, $ob->id];
    });

    $this->artisan('betterdoc:contracts:scan-alerts')->assertExitCode(0);

    [$updatedContract, $updatedOb, $alertCount] = $dbContext->runAs($cro, static function () use ($contractId, $obId, $cro): array {
        return [
            Contract::query()->findOrFail($contractId),
            ContractObligation::query()->findOrFail($obId),
            UserNotification::query()
                ->where('user_id', $cro->id)
                ->whereIn('type', ['contract.expiry_alert', 'contract.obligation_overdue'])
                ->count(),
        ];
    });

    expect($updatedContract->status)->toBe(ContractStatus::ExpiringSoon)
        ->and($updatedOb->status)->toBe(ObligationStatus::Overdue)
        ->and($alertCount)->toBeGreaterThanOrEqual(1);

    // Fulfill obligation via route
    $this->post("/contracts/obligations/{$obId}/complete")->assertRedirect();
    $fulfilledOb = $dbContext->runAs($cro, static fn (): ContractObligation => ContractObligation::query()->findOrFail($obId));
    expect($fulfilledOb->status)->toBe(ObligationStatus::Fulfilled);
});

it('strictly hides hr_restricted_from_subject records from subject employee across HR vault, document view, search, and AI RAG', function (): void {
    $this->seed([GoldenKnotFinancialSeeder::class]);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'golden-knot')->firstOrFail();
    /** @var User $cro */
    $cro = User::query()->where('email', 'cro.gumbo@golden-knot.co.zw')->firstOrFail();
    /** @var User $analyst */
    $analyst = User::query()->where('email', 'analyst.chigumba@golden-knot.co.zw')->firstOrFail();
    $dbContext = app(DbSecurityContext::class);

    [$empFile, $seededRestrictedDoc, $payslipDoc, $cabinetId, $level1Id] = $dbContext->runAs($cro, static function () use ($tenant, $analyst): array {
        $ef = HrEmployeeFile::query()
            ->where('tenant_id', $tenant->id)
            ->where('subject_user_id', $analyst->id)
            ->firstOrFail();
        $restricted = Document::query()->where('tenant_id', $tenant->id)->where('doc_number', 'GK/HR/2026/044')->firstOrFail();
        $payslip = Document::query()
            ->where('tenant_id', $tenant->id)
            ->where('hr_subject_user_id', $analyst->id)
            ->where('hr_restricted_from_subject', false)
            ->firstOrFail();

        return [$ef, $restricted, $payslip, $payslip->cabinet_id, $payslip->classification_level_id];
    });

    // HR officer files an explicit confidential disciplinary hearing record on analyst (rank L1 so ONLY hr_restricted_from_subject blocks it!)
    $this->signIn($cro);
    $recordRes = $this->postJson('/hr/records', [
        'hr_employee_file_id' => $empFile->id,
        'cabinet_id' => $cabinetId,
        'classification_level_id' => $level1Id,
        'category' => 'disciplinary',
        'title' => 'Disciplinary Hearing — Token UniqueShieldedHrToken99',
        'content_text' => 'Confidential disciplinary tribunal record UniqueShieldedHrToken99 for Analyst Chigumba.',
        'restricted_from_subject' => true,
    ])->assertCreated();
    $newDisciplinaryId = (string) $recordRes->json('document.id');

    // HR officer DOES see the shielded record in /hr
    $hrOfficerPage = $this->get('/hr')->assertOk();
    $hrOfficerPage->assertSee('UniqueShieldedHrToken99');

    // Switch session to subject employee (analyst.chigumba@golden-knot.co.zw)
    $this->signIn($analyst);

    // Subject employee can see own Payslip on /hr and open it (200), but NEVER sees shielded records
    $subjectHrPage = $this->get('/hr')->assertOk();
    $subjectHrPage->assertInertia(static fn (Assert $page): Assert => $page
        ->component('Hr/Index')
        ->where('hrDocuments', static function ($docs) use ($payslipDoc, $newDisciplinaryId, $seededRestrictedDoc): bool {
            $arr = is_array($docs) ? $docs : $docs->toArray();
            $ids = array_column($arr, 'id');

            return in_array($payslipDoc->id, $ids, true)
                && ! in_array($newDisciplinaryId, $ids, true)
                && ! in_array($seededRestrictedDoc->id, $ids, true);
        })
    );
    $subjectHrPage->assertDontSee('UniqueShieldedHrToken99');
    $subjectHrPage->assertDontSee('GK\\/HR\\/2026\\/044');

    $this->get("/documents/{$payslipDoc->id}")->assertOk();
    $this->get("/documents/{$seededRestrictedDoc->id}")->assertNotFound();
    $this->get("/documents/{$newDisciplinaryId}")->assertNotFound();

    // Full-text search under RLS returns zero hits for shielded record token
    $searchHits = $dbContext->runAs(
        $analyst,
        static fn (): array => app(DocumentSearchService::class)->search($analyst, 'UniqueShieldedHrToken99')
    );
    expect($searchHits['total'])->toBe(0);

    // Hybrid AI RAG returns zero citations pointing to shielded HR documents
    $aiRes = $this->postJson('/ai/chat', [
        'query' => 'What does UniqueShieldedHrToken99 say about disciplinary hearing?',
    ])->assertOk();
    $citedIds = $aiRes->json('message.cited_doc_ids') ?? [];
    expect($citedIds)->not->toContain($newDisciplinaryId)
        ->and($citedIds)->not->toContain($seededRestrictedDoc->id);
});

it('generates verifiable encrypted compliance packs, verifies audit checkpoint HMACs, and exports RFC 5424 syslog', function (): void {
    $this->seed([AfzDefenceSeeder::class]);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    /** @var User $auditor */
    $auditor = User::query()->where('email', 'gpcapt.dube@afz.mil.zw')->firstOrFail();

    $this->signIn($auditor);

    // Anchor a signed audit checkpoint as Auditor and verify its HMAC integrity
    $this->post('/audit/checkpoints')->assertRedirect();

    $cpVerify = app(ImmutableAuditLogger::class)->verifyCheckpoints($tenant);
    expect($cpVerify['valid'])->toBeTrue()
        ->and($cpVerify['checked'])->toBeGreaterThanOrEqual(1);

    // Audit UI loads cleanly with verification banner & compliance tools
    $this->get('/audit')->assertOk();

    // Generate Encrypted ZIMRA Regulator Export Pack
    $genRes = $this->postJson('/compliance/packs', [
        'regulation_standard' => 'ZIMRA',
        'pack_name' => 'ZIMRA Annual Audit Bundle 2026',
    ])->assertCreated();
    $packId = (string) $genRes->json('pack.id');

    // Cryptographic Verify Endpoint checks manifest SHA-256 + file hashes + chain + checkpoint HMACs
    $this->getJson("/compliance/packs/{$packId}/verify")
        ->assertOk()
        ->assertJson([
            'pack_id' => $packId,
            'manifest_valid' => true,
            'files_valid' => true,
            'chain_valid' => true,
            'checkpoints_valid' => true,
        ]);

    // ZIP Download contains valid archive & signed files
    $zipResp = $this->get("/compliance/packs/{$packId}/download")->assertOk();
    expect($zipResp->headers->get('Content-Type'))->toContain('application/zip');

    $tmpZip = tempnam(sys_get_temp_dir(), 'bdoc_dl_').'.zip';
    file_put_contents($tmpZip, $zipResp->getContent());
    try {
        $zip = new ZipArchive;
        expect($zip->open($tmpZip))->toBeTrue();
        expect($zip->getFromName('manifest.json'))->toBeString()->toContain('ZIMRA')
            ->and($zip->getFromName('audit_events.jsonl'))->toBeString()
            ->and($zip->getFromName('audit_checkpoints.json'))->toBeString()
            ->and($zip->getFromName('chain_verification.json'))->toBeString();
        $zip->close();
    } finally {
        @unlink($tmpZip);
    }

    // RFC 5424 Syslog Export validates strictly formatted syslog frames
    $syslogResp = $this->get('/compliance/syslog')->assertOk();
    expect($syslogResp->getContent())->toMatch('/^<\d+>1 \d{4}-\d{2}-\d{2}T/');
    expect($syslogResp->getContent())->toContain('[betterdoc@59999 tenant="afz-defence"');
});
