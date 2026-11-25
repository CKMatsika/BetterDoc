<?php

declare(strict_types=1);

use App\Enums\ApInvoiceStatus;
use App\Enums\ErpSystemType;
use App\Models\ApInvoice;
use App\Models\Document;
use App\Models\ExternalMapping;
use App\Models\IntegrationConnection;
use App\Models\IntegrationSyncRun;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\CorporateSeeder;
use Illuminate\Support\Facades\Http;

it('executes Xero OAuth2 test, vendor pull, PO pull, and approved AP bill push while honoring air-gap blocks', function (): void {
    $this->seed([CorporateSeeder::class]);

    /** @var User $sysAdmin */
    $sysAdmin = User::query()->where('email', 'admin@zimcorp.co.zw')->firstOrFail();
    /** @var User $clerk */
    $clerk = User::query()->where('email', 'clerk@zimcorp.co.zw')->firstOrFail();

    $this->signIn($sysAdmin);

    $this->post('/admin/integrations/connections', [
        'name' => 'Xero ZimCorp Ledger',
        'system_type' => ErpSystemType::Xero->value,
        'transport' => 'rest_api',
        'access_token' => 'xero-oauth2-demo-token',
        'xero_tenant_id' => 'xero-org-uuid-001',
        'default_expense_account' => '420',
        'default_tax_type' => 'INPUT2',
    ])->assertRedirect();

    /** @var IntegrationConnection $connection */
    $connection = app(DbSecurityContext::class)->runAs($sysAdmin, static fn () => IntegrationConnection::query()
        ->where('tenant_id', $sysAdmin->tenant_id)
        ->where('system_type', ErpSystemType::Xero->value)
        ->firstOrFail());

    // 1. Non-SystemAdmin cannot trigger sync
    $this->signIn($clerk);
    $this->post("/admin/integrations/connections/{$connection->id}/sync", ['direction' => 'test_connection'])->assertForbidden();

    // 2. Air-gapped mode without api.xero.com in external allowlist safely logs blocked_airgap
    $this->signIn($sysAdmin);
    config()->set('betterdoc.air_gapped', true);
    config()->set('betterdoc.external_allowlist_hosts', []);

    $this->post("/admin/integrations/connections/{$connection->id}/sync", ['direction' => 'test_connection'])
        ->assertRedirect()
        ->assertSessionHas('error');

    app(DbSecurityContext::class)->runAs($sysAdmin, function () use ($connection): void {
        /** @var IntegrationSyncRun $blocked */
        $blocked = IntegrationSyncRun::query()->where('integration_connection_id', $connection->id)->latest()->firstOrFail();
        expect($blocked->status)->toBe('blocked_airgap');
    });

    // 3. Allowlist api.xero.com and verify test_connection, pull_vendors, pull_purchase_orders, push_invoices
    config()->set('betterdoc.external_allowlist_hosts', ['api.xero.com']);
    Http::fake([
        'https://api.xero.com/api.xro/2.0/Organisation' => Http::response([
            'Organisations' => [['Name' => 'ZimCorp Xero Live', 'ShortCode' => '!ZC01']],
        ], 200),
        'https://api.xero.com/api.xro/2.0/Contacts*' => Http::response([
            'Contacts' => [[
                'ContactID' => 'xero-contact-55',
                'AccountNumber' => 'XSUP-55',
                'Name' => 'Zimbabwe Fleet Parts Ltd',
                'TaxNumber' => '20009911',
                'DefaultCurrency' => 'USD',
                'EmailAddress' => 'ap@fleetparts.co.zw',
            ]],
        ], 200),
        'https://api.xero.com/api.xro/2.0/PurchaseOrders*' => Http::response([
            'PurchaseOrders' => [[
                'PurchaseOrderID' => 'xero-po-77',
                'PurchaseOrderNumber' => 'PO-XERO-77',
                'CurrencyCode' => 'USD',
                'DateString' => '2026-11-20',
                'Contact' => ['ContactID' => 'xero-contact-55', 'Name' => 'Zimbabwe Fleet Parts Ltd'],
                'LineItems' => [[
                    'ItemCode' => 'TYRE-01',
                    'Description' => 'Heavy Truck Tyres',
                    'Quantity' => 4,
                    'UnitAmount' => 250.00,
                ]],
            ]],
        ], 200),
        'https://api.xero.com/api.xro/2.0/Invoices' => Http::response([
            'Invoices' => [['InvoiceID' => 'xero-bill-991']],
        ], 200),
    ]);

    $this->post("/admin/integrations/connections/{$connection->id}/sync", ['direction' => 'test_connection'])
        ->assertRedirect()->assertSessionHas('success');
    $this->post("/admin/integrations/connections/{$connection->id}/sync", ['direction' => 'pull_vendors'])
        ->assertRedirect()->assertSessionHas('success');
    $this->post("/admin/integrations/connections/{$connection->id}/sync", ['direction' => 'pull_purchase_orders'])
        ->assertRedirect()->assertSessionHas('success');

    // Seed an approved AP invoice for the pulled Xero vendor and push it back
    $invoiceId = app(DbSecurityContext::class)->runAs($sysAdmin, function () use ($sysAdmin): string {
        /** @var Vendor $vendor */
        $vendor = Vendor::query()->where('tenant_id', $sysAdmin->tenant_id)->where('code', 'XSUP-55')->firstOrFail();
        /** @var Document $doc */
        $doc = Document::query()->where('tenant_id', $sysAdmin->tenant_id)->firstOrFail();

        /** @var ApInvoice $inv */
        $inv = ApInvoice::query()->create([
            'tenant_id' => $sysAdmin->tenant_id,
            'vendor_id' => $vendor->id,
            'document_id' => $doc->id,
            'invoice_number' => 'INV-XERO-991',
            'invoice_date' => '2026-11-24',
            'currency' => 'USD',
            'subtotal_cents' => 100000,
            'vat_cents' => 15000,
            'total_cents' => 115000,
            'status' => ApInvoiceStatus::Approved,
            'match_mode' => '3_way',
        ]);

        return $inv->id;
    });

    $this->post("/admin/integrations/connections/{$connection->id}/sync", ['direction' => 'push_invoices'])
        ->assertRedirect()->assertSessionHas('success');

    Http::assertSent(function ($request): bool {
        if ($request->url() !== 'https://api.xero.com/api.xro/2.0/Invoices') {
            return false;
        }
        $data = $request->data();
        $inv = $data['Invoices'][0] ?? [];

        return ($request->header('xero-tenant-id')[0] ?? '') === 'xero-org-uuid-001'
            && ($inv['Type'] ?? '') === 'ACCPAY'
            && ($inv['Contact']['ContactID'] ?? '') === 'xero-contact-55'
            && ($inv['LineItems'][0]['AccountCode'] ?? '') === '420'
            && ($inv['LineItems'][0]['TaxType'] ?? '') === 'INPUT2';
    });

    app(DbSecurityContext::class)->runAs($sysAdmin, function () use ($sysAdmin, $invoiceId): void {
        expect(PurchaseOrder::query()->where('tenant_id', $sysAdmin->tenant_id)->where('po_number', 'PO-XERO-77')->exists())->toBeTrue()
            ->and(ApInvoice::query()->findOrFail($invoiceId)->status)->toBe(ApInvoiceStatus::Exported)
            ->and(ExternalMapping::query()
                ->where('tenant_id', $sysAdmin->tenant_id)
                ->where('external_system', ErpSystemType::Xero->value)
                ->where('entity_type', 'ap_invoice')
                ->where('external_id', 'xero-bill-991')
                ->exists())->toBeTrue();
    });
});

