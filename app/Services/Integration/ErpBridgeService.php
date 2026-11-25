<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Enums\ApInvoiceStatus;
use App\Enums\BetterDocRole;
use App\Enums\ErpSystemType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\UserStatus;
use App\Models\ApInvoice;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\ErpBridgeAgent;
use App\Models\ErpBridgeJob;
use App\Models\ExternalMapping;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Hr\HrVaultService;
use App\Services\Security\DbSecurityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class ErpBridgeService
{
    public function __construct(
        private readonly DbSecurityContext $dbContext,
        private readonly HrVaultService $hr,
        private readonly IntegrationWebhookService $webhooks,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function targetOptions(): array
    {
        return [
            ['value' => 'sage_pastel_evolution', 'label' => 'Sage Pastel Evolution (MSSQL / Freedom API)'],
            ['value' => 'sage_pastel_partner', 'label' => 'Sage Pastel Partner (LAN Folder / CSV)'],
            ['value' => 'belina_payroll', 'label' => 'Belina Payroll & HR (On-Prem Windows)'],
            ['value' => 'custom_lan_erp', 'label' => 'Custom On-Premise LAN ERP'],
        ];
    }

    /**
     * @param  list<string>  $capabilities
     * @return array{agent: ErpBridgeAgent, pairing_code: string}
     */
    public function initiatePairing(
        User $actor,
        string $name,
        string $erpTarget,
        ?string $connectionId = null,
        array $capabilities = ['pull_vendors', 'pull_purchase_orders', 'push_invoices', 'pull_employees'],
        ?Request $request = null,
    ): array {
        $pairingCode = 'PAIR-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4));

        /** @var ErpBridgeAgent $agent */
        $agent = ErpBridgeAgent::query()->create([
            'tenant_id' => $actor->tenant_id,
            'integration_connection_id' => $connectionId,
            'created_by' => $actor->id,
            'name' => trim($name),
            'erp_target' => $erpTarget,
            'status' => 'pending_pairing',
            'pairing_code_hash' => hash('sha256', $pairingCode),
            'pairing_expires_at' => now()->addHours(24),
            'capabilities_json' => array_values(array_unique($capabilities)),
        ]);

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'integration.bridge_pair_initiated',
            resourceType: 'erp_bridge_agent',
            resourceId: $agent->id,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            after: ['name' => $agent->name, 'erp_target' => $agent->erp_target],
            request: $request,
        );

        return [
            'agent' => $agent,
            'pairing_code' => $pairingCode,
        ];
    }

    /**
     * Exchange a one-time pairing code for a long-lived machine Bearer token (`bdb_...`).
     *
     * @return array{agent: ErpBridgeAgent, bridge_token: string}|null
     */
    public function completePairing(string $pairingCode, string $hostname, string $agentVersion, ?string $ip = null): ?array
    {
        $resolved = $this->resolveAgentRef(pairingHash: hash('sha256', trim($pairingCode)), tokenHash: null);
        if ($resolved === null) {
            return null;
        }

        $plainToken = 'bdb_'.Str::random(48);

        /** @var ErpBridgeAgent|null $paired */
        $paired = $this->dbContext->runForTenant($resolved['tenant_id'], function () use ($resolved, $plainToken, $hostname, $agentVersion, $ip): ?ErpBridgeAgent {
            /** @var ErpBridgeAgent|null $agent */
            $agent = ErpBridgeAgent::query()->find($resolved['agent_id']);
            if ($agent === null || $agent->status !== 'pending_pairing') {
                return null;
            }

            $agent->update([
                'hostname' => substr(trim($hostname), 0, 160),
                'agent_version' => substr(trim($agentVersion), 0, 32),
                'status' => 'online',
                'pairing_code_hash' => null,
                'pairing_expires_at' => null,
                'api_token_hash' => hash('sha256', $plainToken),
                'last_heartbeat_at' => now(),
                'last_ip' => $ip,
            ]);

            $this->audit->log(
                tenantId: $agent->tenant_id,
                eventType: 'integration.bridge_paired',
                resourceType: 'erp_bridge_agent',
                resourceId: $agent->id,
                actor: null,
                classificationRank: 1,
                after: ['hostname' => $agent->hostname, 'agent_version' => $agent->agent_version, 'ip' => $ip],
            );

            return $agent->refresh();
        });

        if ($paired === null) {
            return null;
        }

        return [
            'agent' => $paired,
            'bridge_token' => $plainToken,
        ];
    }

    /**
     * Resolve and touch an active Windows LAN bridge agent by raw Bearer token.
     */
    public function authenticateToken(string $rawToken, ?string $ip = null): ?ErpBridgeAgent
    {
        $trimmed = trim($rawToken);
        if ($trimmed === '') {
            return null;
        }

        $resolved = $this->resolveAgentRef(pairingHash: null, tokenHash: hash('sha256', $trimmed));
        if ($resolved === null) {
            return null;
        }

        return $this->dbContext->runForTenant($resolved['tenant_id'], function () use ($resolved, $ip): ?ErpBridgeAgent {
            /** @var ErpBridgeAgent|null $agent */
            $agent = ErpBridgeAgent::query()->find($resolved['agent_id']);
            if ($agent === null || $agent->status === 'revoked') {
                return null;
            }

            $agent->update([
                'status' => 'online',
                'last_heartbeat_at' => now(),
                'last_ip' => $ip ?? $agent->last_ip,
            ]);

            return $agent->refresh();
        });
    }

    public function enqueueJob(User $actor, ErpBridgeAgent $agent, string $jobType, ?Request $request = null): ErpBridgeJob
    {
        $payload = [];

        if ($jobType === 'push_invoices') {
            $extSystem = $this->externalSystemForTarget($agent->erp_target);
            $vendorMap = ExternalMapping::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('external_system', $extSystem)
                ->where('entity_type', 'vendor')
                ->get()
                ->keyBy('internal_id');

            $invoices = ApInvoice::query()
                ->with(['vendor', 'lines', 'purchaseOrder'])
                ->where('tenant_id', $actor->tenant_id)
                ->whereIn('status', [ApInvoiceStatus::Approved->value, ApInvoiceStatus::Matched->value])
                ->orderBy('invoice_date')
                ->limit(50)
                ->get();

            $rows = [];
            /** @var ApInvoice $inv */
            foreach ($invoices as $inv) {
                $mappedVendor = $vendorMap->get($inv->vendor_id);
                $lines = [];
                foreach ($inv->lines as $line) {
                    $lines[] = [
                        'sku' => $line->sku,
                        'description' => $line->description,
                        'qty' => $line->qty_invoiced,
                        'unit_price' => round($line->unit_price_cents / 100, 2),
                        'line_total' => round($line->line_total_cents / 100, 2),
                    ];
                }

                $rows[] = [
                    'invoice_id' => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'invoice_date' => $inv->invoice_date->toDateString(),
                    'due_date' => $inv->due_date?->toDateString(),
                    'currency' => $inv->currency,
                    'subtotal' => round($inv->subtotal_cents / 100, 2),
                    'vat' => round($inv->vat_cents / 100, 2),
                    'total' => round($inv->total_cents / 100, 2),
                    'vendor_code' => $mappedVendor->external_id ?? $inv->vendor->code ?? '',
                    'vendor_name' => $inv->vendor->name ?? '',
                    'po_number' => $inv->purchaseOrder->po_number ?? null,
                    'document_id' => $inv->document_id,
                    'lines' => $lines,
                ];
            }

            $payload = [
                'erp_target' => $agent->erp_target,
                'invoices' => $rows,
            ];
        } else {
            $payload = [
                'erp_target' => $agent->erp_target,
                'requested_at' => now('UTC')->toIso8601String(),
            ];
        }

        /** @var ErpBridgeJob $job */
        $job = ErpBridgeJob::query()->create([
            'tenant_id' => $actor->tenant_id,
            'erp_bridge_agent_id' => $agent->id,
            'triggered_by' => $actor->id,
            'job_type' => $jobType,
            'status' => 'queued',
            'payload_json' => $payload,
        ]);

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'integration.bridge_job_queued',
            resourceType: 'erp_bridge_job',
            resourceId: $job->id,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            after: ['agent' => $agent->name, 'job_type' => $jobType],
            request: $request,
        );

        return $job;
    }

    public function claimNextJob(ErpBridgeAgent $agent): ?ErpBridgeJob
    {
        return $this->dbContext->runForTenant($agent->tenant_id, function () use ($agent): ?ErpBridgeJob {
            return DB::transaction(function () use ($agent): ?ErpBridgeJob {
                /** @var ErpBridgeJob|null $job */
                $job = ErpBridgeJob::query()
                    ->where('tenant_id', $agent->tenant_id)
                    ->where('erp_bridge_agent_id', $agent->id)
                    ->where('status', 'queued')
                    ->orderBy('created_at')
                    ->lockForUpdate()
                    ->first();

                if ($job === null) {
                    return null;
                }

                $job->update([
                    'status' => 'claimed',
                    'claimed_at' => now(),
                ]);

                return $job->refresh();
            });
        });
    }

    /**
     * Ingest structured results submitted back by the Windows LAN ERP Bridge Agent under PostgreSQL RLS.
     *
     * @param  array<string, mixed>  $resultData
     */
    public function completeJob(
        ErpBridgeAgent $agent,
        string $jobId,
        string $status,
        array $resultData = [],
        ?string $errorMessage = null,
    ): ?ErpBridgeJob {
        $actor = $this->resolveTenantAdminActor($agent);
        if ($actor === null) {
            return null;
        }

        return $this->dbContext->runAs($actor, function () use ($agent, $actor, $jobId, $status, $resultData, $errorMessage): ?ErpBridgeJob {
            /** @var ErpBridgeJob|null $job */
            $job = ErpBridgeJob::query()
                ->where('tenant_id', $agent->tenant_id)
                ->where('erp_bridge_agent_id', $agent->id)
                ->where('id', $jobId)
                ->first();

            if ($job === null) {
                return null;
            }

            $summary = $resultData;

            if ($status === 'completed') {
                $extSystem = $this->externalSystemForTarget($agent->erp_target);

                if ($job->job_type === 'pull_vendors') {
                    $summary['applied'] = $this->applyVendors($actor, $agent, $extSystem, $resultData['vendors'] ?? []);
                } elseif ($job->job_type === 'pull_purchase_orders') {
                    $summary['applied'] = $this->applyPurchaseOrders($actor, $agent, $extSystem, $resultData['purchase_orders'] ?? []);
                } elseif ($job->job_type === 'pull_employees') {
                    $summary['applied'] = $this->applyEmployees($actor, $agent, $extSystem, $resultData['employees'] ?? []);
                } elseif ($job->job_type === 'push_invoices') {
                    $summary['applied'] = $this->acknowledgePostedInvoices($actor, $agent, $extSystem, $resultData['posted_invoices'] ?? []);
                }
            }

            $job->update([
                'status' => $status === 'completed' ? 'completed' : 'failed',
                'result_json' => $summary,
                'error_message' => $errorMessage !== null ? substr($errorMessage, 0, 480) : null,
                'completed_at' => now(),
            ]);

            $this->audit->log(
                tenantId: $agent->tenant_id,
                eventType: 'integration.bridge_job_completed',
                resourceType: 'erp_bridge_job',
                resourceId: $job->id,
                actor: $actor,
                classificationRank: $actor->clearance_rank,
                after: ['agent' => $agent->name, 'job_type' => $job->job_type, 'status' => $job->status],
            );

            $this->webhooks->emit(
                tenantId: $agent->tenant_id,
                eventType: 'erp.sync_completed',
                data: [
                    'bridge_job_id' => $job->id,
                    'bridge_agent' => $agent->name,
                    'erp_target' => $agent->erp_target,
                    'job_type' => $job->job_type,
                    'status' => $job->status,
                ],
                actor: $actor,
            );

            return $job->refresh();
        });
    }

    public function revokeAgent(User $actor, ErpBridgeAgent $agent, ?Request $request = null): void
    {
        $agent->update([
            'status' => 'revoked',
            'pairing_code_hash' => null,
            'api_token_hash' => null,
        ]);

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'integration.bridge_revoked',
            resourceType: 'erp_bridge_agent',
            resourceId: $agent->id,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            after: ['name' => $agent->name],
            request: $request,
        );
    }

    /**
     * @return array{agent_id: string, tenant_id: string}|null
     */
    private function resolveAgentRef(?string $pairingHash, ?string $tokenHash): ?array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $rows = DB::select('SELECT agent_id, tenant_id FROM resolve_erp_bridge_agent(?, ?)', [$pairingHash, $tokenHash]);
            if ($rows === []) {
                return null;
            }
            $first = $rows[0];
            if (! is_object($first) || ! is_string($first->agent_id ?? null) || ! is_string($first->tenant_id ?? null)) {
                return null;
            }

            return [
                'agent_id' => $first->agent_id,
                'tenant_id' => $first->tenant_id,
            ];
        }

        $query = ErpBridgeAgent::query();
        if ($pairingHash !== null) {
            $query->where('pairing_code_hash', $pairingHash)->where('status', 'pending_pairing');
        } elseif ($tokenHash !== null) {
            $query->where('api_token_hash', $tokenHash)->where('status', '!=', 'revoked');
        } else {
            return null;
        }

        /** @var ErpBridgeAgent|null $found */
        $found = $query->first();

        return $found !== null ? ['agent_id' => $found->id, 'tenant_id' => $found->tenant_id] : null;
    }

    private function resolveTenantAdminActor(ErpBridgeAgent $agent): ?User
    {
        if ($agent->created_by !== null) {
            /** @var User|null $creator */
            $creator = User::query()->where('tenant_id', $agent->tenant_id)->where('id', $agent->created_by)->first();
            if ($creator !== null) {
                return $creator;
            }
        }

        /** @var User|null $fallback */
        $fallback = User::query()
            ->where('tenant_id', $agent->tenant_id)
            ->orderByDesc('clearance_rank')
            ->first();

        return $fallback;
    }

    private function externalSystemForTarget(string $erpTarget): string
    {
        return match ($erpTarget) {
            'belina_payroll' => ErpSystemType::Belina->value,
            'sage_pastel_evolution', 'sage_pastel_partner' => ErpSystemType::SagePastel->value,
            default => ErpSystemType::CustomWebhook->value,
        };
    }

    private function applyVendors(User $actor, ErpBridgeAgent $agent, string $extSystem, mixed $rawVendors): int
    {
        if (! is_array($rawVendors)) {
            return 0;
        }

        $count = 0;
        foreach ($rawVendors as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = strtoupper($this->strVal($row['vendor_code'] ?? $row['code'] ?? null));
            $name = $this->strVal($row['name'] ?? null);
            $extId = $this->strVal($row['external_id'] ?? null, $code);
            if ($code === '' || $name === '') {
                continue;
            }

            $tin = $this->strVal($row['tax_id_tin'] ?? null);
            $vat = $this->strVal($row['vat_number'] ?? null);
            $currency = strtoupper($this->strVal($row['currency'] ?? null, 'USD'));
            $email = $this->strVal($row['email'] ?? null);

            /** @var Vendor $vendor */
            $vendor = Vendor::query()->updateOrCreate(
                ['tenant_id' => $actor->tenant_id, 'code' => substr($code, 0, 32)],
                [
                    'name' => substr($name, 0, 160),
                    'tax_id_tin' => $tin !== '' ? substr($tin, 0, 48) : null,
                    'vat_number' => $vat !== '' ? substr($vat, 0, 48) : null,
                    'currency' => substr($currency, 0, 8),
                    'email' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                    'is_active' => true,
                ]
            );

            ExternalMapping::query()->updateOrCreate(
                [
                    'tenant_id' => $actor->tenant_id,
                    'external_system' => $extSystem,
                    'entity_type' => 'vendor',
                    'external_id' => $extId,
                ],
                [
                    'integration_connection_id' => $agent->integration_connection_id,
                    'internal_id' => $vendor->id,
                    'metadata_json' => ['source_bridge' => $agent->name, 'code' => $vendor->code],
                ]
            );
            $count++;
        }

        return $count;
    }

    private function applyPurchaseOrders(User $actor, ErpBridgeAgent $agent, string $extSystem, mixed $rawOrders): int
    {
        if (! is_array($rawOrders)) {
            return 0;
        }

        $count = 0;
        foreach ($rawOrders as $row) {
            if (! is_array($row)) {
                continue;
            }

            $poNumber = strtoupper($this->strVal($row['po_number'] ?? null));
            $vendorCode = strtoupper($this->strVal($row['vendor_code'] ?? null));
            if ($poNumber === '' || $vendorCode === '') {
                continue;
            }

            /** @var Vendor $vendor */
            $vendor = Vendor::query()->firstOrCreate(
                ['tenant_id' => $actor->tenant_id, 'code' => substr($vendorCode, 0, 32)],
                ['name' => substr($this->strVal($row['vendor_name'] ?? null, $vendorCode), 0, 160), 'currency' => 'USD', 'is_active' => true]
            );

            $rawLines = is_array($row['lines'] ?? null) ? $row['lines'] : [];
            $subtotal = 0;
            $parsedLines = [];
            foreach ($rawLines as $idx => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $qty = max(0.001, is_numeric($line['qty'] ?? null) ? (float) $line['qty'] : 1.0);
                $priceCents = max(0, (int) round((is_numeric($line['unit_price'] ?? null) ? (float) $line['unit_price'] : 0.0) * 100));
                $subtotal += (int) round($qty * $priceCents);
                $parsedLines[] = [
                    'line_number' => $idx + 1,
                    'sku' => substr($this->strVal($line['sku'] ?? null, 'LINE-'.($idx + 1)), 0, 64),
                    'description' => substr($this->strVal($line['description'] ?? null, 'LAN ERP PO Line'), 0, 200),
                    'qty_ordered' => $qty,
                    'unit_price_cents' => $priceCents,
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
                    'currency' => strtoupper($this->strVal($row['currency'] ?? null, $vendor->currency)),
                    'subtotal_cents' => $subtotal,
                    'tax_cents' => $tax,
                    'total_cents' => $subtotal + $tax,
                    'issued_at' => substr($this->strVal($row['issued_at'] ?? null, now('UTC')->toDateString()), 0, 10),
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
                    'external_system' => $extSystem,
                    'entity_type' => 'purchase_order',
                    'external_id' => $this->strVal($row['external_id'] ?? null, $poNumber),
                ],
                [
                    'integration_connection_id' => $agent->integration_connection_id,
                    'internal_id' => $po->id,
                    'metadata_json' => ['po_number' => $po->po_number],
                ]
            );
            $count++;
        }

        return $count;
    }

    private function applyEmployees(User $actor, ErpBridgeAgent $agent, string $extSystem, mixed $rawEmployees): int
    {
        if (! is_array($rawEmployees)) {
            return 0;
        }

        /** @var Tenant $tenant */
        $tenant = $actor->tenant()->firstOrFail();
        /** @var Cabinet $hrCabinet */
        $hrCabinet = Cabinet::query()->where('tenant_id', $tenant->id)->orderBy('default_classification_rank')->firstOrFail();
        /** @var ClearanceLevel $baseLevel */
        $baseLevel = ClearanceLevel::query()->where('tenant_id', $tenant->id)->orderBy('rank')->firstOrFail();

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $count = 0;
        foreach ($rawEmployees as $row) {
            if (! is_array($row)) {
                continue;
            }

            $empNo = $this->strVal($row['employee_number'] ?? null);
            $email = strtolower($this->strVal($row['email'] ?? null));
            $name = $this->strVal($row['name'] ?? null);
            if ($empNo === '' || $email === '' || $name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            /** @var User|null $employee */
            $employee = User::query()->where('tenant_id', $tenant->id)->where('email', $email)->first();
            if ($employee === null) {
                if (User::query()->where('email', $email)->exists()) {
                    continue;
                }

                /** @var User $employee */
                $employee = User::query()->create([
                    'tenant_id' => $tenant->id,
                    'org_unit_id' => $actor->org_unit_id,
                    'clearance_level_id' => $baseLevel->id,
                    'clearance_rank' => $baseLevel->rank,
                    'service_number' => $empNo,
                    'rank_title' => substr($this->strVal($row['job_title'] ?? null, 'Staff'), 0, 60),
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::password(24)),
                    'status' => UserStatus::Active,
                    'mfa_enforced' => false,
                ]);
                $employee->syncRoles([BetterDocRole::Reader->value]);
            }

            $profile = $this->hr->ensureEmployeeProfile(
                hrActor: $actor,
                employee: $employee,
                hrCabinet: $hrCabinet,
                employeeNumber: $empNo,
                departmentName: $this->strVal($row['department_name'] ?? null, 'General'),
                jobTitle: $this->strVal($row['job_title'] ?? null, 'Staff'),
                hireDate: $this->strVal($row['hire_date'] ?? null) !== '' ? $this->strVal($row['hire_date'] ?? null) : null,
            );

            ExternalMapping::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'external_system' => $extSystem,
                    'entity_type' => 'hr_employee',
                    'external_id' => $empNo,
                ],
                [
                    'integration_connection_id' => $agent->integration_connection_id,
                    'internal_id' => $profile->id,
                    'metadata_json' => ['source_bridge' => $agent->name, 'email' => $email],
                ]
            );
            $count++;
        }

        return $count;
    }

    private function acknowledgePostedInvoices(User $actor, ErpBridgeAgent $agent, string $extSystem, mixed $rawPosted): int
    {
        if (! is_array($rawPosted)) {
            return 0;
        }

        $count = 0;
        foreach ($rawPosted as $row) {
            if (! is_array($row)) {
                continue;
            }

            $invoiceId = $this->strVal($row['invoice_id'] ?? null);
            $externalRef = $this->strVal($row['external_id'] ?? $row['erp_reference'] ?? null);
            if ($invoiceId === '' || $externalRef === '') {
                continue;
            }

            /** @var ApInvoice|null $invoice */
            $invoice = ApInvoice::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('id', $invoiceId)
                ->first();

            if ($invoice === null) {
                continue;
            }

            $invoice->update(['status' => ApInvoiceStatus::Exported]);

            ExternalMapping::query()->updateOrCreate(
                [
                    'tenant_id' => $actor->tenant_id,
                    'external_system' => $extSystem,
                    'entity_type' => 'ap_invoice',
                    'external_id' => $externalRef,
                ],
                [
                    'integration_connection_id' => $agent->integration_connection_id,
                    'internal_id' => $invoice->id,
                    'metadata_json' => ['invoice_number' => $invoice->invoice_number, 'source_bridge' => $agent->name],
                ]
            );
            $count++;
        }

        return $count;
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
}
