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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class NetSuiteErpConnector implements ErpConnectorInterface
{
    public function supports(): ErpSystemType
    {
        return ErpSystemType::Netsuite;
    }

    public function testConnection(IntegrationConnection $connection): array
    {
        $url = $this->baseUrl($connection).'/vendor?limit=1';
        $response = $this->send($connection, 'GET', $url);

        if (! $response->successful()) {
            throw new RuntimeException('NetSuite SuiteTalk API returned HTTP '.$response->status().': '.substr($response->body(), 0, 240));
        }

        $body = $response->json();
        $total = is_array($body) && is_numeric($body['totalResults'] ?? null) ? (int) $body['totalResults'] : 0;

        return [
            'ok' => true,
            'message' => "Connected to NetSuite SuiteTalk REST API ({$total} vendor records accessible).",
            'details' => ['total_vendors' => $total],
        ];
    }

    public function pullVendors(IntegrationConnection $connection, User $actor): array
    {
        $url = $this->baseUrl($connection).'/vendor?limit=100';
        $response = $this->send($connection, 'GET', $url);

        if (! $response->successful()) {
            throw new RuntimeException('NetSuite vendor pull failed (HTTP '.$response->status().').');
        }

        $body = $response->json();
        $items = is_array($body) && is_array($body['items'] ?? null) ? $body['items'] : [];
        $processed = 0;
        $failed = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                $failed++;

                continue;
            }

            $externalId = $this->strVal($item['id'] ?? null);
            $name = $this->strVal($item['companyName'] ?? null, $this->strVal($item['entityId'] ?? null));
            if ($externalId === '' || $name === '') {
                $failed++;

                continue;
            }

            $rawCode = $this->strVal($item['entityId'] ?? null);
            $code = strtoupper($rawCode !== '' ? substr(preg_replace('/[^A-Za-z0-9_-]/', '', $rawCode) ?: "NS-{$externalId}", 0, 32) : "NS-{$externalId}");
            $currencyNode = is_array($item['currency'] ?? null) ? $item['currency'] : [];
            $currency = strtoupper($this->strVal($currencyNode['refName'] ?? null, 'USD'));
            $taxId = $this->strVal($item['taxIdNum'] ?? null);
            $email = $this->strVal($item['email'] ?? null);

            /** @var Vendor $vendor */
            $vendor = Vendor::query()->updateOrCreate(
                ['tenant_id' => $actor->tenant_id, 'code' => $code],
                [
                    'name' => substr($name, 0, 160),
                    'tax_id_tin' => $taxId !== '' ? substr($taxId, 0, 48) : null,
                    'currency' => $currency !== '' ? substr($currency, 0, 8) : 'USD',
                    'email' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                    'is_active' => true,
                ]
            );

            ExternalMapping::query()->updateOrCreate(
                [
                    'tenant_id' => $actor->tenant_id,
                    'external_system' => ErpSystemType::Netsuite->value,
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
            'details' => ['source_count' => count($items)],
        ];
    }

    public function pullPurchaseOrders(IntegrationConnection $connection, User $actor): array
    {
        $url = $this->baseUrl($connection).'/purchaseOrder?limit=50';
        $response = $this->send($connection, 'GET', $url);

        if (! $response->successful()) {
            throw new RuntimeException('NetSuite purchaseOrder pull failed (HTTP '.$response->status().').');
        }

        $body = $response->json();
        $items = is_array($body) && is_array($body['items'] ?? null) ? $body['items'] : [];
        $processed = 0;
        $failed = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                $failed++;

                continue;
            }

            $externalPoId = $this->strVal($item['id'] ?? null);
            $poNumber = strtoupper($this->strVal($item['tranId'] ?? null));
            if ($externalPoId === '' || $poNumber === '') {
                $failed++;

                continue;
            }

            $entity = is_array($item['entity'] ?? null) ? $item['entity'] : [];
            $vendorExtId = $this->strVal($entity['id'] ?? null);
            $vendorName = $this->strVal($entity['refName'] ?? null, 'NetSuite Vendor');
            $vendor = $this->resolveOrCreateVendor($actor, $connection, $vendorExtId, $vendorName);

            $currencyNode = is_array($item['currency'] ?? null) ? $item['currency'] : [];
            $currency = strtoupper($this->strVal($currencyNode['refName'] ?? null, $vendor->currency));
            $tranDate = substr($this->strVal($item['tranDate'] ?? null, now('UTC')->toDateString()), 0, 10);
            $itemNode = is_array($item['item'] ?? null) ? $item['item'] : [];
            $rawLines = is_array($itemNode['items'] ?? null) ? $itemNode['items'] : [];

            DB::transaction(function () use ($actor, $connection, $vendor, $externalPoId, $poNumber, $currency, $tranDate, $rawLines): void {
                $subtotal = 0;
                $parsedLines = [];

                foreach ($rawLines as $idx => $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    $qty = max(0.001, $this->floatVal($line['quantity'] ?? null, 1.0));
                    $unitPriceCents = max(0, (int) round($this->floatVal($line['rate'] ?? null, 0.0) * 100));
                    $subtotal += (int) round($qty * $unitPriceCents);
                    $skuNode = is_array($line['item'] ?? null) ? $line['item'] : [];
                    $parsedLines[] = [
                        'line_number' => $idx + 1,
                        'sku' => substr($this->strVal($skuNode['refName'] ?? null, 'NS-ITEM-'.($idx + 1)), 0, 64),
                        'description' => substr($this->strVal($line['description'] ?? null, 'NetSuite PO Line'), 0, 200),
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
                        'issued_at' => $tranDate,
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
                        'external_system' => ErpSystemType::Netsuite->value,
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
            'details' => ['source_count' => count($items)],
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

        $subsidiaryId = $this->mapping($connection, 'subsidiary_id', '');
        $expenseAccount = $this->mapping($connection, 'default_expense_account', '6010');
        $processed = 0;
        $failed = 0;
        $pushedIds = [];

        /** @var ApInvoice $invoice */
        foreach ($invoices as $invoice) {
            /** @var ExternalMapping|null $vendorMapping */
            $vendorMapping = ExternalMapping::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('external_system', ErpSystemType::Netsuite->value)
                ->where('entity_type', 'vendor')
                ->where('internal_id', $invoice->vendor_id)
                ->first();

            $expenseItems = [];
            foreach ($invoice->lines as $line) {
                $expenseItems[] = [
                    'account' => ['id' => $expenseAccount],
                    'amount' => round($line->line_total_cents / 100, 2),
                    'memo' => $line->description,
                ];
            }

            if ($expenseItems === []) {
                $expenseItems[] = [
                    'account' => ['id' => $expenseAccount],
                    'amount' => round($invoice->subtotal_cents / 100, 2),
                    'memo' => "AP Invoice {$invoice->invoice_number}",
                ];
            }

            $payload = [
                'entity' => ['id' => $vendorMapping !== null ? $vendorMapping->external_id : ($invoice->vendor->code ?? $invoice->vendor_id)],
                'tranId' => $invoice->invoice_number,
                'tranDate' => $invoice->invoice_date->toDateString(),
                'memo' => "BetterDoc DMS Document {$invoice->document_id}",
                'expense' => ['items' => $expenseItems],
            ];

            if ($subsidiaryId !== '') {
                $payload['subsidiary'] = ['id' => $subsidiaryId];
            }

            $url = $this->baseUrl($connection).'/vendorBill';
            $response = $this->send($connection, 'POST', $url, $payload);

            if (! $response->successful()) {
                $failed++;

                continue;
            }

            $respJson = $response->json();
            $location = $response->header('Location');
            $fromLocation = $location !== '' ? basename(parse_url($location, PHP_URL_PATH) ?: '') : '';
            $nsBillId = is_array($respJson) ? $this->strVal($respJson['id'] ?? null, $fromLocation !== '' ? $fromLocation : $invoice->invoice_number) : ($fromLocation !== '' ? $fromLocation : $invoice->invoice_number);

            ExternalMapping::query()->updateOrCreate(
                [
                    'tenant_id' => $actor->tenant_id,
                    'external_system' => ErpSystemType::Netsuite->value,
                    'entity_type' => 'ap_invoice',
                    'external_id' => $nsBillId,
                ],
                [
                    'integration_connection_id' => $connection->id,
                    'internal_id' => $invoice->id,
                    'metadata_json' => ['invoice_number' => $invoice->invoice_number],
                ]
            );

            $invoice->update(['status' => ApInvoiceStatus::Exported]);
            $pushedIds[] = $nsBillId;
            $processed++;
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'details' => ['netsuite_bill_ids' => $pushedIds],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $jsonBody
     */
    private function send(IntegrationConnection $connection, string $method, string $url, ?array $jsonBody = null): Response
    {
        $creds = $this->decodeCredentials($connection);
        $headers = ['Accept' => 'application/json'];

        if (($creds['consumer_key'] ?? '') !== '' && ($creds['token_id'] ?? '') !== '') {
            $headers['Authorization'] = $this->buildOAuth1Header($method, $url, $creds);
        } elseif (($creds['access_token'] ?? '') !== '' || ($creds['api_key'] ?? '') !== '') {
            $token = $creds['access_token'] ?? $creds['api_key'] ?? '';
            $headers['Authorization'] = "Bearer {$token}";
        } else {
            throw new RuntimeException('NetSuite connection requires OAuth 1.0a TBA credentials or OAuth 2.0 access_token.');
        }

        $req = Http::withHeaders($headers)->timeout(15);

        return strtoupper($method) === 'POST'
            ? $req->post($url, $jsonBody ?? [])
            : $req->get($url);
    }

    /**
     * Compute RFC 5849 OAuth 1.0a HMAC-SHA256 Authorization header required by NetSuite Token-Based Auth (TBA).
     *
     * @param  array<string, string>  $creds
     */
    private function buildOAuth1Header(string $method, string $url, array $creds): string
    {
        $realm = strtoupper(str_replace('-', '_', trim($creds['account_id'] ?? '')));
        $consumerKey = trim($creds['consumer_key'] ?? '');
        $consumerSecret = trim($creds['consumer_secret'] ?? '');
        $tokenId = trim($creds['token_id'] ?? '');
        $tokenSecret = trim($creds['token_secret'] ?? '');

        $oauthParams = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => Str::random(24),
            'oauth_signature_method' => 'HMAC-SHA256',
            'oauth_timestamp' => (string) now('UTC')->timestamp,
            'oauth_token' => $tokenId,
            'oauth_version' => '1.0',
        ];

        $parts = parse_url($url);
        $baseUrl = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '');
        $queryParams = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $parsedQuery);
            foreach ($parsedQuery as $qk => $qv) {
                if (is_string($qk) && is_scalar($qv)) {
                    $queryParams[$qk] = (string) $qv;
                }
            }
        }

        $sigParams = array_merge($queryParams, $oauthParams);
        ksort($sigParams);

        $encodedPairs = [];
        foreach ($sigParams as $k => $v) {
            $encodedPairs[] = rawurlencode($k).'='.rawurlencode($v);
        }

        $baseString = strtoupper($method).'&'.rawurlencode($baseUrl).'&'.rawurlencode(implode('&', $encodedPairs));
        $signingKey = rawurlencode($consumerSecret).'&'.rawurlencode($tokenSecret);
        $signature = base64_encode(hash_hmac('sha256', $baseString, $signingKey, true));

        $headerParts = [];
        if ($realm !== '') {
            $headerParts[] = 'realm="'.rawurlencode($realm).'"';
        }
        foreach ($oauthParams as $k => $v) {
            $headerParts[] = $k.'="'.rawurlencode($v).'"';
        }
        $headerParts[] = 'oauth_signature="'.rawurlencode($signature).'"';

        return 'OAuth '.implode(', ', $headerParts);
    }

    private function baseUrl(IntegrationConnection $connection): string
    {
        $custom = trim((string) ($connection->base_url ?? ''));
        if ($custom !== '') {
            return rtrim($custom, '/');
        }

        $creds = $this->decodeCredentials($connection);
        $accountSlug = strtolower(str_replace('_', '-', trim($creds['account_id'] ?? 'account')));

        return "https://{$accountSlug}.suitetalk.api.netsuite.com/services/rest/record/v1";
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
                ->where('external_system', ErpSystemType::Netsuite->value)
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

        $code = 'NS-'.strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $externalId !== '' ? $externalId : $name) ?: 'SUP', 0, 12));

        /** @var Vendor $vendor */
        $vendor = Vendor::query()->updateOrCreate(
            ['tenant_id' => $actor->tenant_id, 'code' => $code],
            ['name' => substr($name, 0, 160), 'currency' => 'USD', 'is_active' => true]
        );

        if ($externalId !== '') {
            ExternalMapping::query()->updateOrCreate(
                [
                    'tenant_id' => $actor->tenant_id,
                    'external_system' => ErpSystemType::Netsuite->value,
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
