<?php

declare(strict_types=1);

use App\Enums\ApInvoiceStatus;
use App\Enums\ErpSystemType;
use App\Enums\UserStatus;
use App\Models\ApInvoice;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\ExternalMapping;
use App\Models\HrEmployeeFile;
use App\Models\IntegrationDelivery;
use App\Models\IntegrationWebhook;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Integration\IntegrationWebhookService;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;
use Database\Seeders\CorporateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

it('restricts integration hub to SystemAdmin and supports scoped Sanctum token management', function (): void {
    $this->seed([CorporateSeeder::class]);

    /** @var User $nonAdmin */
    $nonAdmin = User::query()->where('email', 'clerk@zimcorp.co.zw')->firstOrFail();
    /** @var User $sysAdmin */
    $sysAdmin = User::query()->where('email', 'admin@zimcorp.co.zw')->firstOrFail();

    $this->signIn($nonAdmin);
    $this->get('/admin/integrations')->assertForbidden();
    $this->post('/admin/integrations/tokens', ['name' => 'Unauthorized', 'abilities' => ['documents:read']])->assertForbidden();

    $this->signIn($sysAdmin);
    $this->get('/admin/integrations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Integrations'));

    $this->post('/admin/integrations/tokens', [
        'name' => 'Insurance Claims Gateway',
        'abilities' => ['documents:read', 'documents:write'],
        'expires_days' => 30,
    ])->assertRedirect()->assertSessionHas('issued_api_token');

    $tokenId = $sysAdmin->tokens()->firstOrFail()->id;
    $this->delete("/admin/integrations/tokens/{$tokenId}")->assertRedirect();
    expect($sysAdmin->tokens()->count())->toBe(0);
});

it('enforces Sanctum token scopes, PostgreSQL RLS isolation, and insurance policy/claim ingestion on /api/v1/*', function (): void {
    $this->seed([AfzDefenceSeeder::class, CorporateSeeder::class]);

    /** @var User $corpLowUser */
    $corpLowUser = User::query()->where('email', 'clerk@zimcorp.co.zw')->firstOrFail();
    /** @var User $corpAdmin */
    $corpAdmin = User::query()->where('email', 'admin@zimcorp.co.zw')->firstOrFail();

    $readOnlyToken = $corpLowUser->createToken('ReadOnly', ['documents:read'])->plainTextToken;
    $fullToken = $corpLowUser->createToken('FullIntegration', ['documents:read', 'documents:write', 'finance:sync', 'hr:sync'])->plainTextToken;

    // 1. Missing scope is blocked with 403
    $this->withToken($readOnlyToken)
        ->postJson('/api/v1/documents', ['title' => 'Should Fail'])
        ->assertForbidden();

    // 2. PostgreSQL RLS hides AFZ Defence docs and ZimCorp L2 confidential docs from L1 clerk token
    $listResp = $this->withToken($readOnlyToken)
        ->getJson('/api/v1/documents')
        ->assertOk();

    $docNumbers = collect($listResp->json('data'))->pluck('doc_number')->all();
    expect($docNumbers)->not->toContain('ZC/EXEC/2026/009')
        ->and($docNumbers)->not->toContain('AFZ/OPS/2026/001');

    // 3. Token user cannot classify a document above their own clearance rank (L1 clerk trying L2 confidential)
    $corpL2 = ClearanceLevel::query()
        ->where('tenant_id', $corpLowUser->tenant_id)
        ->where('rank', '>', $corpLowUser->clearance_rank)
        ->firstOrFail();

    auth()->forgetGuards();

    $this->withToken($fullToken)
        ->postJson('/api/v1/documents', [
            'title' => 'Write-Up Blocked Claim',
            'classification_level_id' => $corpL2->id,
        ])
        ->assertForbidden();

    // 4. Insurance claim/policy document ingestion succeeds and maps external_id + policy_number + claim_number
    auth()->forgetGuards();

    $ingestResp = $this->withToken($fullToken)
        ->postJson('/api/v1/documents', [
            'title' => 'Motor Third-Party Claim Assessment',
            'external_system' => ErpSystemType::InsuranceCore->value,
            'external_reference' => 'CLM-2026-0091',
            'policy_number' => 'POL-ZW-88412',
            'claim_number' => 'CLM-2026-0091',
            'content_text' => 'Assessor report verified offline.',
        ])
        ->assertCreated();

    $docId = (string) $ingestResp->json('document.id');

    auth()->forgetGuards();

    $claimSearch = $this->withToken($readOnlyToken)
        ->getJson('/api/v1/documents?claim_number=CLM-2026-0091')
        ->assertOk();

    expect(collect($claimSearch->json('data'))->pluck('id')->all())->toContain($docId);

    // 5. Vendor upsert + Invoices pull + Belina/HR employee upsert via API
    auth()->forgetGuards();

    $this->withToken($fullToken)
        ->postJson('/api/v1/finance/vendors', [
            'code' => 'XERO-901',
            'name' => 'Harare Reinsurance Brokers',
            'currency' => 'USD',
            'external_system' => ErpSystemType::Xero->value,
            'external_id' => 'xero-contact-901',
        ])
        ->assertCreated();

    $hrToken = $corpAdmin->createToken('HrSync', ['hr:sync'])->plainTextToken;

    // Non-HR clerk is blocked from HR sync even if token has hr:sync scope
    $this->withToken($fullToken)
        ->postJson('/api/v1/hr/employees', [
            'employee_number' => 'BEL-901',
            'email' => 'belina.new@zimcorp.co.zw',
            'name' => 'Kudakwashe Nyoni',
        ])
        ->assertForbidden();

    auth()->forgetGuards();

    $this->withToken($hrToken)
        ->postJson('/api/v1/hr/employees', [
            'employee_number' => 'BEL-901',
            'email' => 'belina.new@zimcorp.co.zw',
            'name' => 'Kudakwashe Nyoni',
            'department_name' => 'Claims',
            'job_title' => 'Claims Assessor',
            'external_system' => ErpSystemType::Belina->value,
        ])
        ->assertCreated();

    app(DbSecurityContext::class)->runAs($corpAdmin, function () use ($corpAdmin, $docId): void {
        expect(ExternalMapping::query()->where('tenant_id', $corpAdmin->tenant_id)->where('internal_id', $docId)->exists())->toBeTrue()
            ->and(Vendor::query()->where('tenant_id', $corpAdmin->tenant_id)->where('code', 'XERO-901')->exists())->toBeTrue()
            ->and(HrEmployeeFile::query()->where('tenant_id', $corpAdmin->tenant_id)->where('employee_number', 'BEL-901')->exists())->toBeTrue();
    });

    // 6. Suspended user API token is rejected immediately
    $corpLowUser->update(['status' => UserStatus::Suspended]);
    auth()->forgetGuards();
    $this->withToken($readOnlyToken)->getJson('/api/v1/documents')->assertForbidden();
});

