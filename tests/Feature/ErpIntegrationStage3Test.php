<?php

declare(strict_types=1);

use App\Enums\ApInvoiceStatus;
use App\Enums\ErpSystemType;
use App\Models\ApInvoice;
use App\Models\Document;
use App\Models\ErpBridgeAgent;
use App\Models\ExternalMapping;
use App\Models\HrEmployeeFile;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\CorporateSeeder;

it('pairs a Windows LAN ERP bridge agent, executes queued Sage Pastel & Belina jobs under PostgreSQL RLS, and enforces revocation', function (): void {
    $this->seed([CorporateSeeder::class]);

    /** @var User $sysAdmin */
    $sysAdmin = User::query()->where('email', 'admin@zimcorp.co.zw')->firstOrFail();
    /** @var User $clerk */
    $clerk = User::query()->where('email', 'clerk@zimcorp.co.zw')->firstOrFail();

    // Non-admin blocked from bridge management
    $this->signIn($clerk);
    $this->post('/admin/integrations/bridge-agents', [
        'name' => 'Unauthorized Bridge',
        'erp_target' => 'sage_pastel_evolution',
    ])->assertForbidden();

    // 1. SystemAdmin registers Windows LAN Sage Pastel Evolution Bridge Agent
    $this->signIn($sysAdmin);
    $createResp = $this->post('/admin/integrations/bridge-agents', [
        'name' => 'Harare HQ Sage Evolution',
        'erp_target' => 'sage_pastel_evolution',
    ])->assertRedirect()->assertSessionHas('issued_bridge_pairing_code');

    $pairingCode = (string) session('issued_bridge_pairing_code');
    expect($pairingCode)->toStartWith('PAIR-');

    /** @var ErpBridgeAgent $agent */
    $agent = app(DbSecurityContext::class)->runAs($sysAdmin, static fn () => ErpBridgeAgent::query()
        ->where('tenant_id', $sysAdmin->tenant_id)
        ->firstOrFail());

    // Downloadable config JSON works cleanly
    $this->get("/admin/integrations/bridge-agents/{$agent->id}/download-config")
        ->assertOk()
        ->assertSee('sage_pastel_evolution');

    // 2. Windows LAN agent pairs outbound via /api/v1/bridge/pair
    $pairResp = $this->postJson('/api/v1/bridge/pair', [
        'pairing_code' => $pairingCode,
        'hostname' => 'WIN-SAGE-01.corp.lan',
        'agent_version' => '1.0.0',
    ])->assertCreated();

    $bridgeToken = (string) $pairResp->json('bridge_token');
    expect($bridgeToken)->toStartWith('bdb_');

    // Pairing code is one-time only and cannot be reused
    $this->postJson('/api/v1/bridge/pair', [
        'pairing_code' => $pairingCode,
        'hostname' => 'ROGUE-HOST',
    ])->assertUnauthorized();

    // Heartbeat works with bridge token
    $this->withHeader('X-BetterDoc-Bridge-Token', $bridgeToken)
        ->postJson('/api/v1/bridge/heartbeat')
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    // 3. Admin queues pull_vendors -> Windows agent polls & submits Sage Evolution vendors
    $this->post("/admin/integrations/bridge-agents/{$agent->id}/jobs", ['job_type' => 'pull_vendors'])
        ->assertRedirect();

    $pollVendor = $this->withHeader('X-BetterDoc-Bridge-Token', $bridgeToken)
        ->postJson('/api/v1/bridge/poll')
        ->assertOk();

    $vendorJobId = (string) $pollVendor->json('job.id');
    expect($pollVendor->json('job.job_type'))->toBe('pull_vendors');

    $this->withHeader('X-BetterDoc-Bridge-Token', $bridgeToken)
        ->postJson("/api/v1/bridge/jobs/{$vendorJobId}/complete", [
            'status' => 'completed',
            'result' => [
                'vendors' => [[
                    'external_id' => 'EVO-DCLINK-701',
                    'vendor_code' => 'EVO701',
                    'name' => 'Bulawayo Industrial Bearings Ltd',
                    'tax_id_tin' => '20007701',
                    'currency' => 'USD',
                    'email' => 'ap@bearings.co.zw',
                ]],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('job.status', 'completed');

    // 4. Admin queues pull_employees -> Windows agent submits Belina employees
    $this->post("/admin/integrations/bridge-agents/{$agent->id}/jobs", ['job_type' => 'pull_employees'])
        ->assertRedirect();

    $pollEmp = $this->withHeader('X-BetterDoc-Bridge-Token', $bridgeToken)
        ->postJson('/api/v1/bridge/poll')
        ->assertOk();

    $empJobId = (string) $pollEmp->json('job.id');

    $this->withHeader('X-BetterDoc-Bridge-Token', $bridgeToken)
        ->postJson("/api/v1/bridge/jobs/{$empJobId}/complete", [
            'status' => 'completed',
            'result' => [
                'employees' => [[
                    'employee_number' => 'BEL-LAN-301',
                    'email' => 'lan.employee@zimcorp.co.zw',
                    'name' => 'Farai Chikwanda',
                    'department_name' => 'Operations',
                    'job_title' => 'Fleet Supervisor',
                    'hire_date' => '2026-03-01',
                ]],
            ],
        ])
        ->assertOk();

    // 5. Create approved AP invoice for EVO701 -> Queue push_invoices -> Agent claims & acknowledges Sage ref
    $invoiceId = app(DbSecurityContext::class)->runAs($sysAdmin, function () use ($sysAdmin): string {
        /** @var Vendor $vendor */
        $vendor = Vendor::query()->where('tenant_id', $sysAdmin->tenant_id)->where('code', 'EVO701')->firstOrFail();
        /** @var Document $doc */
        $doc = Document::query()->where('tenant_id', $sysAdmin->tenant_id)->firstOrFail();

        /** @var ApInvoice $inv */
        $inv = ApInvoice::query()->create([
            'tenant_id' => $sysAdmin->tenant_id,
            'vendor_id' => $vendor->id,
            'document_id' => $doc->id,
            'invoice_number' => 'INV-EVO-701',
            'invoice_date' => '2026-11-25',
            'currency' => 'USD',
            'subtotal_cents' => 50000,
            'vat_cents' => 7500,
            'total_cents' => 57500,
            'status' => ApInvoiceStatus::Approved,
            'match_mode' => '3_way',
        ]);

        return $inv->id;
    });

    $this->post("/admin/integrations/bridge-agents/{$agent->id}/jobs", ['job_type' => 'push_invoices'])
        ->assertRedirect();

    $pollPush = $this->withHeader('X-BetterDoc-Bridge-Token', $bridgeToken)
        ->postJson('/api/v1/bridge/poll')
        ->assertOk();

    $pushJobId = (string) $pollPush->json('job.id');
    $pushedInvNumbers = collect($pollPush->json('job.payload.invoices'))->pluck('invoice_number')->all();
    expect($pushedInvNumbers)->toContain('INV-EVO-701');

    $this->withHeader('X-BetterDoc-Bridge-Token', $bridgeToken)
        ->postJson("/api/v1/bridge/jobs/{$pushJobId}/complete", [
            'status' => 'completed',
            'result' => [
                'posted_invoices' => [[
                    'invoice_id' => $invoiceId,
                    'external_id' => 'SAGE-POST-990701',
                ]],
            ],
        ])
        ->assertOk();

    app(DbSecurityContext::class)->runAs($sysAdmin, function () use ($sysAdmin, $invoiceId): void {
        expect(Vendor::query()->where('tenant_id', $sysAdmin->tenant_id)->where('code', 'EVO701')->exists())->toBeTrue()
            ->and(HrEmployeeFile::query()->where('tenant_id', $sysAdmin->tenant_id)->where('employee_number', 'BEL-LAN-301')->exists())->toBeTrue()
            ->and(ApInvoice::query()->findOrFail($invoiceId)->status)->toBe(ApInvoiceStatus::Exported)
            ->and(ExternalMapping::query()
                ->where('tenant_id', $sysAdmin->tenant_id)
                ->where('external_system', ErpSystemType::SagePastel->value)
                ->where('entity_type', 'ap_invoice')
                ->where('external_id', 'SAGE-POST-990701')
                ->exists())->toBeTrue();
    });

    // 6. Revoking the bridge agent immediately invalidates its token
    $this->post("/admin/integrations/bridge-agents/{$agent->id}/revoke")->assertRedirect();

    $this->withHeader('X-BetterDoc-Bridge-Token', $bridgeToken)
        ->postJson('/api/v1/bridge/poll')
        ->assertUnauthorized();
});
