<?php

declare(strict_types=1);

namespace App\Services\Integration\Connectors;

use App\Enums\ApInvoiceStatus;
use App\Enums\ErpSystemType;
use App\Enums\PurchaseOrderStatus;
use App\Models\ApInvoice;
use App\Models\ExternalMapping;
use App\Models\IntegrationConnection;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class XeroErpConnector implements ErpConnectorInterface
{
    public function supports(): ErpSystemType
    {
        return ErpSystemType::Xero;
    }

    public function testConnection(IntegrationConnection $connection): array
    {
        $response = $this->client($connection)->get($this->baseUrl($connection).'/Organisation');

        if (! $response->successful()) {
            throw new RuntimeException('Xero API returned HTTP '.$response->status().': '.substr($response->body(), 0, 240));
        }

        $body = $response->json();
        $orgs = is_array($body) && is_array($body['Organisations'] ?? null) ? $body['Organisations'] : [];
        $firstOrg = is_array($orgs[0] ?? null) ? $orgs[0] : [];
        $orgName = $this->strVal($firstOrg['Name'] ?? null, 'Xero Organisation');
        $shortCode = $this->strVal($firstOrg['ShortCode'] ?? null);

        return [
            'ok' => true,
            'message' => "Connected to Xero organisation '{$orgName}'.",
            'details' => [
                'organisation_name' => $orgName,
                'short_code' => $shortCode !== '' ? $shortCode : null,
            ],
        ];
    }

    public function pullVendors(IntegrationConnection $connection, User $actor): array
    {
        $response = $this->client($connection)->get($this->baseUrl($connection).'/Contacts', [
            'where' => 'IsSupplier==true',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Xero Contacts pull failed (HTTP '.$response->status().').');
        }

        $body = $response->json();
        $contacts = is_array($body) && is_array($body['Contacts'] ?? null) ? $body['Contacts'] : [];
        $processed = 0;
        $failed = 0;

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                $failed++;

                continue;
            }

            $externalId = $this->strVal($contact['ContactID'] ?? null);
            $name = $this->strVal($contact['Name'] ?? null);
            if ($externalId === '' || $name === '') {
                $failed++;

                continue;
            }

            $rawCode = $this->strVal($contact['AccountNumber'] ?? null);
            $code = strtoupper($rawCode !== '' ? substr($rawCode, 0, 32) : 'XERO-'.substr(str_replace('-', '', $externalId), 0, 8));
            $currency = strtoupper($this->strVal($contact['DefaultCurrency'] ?? null, $this->mapping($connection, 'currency_fallback', 'USD')));
            $taxNumber = $this->strVal($contact['TaxNumber'] ?? null);
            $email = $this->strVal($contact['EmailAddress'] ?? null);

            /** @var Vendor $vendor */
            $vendor = Vendor::query()->updateOrCreate(
                ['tenant_id' => $actor->tenant_id, 'code' => $code],
                [
                    'name' => substr($name, 0, 160),
                    'tax_id_tin' => $taxNumber !== '' ? substr($taxNumber, 0, 48) : null,
                    'currency' => $currency !== '' ? substr($currency, 0, 8) : 'USD',
                    'email' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                    'is_active' => true,
                ]
            );

            ExternalMapping::query()->updateOrCreate(
                [
                    'tenant_id' => $actor->tenant_id,
                    'external_system' => ErpSystemType::Xero->value,
                    'entity_type' => 'vendor',
                    'external_id' => $externalId,
                ],
                [
                    'integration_connection_id' => $connection->id,
                    'internal_id' => $vendor->id,
                    'metadata_json' => ['code' => $vendor->code, 'name' => $vendor->name],
                ]
            );

            $processed++;
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'details' => ['source_count' => count($contacts)],
        ];
    }

    public function pullPurchaseOrders(IntegrationConnection $connection, User $actor): array
    {
        $response = $this->client($connection)->get($this->baseUrl($connection).'/PurchaseOrders', [
            'status' => 'AUTHORISED',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Xero PurchaseOrders pull failed (HTTP '.$response->status().').');
        }

        $body = $response->json();
        $orders = is_array($body) && is_array($body['PurchaseOrders'] ?? null) ? $body['PurchaseOrders'] : [];
        $processed = 0;
        $failed = 0;

        foreach ($orders as $order) {
            if (! is_array($order)) {
                $failed++;

                continue;
            }

            $externalPoId = $this->strVal($order['PurchaseOrderID'] ?? null);
            $poNumber = strtoupper($this->strVal($order['PurchaseOrderNumber'] ?? null));
            if ($externalPoId === '' || $poNumber === '') {
                $failed++;

                continue;
            }

            $contact = is_array($order['Contact'] ?? null) ? $order['Contact'] : [];
            $contactId = $this->strVal($contact['ContactID'] ?? null);
            $contactName = $this->strVal($contact['Name'] ?? null, 'Xero Supplier');

            $vendor = $this->resolveOrCreateVendor($actor, $connection, $contactId, $contactName);
            $currency = strtoupper($this->strVal($order['CurrencyCode'] ?? null, $vendor->currency));
            $issuedAt = $this->strVal($order['DateString'] ?? null, now('UTC')->toDateString());
            $rawLines = is_array($order['LineItems'] ?? null) ? $order['LineItems'] : [];

            DB::transaction(function () use ($actor, $connection, $vendor, $externalPoId, $poNumber, $currency, $issuedAt, $rawLines): void {
                $subtotal = 0;
                $parsedLines = [];
                foreach ($rawLines as $idx => $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    $qty = max(0.001, $this->floatVal($line['Quantity'] ?? null, 1.0));
                    $unitPriceCents = max(0, (int) round($this->floatVal($line['UnitAmount'] ?? null, 0.0) * 100));
                    $subtotal += (int) round($qty * $unitPriceCents);
                    $parsedLines[] = [
                        'line_number' => $idx + 1,
                        'sku' => substr($this->strVal($line['ItemCode'] ?? null, 'ITEM-'.($idx + 1)), 0, 64),
                        'description' => substr($this->strVal($line['Description'] ?? null, 'Xero PO Line'), 0, 200),
                        'qty_ordered' => $qty,
                        'unit_price_cents' => $unitPriceCents,
                    ];
                }

                $tax = (int) round($subtotal * 0.15);

                /** @var PurchaseOrder $po */
                $po = PurchaseOrder::query()->updateOrCreate(
                    ['tenant_id' => $actor->tenant_id, 'po_number' => $poNumber],
                    [
                        'vendor_id' => $vendor->id,
                        'created_by' => $actor->id,
                        'status' => PurchaseOrderStatus::Open,
                        'currency' => $currency !== '' ? substr($currency, 0, 8) : 'USD',
                        'subtotal_cents' => $subtotal,
                        'tax_cents' => $tax,
                        'total_cents' => $subtotal + $tax,
                        'issued_at' => substr($issuedAt, 0, 10),
                    ]
                );

                PurchaseOrderLine::query()->where('purchase_order_id', $po->id)->delete();
                foreach ($parsedLines as $pl) {
                    PurchaseOrderLine::query()->create([
                        'tenant_id' => $actor->tenant_id,
                        'purchase_order_id' => $po->id,
                        'line_number' => $pl['line_number'],
                        'sku' => $pl['sku'],
                        'description' => $pl['description'],
                        'qty_ordered' => $pl['qty_ordered'],
                        'unit_price_cents' => $pl['unit_price_cents'],
                        'tax_rate_bps' => 1500,
                    ]);
                }

                ExternalMapping::query()->updateOrCreate(
                    [
                        'tenant_id' => $actor->tenant_id,
                        'external_system' => ErpSystemType::Xero->value,
                        'entity_type' => 'purchase_order',
                        'external_id' => $externalPoId,
                    ],
                    [
                        'integration_connection_id' => $connection->id,
                        'internal_id' => $po->id,
                        'metadata_json' => ['po_number' => $po->po_number],
                    ]
                );
            });

            $processed++;
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'details' => ['source_count' => count($orders)],
        ];
    }

    public function pushApprovedInvoices(IntegrationConnection $connection, User $actor): array
    {
        $invoices = ApInvoice::query()
            ->with(['vendor', 'lines'])
            ->where('tenant_id', $actor->tenant_id)
            ->whereIn('status', [ApInvoiceStatus::Approved->value, ApInvoiceStatus::Matched->value])
            ->orderBy('invoice_date')
            ->limit(50)
            ->get();

        $accountCode = $this->mapping($connection, 'default_expense_account', '400');
        $taxType = $this->mapping($connection, 'default_tax_type', 'INPUT2');
        $processed = 0;
        $failed = 0;
        $pushedIds = [];

        /** @var ApInvoice $invoice */
        foreach ($invoices as $invoice) {
            /** @var ExternalMapping|null $vendorMapping */
            $vendorMapping = ExternalMapping::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('external_system', ErpSystemType::Xero->value)
                ->where('entity_type', 'vendor')
                ->where('internal_id', $invoice->vendor_id)
                ->first();

            $contactPayload = $vendorMapping !== null
                ? ['ContactID' => $vendorMapping->external_id]
                : ['Name' => $invoice->vendor->name ?? 'BetterDoc Vendor'];

            $lineItems = [];
            foreach ($invoice->lines as $line) {
                $lineItems[] = [
                    'Description' => $line->description,
                    'Quantity' => $line->qty_invoiced,
                    'UnitAmount' => round($line->unit_price_cents / 100, 2),
                    'AccountCode' => $accountCode,
                    'TaxType' => $taxType,
                ];
            }

            if ($lineItems === []) {
                $lineItems[] = [
                    'Description' => "AP Invoice {$invoice->invoice_number}",
                    'Quantity' => 1,
                    'UnitAmount' => round($invoice->subtotal_cents / 100, 2),
                    'AccountCode' => $accountCode,
                    'TaxType' => $taxType,
                ];
            }

            $payload = [
                'Invoices' => [[
                    'Type' => 'ACCPAY',
                    'Contact' => $contactPayload,
                    'InvoiceNumber' => $invoice->invoice_number,
                    'Reference' => "BetterDoc:{$invoice->document_id}",
                    'Date' => $invoice->invoice_date->toDateString(),
                    'DueDate' => $invoice->due_date?->toDateString() ?? $invoice->invoice_date->copy()->addDays(30)->toDateString(),
                    'CurrencyCode' => $invoice->currency,
                    'Status' => 'AUTHORISED',
                    'LineItems' => $lineItems,
                ]],
            ];

            $response = $this->client($connection)->post($this->baseUrl($connection).'/Invoices', $payload);

            if (! $response->successful()) {
                $failed++;

                continue;
            }

            $respJson = $response->json();
            $createdList = is_array($respJson) && is_array($respJson['Invoices'] ?? null) ? $respJson['Invoices'] : [];
            $firstCreated = is_array($createdList[0] ?? null) ? $createdList[0] : [];
            $xeroInvoiceId = $this->strVal($firstCreated['InvoiceID'] ?? null, $invoice->invoice_number);

            ExternalMapping::query()->updateOrCreate(
                [
                    'tenant_id' => $actor->tenant_id,
                    'external_system' => ErpSystemType::Xero->value,
                    'entity_type' => 'ap_invoice',
                    'external_id' => $xeroInvoiceId,
                ],
                [
                    'integration_connection_id' => $connection->id,
                    'internal_id' => $invoice->id,
                    'metadata_json' => ['invoice_number' => $invoice->invoice_number],
                ]
            );

            $invoice->update(['status' => ApInvoiceStatus::Exported]);
            $pushedIds[] = $xeroInvoiceId;
            $processed++;
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'details' => ['xero_invoice_ids' => $pushedIds],
        ];
    }

    private function client(IntegrationConnection $connection): PendingRequest
    {
        $creds = $this->decodeCredentials($connection);
        $accessToken = trim($creds['access_token'] ?? $creds['api_key'] ?? '');
        $xeroTenantId = trim($creds['xero_tenant_id'] ?? '');

        if ($accessToken === '') {
            throw new RuntimeException('Xero connection is missing OAuth2 access_token.');
        }

        $headers = ['Accept' => 'application/json'];
        if ($xeroTenantId !== '') {
            $headers['xero-tenant-id'] = $xeroTenantId;
        }

        return Http::withToken($accessToken)->withHeaders($headers)->timeout(15);
    }

    private function baseUrl(IntegrationConnection $connection): string
    {
        $custom = trim((string) ($connection->base_url ?? ''));

        return rtrim($custom !== '' ? $custom : 'https://api.xero.com/api.xro/2.0', '/');
    }

    /**
     * @return array<string, string>
     */
    private function decodeCredentials(IntegrationConnection $connection): array
    {
        $raw = $connection->credentials_encrypted;
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && (is_string($v) || is_int($v))) {
                $out[$k] = (string) $v;
            }
        }

        return $out;
    }

    private function mapping(IntegrationConnection $connection, string $key, string $default): string
    {
        $map = $connection->field_mapping_json;
        if (is_array($map) && is_string($map[$key] ?? null) && trim($map[$key]) !== '') {
            return trim($map[$key]);
        }

        return $default;
    }

    private function resolveOrCreateVendor(User $actor, IntegrationConnection $connection, string $externalId, string $name): Vendor
    {
        if ($externalId !== '') {
            /** @var ExternalMapping|null $existingMap */
            $existingMap = ExternalMapping::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('external_system', ErpSystemType::Xero->value)
                ->where('entity_type', 'vendor')
                ->where('external_id', $externalId)
                ->first();

            if ($existingMap !== null) {
                $found = Vendor::query()->where('tenant_id', $actor->tenant_id)->where('id', $existingMap->internal_id)->first();
                if ($found instanceof Vendor) {
                    return $found;
                }
            }
        }

        $code = 'XERO-'.strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $externalId !== '' ? $externalId : $name) ?: 'SUP', 0, 12));

        /** @var Vendor $vendor */
        $vendor = Vendor::query()->updateOrCreate(
            ['tenant_id' => $actor->tenant_id, 'code' => $code],
            ['name' => substr($name, 0, 160), 'currency' => 'USD', 'is_active' => true]
        );

        if ($externalId !== '') {
            ExternalMapping::query()->updateOrCreate(
                [
                    'tenant_id' => $actor->tenant_id,
                    'external_system' => ErpSystemType::Xero->value,
                    'entity_type' => 'vendor',
                    'external_id' => $externalId,
                ],
                [
                    'integration_connection_id' => $connection->id,
                    'internal_id' => $vendor->id,
                ]
            );
        }

        return $vendor;
    }

    private function strVal(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : $default;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    private function floatVal(mixed $value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