it('dispatches HMAC-SHA256 webhooks when allowlisted and safely records blocked_airgap when external', function (): void {
    $this->seed([CorporateSeeder::class]);

    /** @var User $sysAdmin */
    $sysAdmin = User::query()->where('email', 'admin@zimcorp.co.zw')->firstOrFail();

    app(DbSecurityContext::class)->runAs($sysAdmin, function () use ($sysAdmin): void {
        IntegrationWebhook::query()->create([
            'tenant_id' => $sysAdmin->tenant_id,
            'name' => 'Air-Gapped Blocked External Hook',
            'target_url' => 'https://unlisted.erp.test/webhook',
            'secret_encrypted' => 'super-secret-webhook-key-12345',
            'events_json' => ['webhook.test'],
            'is_active' => true,
        ]);

        // Unlisted external host in air-gapped mode logs blocked_airgap cleanly
        config()->set('betterdoc.air_gapped', true);
        config()->set('betterdoc.external_allowlist_hosts', []);

        app(IntegrationWebhookService::class)->emit(
            tenantId: $sysAdmin->tenant_id,
            eventType: 'webhook.test',
            data: ['hello' => 'world'],
            actor: $sysAdmin,
            syncDispatch: true,
        );

        /** @var IntegrationDelivery $blocked */
        $blocked = IntegrationDelivery::query()->where('tenant_id', $sysAdmin->tenant_id)->latest()->firstOrFail();
        expect($blocked->status)->toBe('blocked_airgap');

        // Allowlisted external host receives HMAC-SHA256 signed POST
        IntegrationWebhook::query()->create([
            'tenant_id' => $sysAdmin->tenant_id,
            'name' => 'Allowlisted NetSuite Hook',
            'target_url' => 'https://hooks.erp.test/receiver',
            'secret_encrypted' => 'netsuite-hmac-secret-67890',
            'events_json' => ['ap.invoice_approved'],
            'is_active' => true,
        ]);

        config()->set('betterdoc.external_allowlist_hosts', ['hooks.erp.test']);
        Http::fake(['https://hooks.erp.test/receiver' => Http::response(['received' => true], 200)]);

        app(IntegrationWebhookService::class)->emit(
            tenantId: $sysAdmin->tenant_id,
            eventType: 'ap.invoice_approved',
            data: ['invoice_number' => 'INV-2026-01'],
            actor: $sysAdmin,
            syncDispatch: true,
        );

        Http::assertSent(function ($request): bool {
            $timestamp = $request->header('X-BetterDoc-Timestamp')[0] ?? '';
            $sig = $request->header('X-BetterDoc-Signature')[0] ?? '';
            $expected = 'sha256='.hash_hmac('sha256', "{$timestamp}.{$request->body()}", 'netsuite-hmac-secret-67890');

            return $request->url() === 'https://hooks.erp.test/receiver'
                && $request->header('X-BetterDoc-Event')[0] === 'ap.invoice_approved'
                && hash_equals($expected, $sig);
        });

        /** @var IntegrationDelivery $delivered */
        $delivered = IntegrationDelivery::query()
            ->where('tenant_id', $sysAdmin->tenant_id)
            ->where('event_type', 'ap.invoice_approved')
            ->firstOrFail();
        expect($delivered->status)->toBe('delivered')
            ->and($delivered->response_code)->toBe(200);
    });
});