it('executes NetSuite SuiteTalk OAuth 1.0a TBA sync for vendors, POs, and vendorBill push with subsidiary mapping', function (): void {
    $this->seed([CorporateSeeder::class]);

    /** @var User $sysAdmin */
    $sysAdmin = User::query()->where('email', 'admin@zimcorp.co.zw')->firstOrFail();
    $this->signIn($sysAdmin);

    $this->post('/admin/integrations/connections', [
        'name' => 'Oracle NetSuite Enterprise',
        'system_type' => ErpSystemType::Netsuite->value,
        'transport' => 'rest_api',
        'account_id' => '1234567_SB1',
        'consumer_key' => 'ns-consumer-key',
        'consumer_secret' => 'ns-consumer-secret',
        'token_id' => 'ns-token-id',
        'token_secret' => 'ns-token-secret',
        'subsidiary_id' => '3',
        'default_expense_account' => '6050',
    ])->assertRedirect();

    /** @var IntegrationConnection $connection */
    $connection = app(DbSecurityContext::class)->runAs($sysAdmin, static fn () => IntegrationConnection::query()
        ->where('tenant_id', $sysAdmin->tenant_id)
        ->where('system_type', ErpSystemType::Netsuite->value)
        ->firstOrFail());

    config()->set('betterdoc.external_allowlist_hosts', ['1234567-sb1.suitetalk.api.netsuite.com']);

    Http::fake([
        'https://1234567-sb1.suitetalk.api.netsuite.com/services/rest/record/v1/vendorBill' => Http::response(
            ['id' => 'ns-bill-4402'],
            200,
            ['Location' => 'https://1234567-sb1.suitetalk.api.netsuite.com/services/rest/record/v1/vendorBill/ns-bill-4402']
        ),
        'https://1234567-sb1.suitetalk.api.netsuite.com/services/rest/record/v1/vendor?*' => Http::response([
            'totalResults' => 1,
            'items' => [[
                'id' => '8801',
                'entityId' => 'NS-VEND-88',
                'companyName' => 'Southern Africa Cloud Logistics',
                'taxIdNum' => '20007788',
                'currency' => ['refName' => 'USD'],
                'email' => 'billing@saclogistics.co.zw',
            ]],
        ], 200),
        'https://1234567-sb1.suitetalk.api.netsuite.com/services/rest/record/v1/purchaseOrder*' => Http::response([
            'items' => [[
                'id' => '9901',
                'tranId' => 'PO-NS-9901',
                'tranDate' => '2026-11-21',
                'entity' => ['id' => '8801', 'refName' => 'Southern Africa Cloud Logistics'],
                'currency' => ['refName' => 'USD'],
                'item' => ['items' => [[
                    'item' => ['refName' => 'SRV-01'],
                    'description' => 'Freight Forwarding',
                    'quantity' => 2,
                    'rate' => 500.00,
                ]]],
            ]],
        ], 200),
    ]);

    $this->post("/admin/integrations/connections/{$connection->id}/sync", ['direction' => 'test_connection'])
        ->assertRedirect()->assertSessionHas('success');
    $this->post("/admin/integrations/connections/{$connection->id}/sync", ['direction' => 'pull_vendors'])
        ->assertRedirect()->assertSessionHas('success');
    $this->post("/admin/integrations/connections/{$connection->id}/sync", ['direction' => 'pull_purchase_orders'])
        ->assertRedirect()->assertSessionHas('success');

    $invoiceId = app(DbSecurityContext::class)->runAs($sysAdmin, function () use ($sysAdmin): string {
        /** @var Vendor $vendor */
        $vendor = Vendor::query()->where('tenant_id', $sysAdmin->tenant_id)->where('code', 'NS-VEND-88')->firstOrFail();
        /** @var Document $doc */
        $doc = Document::query()->where('tenant_id', $sysAdmin->tenant_id)->firstOrFail();

        /** @var ApInvoice $inv */
        $inv = ApInvoice::query()->create([
            'tenant_id' => $sysAdmin->tenant_id,
            'vendor_id' => $vendor->id,
            'document_id' => $doc->id,
            'invoice_number' => 'INV-NS-4402',
            'invoice_date' => '2026-11-24',
            'currency' => 'USD',
            'subtotal_cents' => 100000,
            'vat_cents' => 15000,
            'total_cents' => 115000,
            'status' => ApInvoiceStatus::Approved,
            'match_mode' => '3_way',
        ]);

        return $inv->id;
    });

    $this->post("/admin/integrations/connections/{$connection->id}/sync", ['direction' => 'push_invoices'])
        ->assertRedirect()->assertSessionHas('success');

    Http::assertSent(function ($request): bool {
        if ($request->url() !== 'https://1234567-sb1.suitetalk.api.netsuite.com/services/rest/record/v1/vendorBill') {
            return false;
        }
        $authHeader = $request->header('Authorization')[0] ?? '';
        $data = $request->data();

        return str_starts_with($authHeader, 'OAuth realm="1234567_SB1"')
            && str_contains($authHeader, 'oauth_signature_method="HMAC-SHA256"')
            && ($data['subsidiary']['id'] ?? '') === '3'
            && ($data['entity']['id'] ?? '') === '8801'
            && ($data['expense']['items'][0]['account']['id'] ?? '') === '6050';
    });

    app(DbSecurityContext::class)->runAs($sysAdmin, function () use ($sysAdmin, $invoiceId): void {
        expect(PurchaseOrder::query()->where('tenant_id', $sysAdmin->tenant_id)->where('po_number', 'PO-NS-9901')->exists())->toBeTrue()
            ->and(ApInvoice::query()->findOrFail($invoiceId)->status)->toBe(ApInvoiceStatus::Exported)
            ->and(ExternalMapping::query()
                ->where('tenant_id', $sysAdmin->tenant_id)
                ->where('external_system', ErpSystemType::Netsuite->value)
                ->where('entity_type', 'ap_invoice')
                ->where('external_id', 'ns-bill-4402')
                ->exists())->toBeTrue();
    });
});