it('imports Belina employees and Sage Pastel vendors CSVs and exports formula-safe AP invoices CSV', function (): void {
    $this->seed([CorporateSeeder::class]);

    /** @var User $sysAdmin */
    $sysAdmin = User::query()->where('email', 'admin@zimcorp.co.zw')->firstOrFail();
    $this->signIn($sysAdmin);

    $belinaCsv = UploadedFile::fake()->createWithContent(
        'belina.csv',
        "employee_number,email,name,department_name,job_title,hire_date\nBEL-777,belina.payroll@zimcorp.co.zw,Rudo Moyo,Payroll,Payroll Officer,2026-02-01\n"
    );

    $this->post('/admin/integrations/import/belina-employees', ['csv_file' => $belinaCsv])
        ->assertRedirect()
        ->assertSessionHas('success');

    $sageCsv = UploadedFile::fake()->createWithContent(
        'sage-vendors.csv',
        "vendor_code,name,tax_id_tin,vat_number,currency,email\nSAGE-404,=EvilFormula Vendor,20001122,10002233,USD,sage@vendor.co.zw\n"
    );

    $this->post('/admin/integrations/import/sage-vendors', ['csv_file' => $sageCsv])
        ->assertRedirect()
        ->assertSessionHas('success');

    // Create a matched invoice linked to the Sage vendor so export exercises both external_vendor_id and formula escaping
    app(DbSecurityContext::class)->runAs($sysAdmin, function () use ($sysAdmin): void {
        /** @var Vendor $vendor */
        $vendor = Vendor::query()->where('tenant_id', $sysAdmin->tenant_id)->where('code', 'SAGE-404')->firstOrFail();
        /** @var Document $doc */
        $doc = Document::query()->where('tenant_id', $sysAdmin->tenant_id)->firstOrFail();

        ApInvoice::query()->create([
            'tenant_id' => $sysAdmin->tenant_id,
            'vendor_id' => $vendor->id,
            'document_id' => $doc->id,
            'invoice_number' => 'INV-SAGE-001',
            'invoice_date' => now('UTC')->toDateString(),
            'currency' => 'USD',
            'subtotal_cents' => 10000,
            'vat_cents' => 1500,
            'total_cents' => 11500,
            'status' => ApInvoiceStatus::Approved,
            'match_mode' => '2_way',
        ]);

        expect(HrEmployeeFile::query()->where('tenant_id', $sysAdmin->tenant_id)->where('employee_number', 'BEL-777')->exists())->toBeTrue();
    });

    $exportResp = $this->get('/admin/integrations/export/ap-invoices?system=sage_pastel')->assertOk();
    expect($exportResp->getContent())
        ->toContain('SAGE-404')
        ->toContain("'=EvilFormula Vendor")
        ->toContain('INV-SAGE-001');
});
