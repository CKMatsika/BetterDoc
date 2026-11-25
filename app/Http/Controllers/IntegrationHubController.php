<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BetterDocRole;
use App\Enums\ErpSystemType;
use App\Models\ErpBridgeAgent;
use App\Models\ErpBridgeJob;
use App\Models\ExternalMapping;
use App\Models\IntegrationConnection;
use App\Models\IntegrationDelivery;
use App\Models\IntegrationSyncRun;
use App\Models\IntegrationWebhook;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Integration\Connectors\ErpConnectorManager;
use App\Services\Integration\ErpBatchSyncService;
use App\Services\Integration\ErpBridgeService;
use App\Services\Integration\IntegrationWebhookService;
use App\Services\Security\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;

class IntegrationHubController extends Controller
{
    /** @var list<string> */
    private const API_ABILITIES = [
        'documents:read',
        'documents:write',
        'finance:sync',
        'hr:sync',
        'webhooks:manage',
    ];

    public function __construct(
        private readonly ErpBatchSyncService $batchSync,
        private readonly IntegrationWebhookService $webhooks,
        private readonly ErpConnectorManager $connectors,
        private readonly ErpBridgeService $bridge,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);

        $tokens = $actor->tokens()
            ->latest()
            ->get()
            ->map(static fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities ?? [],
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $connections = IntegrationConnection::query()
            ->where('tenant_id', $actor->tenant_id)
            ->latest()
            ->get()
            ->map(static fn (IntegrationConnection $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'system_type' => $c->system_type->value,
                'system_label' => $c->system_type->label(),
                'transport' => $c->transport,
                'base_url' => $c->base_url,
                'field_mapping' => $c->field_mapping_json ?? [],
                'supports_cloud_sync' => in_array($c->system_type, [ErpSystemType::Xero, ErpSystemType::Netsuite], true),
                'is_active' => $c->is_active,
                'last_sync_at' => $c->last_sync_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $syncRuns = IntegrationSyncRun::query()
            ->with(['connection'])
            ->where('tenant_id', $actor->tenant_id)
            ->latest()
            ->limit(25)
            ->get()
            ->map(static fn (IntegrationSyncRun $run): array => [
                'id' => $run->id,
                'connection_name' => $run->connection->name ?? 'Connector',
                'system_type' => $run->connection !== null ? $run->connection->system_type->value : '',
                'direction' => $run->direction,
                'status' => $run->status,
                'records_processed' => $run->records_processed,
                'records_failed' => $run->records_failed,
                'error_message' => $run->error_message,
                'finished_at' => $run->finished_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $webhooks = IntegrationWebhook::query()
            ->where('tenant_id', $actor->tenant_id)
            ->latest()
            ->get()
            ->map(static fn (IntegrationWebhook $w): array => [
                'id' => $w->id,
                'name' => $w->name,
                'target_url' => $w->target_url,
                'events' => $w->events_json,
                'is_active' => $w->is_active,
                'last_dispatched_at' => $w->last_dispatched_at?->toIso8601String(),
                'last_status_code' => $w->last_status_code,
                'failure_count' => $w->failure_count,
            ])
            ->values()
            ->all();

        $deliveries = IntegrationDelivery::query()
            ->with(['webhook'])
            ->where('tenant_id', $actor->tenant_id)
            ->latest()
            ->limit(25)
            ->get()
            ->map(static fn (IntegrationDelivery $d): array => [
                'id' => $d->id,
                'webhook_name' => $d->webhook->name ?? 'Webhook',
                'event_type' => $d->event_type,
                'status' => $d->status,
                'attempts' => $d->attempts,
                'response_code' => $d->response_code,
                'response_excerpt' => $d->response_excerpt,
                'dispatched_at' => $d->dispatched_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $mappings = ExternalMapping::query()
            ->where('tenant_id', $actor->tenant_id)
            ->latest()
            ->limit(30)
            ->get(['id', 'external_system', 'entity_type', 'external_id', 'internal_id', 'created_at'])
            ->toArray();

        $systemOptions = array_map(static fn (ErpSystemType $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], ErpSystemType::cases());

        $bridgeAgents = ErpBridgeAgent::query()
            ->where('tenant_id', $actor->tenant_id)
            ->latest()
            ->get()
            ->map(static fn (ErpBridgeAgent $a): array => [
                'id' => $a->id,
                'name' => $a->name,
                'erp_target' => $a->erp_target,
                'hostname' => $a->hostname,
                'agent_version' => $a->agent_version,
                'status' => $a->status,
                'last_heartbeat_at' => $a->last_heartbeat_at?->toIso8601String(),
                'last_ip' => $a->last_ip,
            ])
            ->values()
            ->all();

        $bridgeJobs = ErpBridgeJob::query()
            ->with(['agent'])
            ->where('tenant_id', $actor->tenant_id)
            ->latest()
            ->limit(25)
            ->get()
            ->map(static fn (ErpBridgeJob $j): array => [
                'id' => $j->id,
                'agent_name' => $j->agent->name ?? 'Bridge Agent',
                'job_type' => $j->job_type,
                'status' => $j->status,
                'error_message' => $j->error_message,
                'completed_at' => $j->completed_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('Admin/Integrations', [
            'tokens' => $tokens,
            'availableAbilities' => self::API_ABILITIES,
            'connections' => $connections,
            'syncRuns' => $syncRuns,
            'bridgeAgents' => $bridgeAgents,
            'bridgeJobs' => $bridgeJobs,
            'bridgeTargetOptions' => ErpBridgeService::targetOptions(),
            'issuedBridgePairingCode' => $request->session()->pull('issued_bridge_pairing_code'),
            'webhooks' => $webhooks,
            'deliveries' => $deliveries,
            'mappings' => $mappings,
            'supportedEvents' => IntegrationWebhookService::supportedEvents(),
            'systemOptions' => $systemOptions,
            'issuedPlaintextToken' => $request->session()->pull('issued_api_token'),
            'externalAllowlist' => config()->array('betterdoc.external_allowlist_hosts', []),
        ]);
    }

    public function storeToken(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);

        /** @var array{name: string, abilities: list<string>, expires_days?: int|null} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(self::API_ABILITIES)],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $expiresAt = isset($validated['expires_days']) ? now()->addDays((int) $validated['expires_days']) : null;
        $newToken = $actor->createToken(trim($validated['name']), $validated['abilities'], $expiresAt);
        $tokenKey = $newToken->accessToken->getKey();
        $tokenResourceId = is_int($tokenKey) || is_string($tokenKey) ? (string) $tokenKey : null;

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'integration.api_token_created',
            resourceType: 'personal_access_token',
            resourceId: $tokenResourceId,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            after: ['name' => $validated['name'], 'abilities' => $validated['abilities']],
            request: $request,
        );

        return back()
            ->with('issued_api_token', $newToken->plainTextToken)
            ->with('success', "API token '{$validated['name']}' generated. Copy it now — it will not be shown again.");
    }

    public function destroyToken(Request $request, string $tokenId): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);

        $deleted = $actor->tokens()->whereKey($tokenId)->delete();
        abort_unless($deleted > 0, 404);

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'integration.api_token_revoked',
            resourceType: 'personal_access_token',
            resourceId: $tokenId,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            request: $request,
        );

        return back()->with('success', 'API token revoked.');
    }

    public function storeConnection(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);

        /** @var array{
         *   name: string,
         *   system_type: string,
         *   transport: string,
         *   base_url?: string|null,
         *   api_key?: string|null,
         *   access_token?: string|null,
         *   xero_tenant_id?: string|null,
         *   account_id?: string|null,
         *   consumer_key?: string|null,
         *   consumer_secret?: string|null,
         *   token_id?: string|null,
         *   token_secret?: string|null,
         *   default_expense_account?: string|null,
         *   default_tax_type?: string|null,
         *   subsidiary_id?: string|null
         * } $validated
         */
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'system_type' => ['required', Rule::in(ErpSystemType::values())],
            'transport' => ['required', Rule::in(['rest_api', 'webhook', 'csv_batch', 'local_bridge'])],
            'base_url' => ['nullable', 'url', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:2000'],
            'access_token' => ['nullable', 'string', 'max:4000'],
            'xero_tenant_id' => ['nullable', 'string', 'max:120'],
            'account_id' => ['nullable', 'string', 'max:120'],
            'consumer_key' => ['nullable', 'string', 'max:500'],
            'consumer_secret' => ['nullable', 'string', 'max:500'],
            'token_id' => ['nullable', 'string', 'max:500'],
            'token_secret' => ['nullable', 'string', 'max:500'],
            'default_expense_account' => ['nullable', 'string', 'max:64'],
            'default_tax_type' => ['nullable', 'string', 'max:64'],
            'subsidiary_id' => ['nullable', 'string', 'max:64'],
        ]);

        $creds = array_filter([
            'api_key' => $validated['api_key'] ?? null,
            'access_token' => $validated['access_token'] ?? null,
            'xero_tenant_id' => $validated['xero_tenant_id'] ?? null,
            'account_id' => $validated['account_id'] ?? null,
            'consumer_key' => $validated['consumer_key'] ?? null,
            'consumer_secret' => $validated['consumer_secret'] ?? null,
            'token_id' => $validated['token_id'] ?? null,
            'token_secret' => $validated['token_secret'] ?? null,
        ], static fn (?string $v): bool => is_string($v) && trim($v) !== '');

        $mapping = array_filter([
            'default_expense_account' => $validated['default_expense_account'] ?? null,
            'default_tax_type' => $validated['default_tax_type'] ?? null,
            'subsidiary_id' => $validated['subsidiary_id'] ?? null,
        ], static fn (?string $v): bool => is_string($v) && trim($v) !== '');

        IntegrationConnection::query()->create([
            'tenant_id' => $actor->tenant_id,
            'created_by' => $actor->id,
            'name' => trim($validated['name']),
            'system_type' => $validated['system_type'],
            'transport' => $validated['transport'],
            'base_url' => isset($validated['base_url']) && trim($validated['base_url']) !== '' ? trim($validated['base_url']) : null,
            'credentials_encrypted' => $creds !== [] ? json_encode($creds, JSON_THROW_ON_ERROR) : null,
            'field_mapping_json' => $mapping !== [] ? $mapping : null,
            'is_active' => true,
        ]);

        return back()->with('success', "Connector '{$validated['name']}' saved.");
    }

    public function runConnectionSync(Request $request, IntegrationConnection $connection): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);
        abort_unless($connection->tenant_id === $actor->tenant_id, 404);

        /** @var array{direction: string} $validated */
        $validated = $request->validate([
            'direction' => ['required', Rule::in(['test_connection', 'pull_vendors', 'pull_purchase_orders', 'push_invoices'])],
        ]);

        $run = $this->connectors->runSync($connection, $actor, $validated['direction'], $request);

        if ($run->status === 'blocked_airgap') {
            return back()->with('error', "Sync blocked by air-gap policy: {$run->error_message}");
        }

        if ($run->status === 'failed') {
            return back()->with('error', "Sync failed: {$run->error_message}");
        }

        return back()->with(
            'success',
            "{$connection->name} ({$run->direction}) completed: {$run->records_processed} processed, {$run->records_failed} failed."
        );
    }

    public function updateConnectionMapping(Request $request, IntegrationConnection $connection): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);
        abort_unless($connection->tenant_id === $actor->tenant_id, 404);

        /** @var array{default_expense_account?: string|null, default_tax_type?: string|null, subsidiary_id?: string|null} $validated */
        $validated = $request->validate([
            'default_expense_account' => ['nullable', 'string', 'max:64'],
            'default_tax_type' => ['nullable', 'string', 'max:64'],
            'subsidiary_id' => ['nullable', 'string', 'max:64'],
        ]);

        $mapping = array_merge($connection->field_mapping_json ?? [], array_filter([
            'default_expense_account' => $validated['default_expense_account'] ?? null,
            'default_tax_type' => $validated['default_tax_type'] ?? null,
            'subsidiary_id' => $validated['subsidiary_id'] ?? null,
        ], static fn (?string $v): bool => is_string($v) && trim($v) !== ''));

        $connection->update(['field_mapping_json' => $mapping]);

        return back()->with('success', "Updated chart-of-accounts & tax mapping for '{$connection->name}'.");
    }

    public function storeWebhook(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);

        /** @var array{name: string, target_url: string, secret?: string|null, events: list<string>, integration_connection_id?: string|null} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'target_url' => ['required', 'url', 'max:500'],
            'secret' => ['nullable', 'string', 'min:16', 'max:256'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in([...IntegrationWebhookService::supportedEvents(), '*'])],
            'integration_connection_id' => ['nullable', 'uuid'],
        ]);

        $secret = ! empty($validated['secret']) ? $validated['secret'] : Str::random(40);

        IntegrationWebhook::query()->create([
            'tenant_id' => $actor->tenant_id,
            'integration_connection_id' => $validated['integration_connection_id'] ?? null,
            'name' => trim($validated['name']),
            'target_url' => trim($validated['target_url']),
            'secret_encrypted' => $secret,
            'events_json' => array_values(array_unique($validated['events'])),
            'is_active' => true,
        ]);

        return back()->with('success', "Webhook '{$validated['name']}' registered.");
    }

    public function testWebhook(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);

        $count = $this->webhooks->emit(
            tenantId: $actor->tenant_id,
            eventType: 'webhook.test',
            data: ['triggered_by' => $actor->email, 'message' => 'BetterDoc Stage 1 integration ping'],
            actor: $actor,
            syncDispatch: true,
        );

        return back()->with('success', "Dispatched test payload to {$count} active webhook(s). Inspect delivery log below.");
    }

    public function importBelinaCsv(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);

        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $file = $request->file('csv_file');
        $raw = $file instanceof UploadedFile && is_string($file->getRealPath())
            ? (string) file_get_contents($file->getRealPath())
            : '';
        $result = $this->batchSync->importBelinaEmployeesCsv($actor, $raw, $request);

        return back()->with(
            'success',
            "Belina import complete: {$result['imported']} employee records synced ({$result['created_users']} new user accounts, {$result['skipped']} skipped)."
        );
    }

    public function importSageVendorsCsv(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);

        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $file = $request->file('csv_file');
        $raw = $file instanceof UploadedFile && is_string($file->getRealPath())
            ? (string) file_get_contents($file->getRealPath())
            : '';
        $result = $this->batchSync->importSageVendorsCsv($actor, $raw, $request);

        return back()->with(
            'success',
            "Sage Pastel vendor import complete: {$result['imported']} vendors mapped ({$result['skipped']} skipped)."
        );
    }

    public function exportInvoicesCsv(Request $request): HttpResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);

        $system = $request->string('system', 'sage_pastel')->toString();
        $csv = $this->batchSync->exportApprovedInvoicesCsv($actor, $system);
        $filename = 'betterdoc-ap-invoices-'.preg_replace('/[^a-z0-9_-]/i', '', $system).'-'.now()->format('Ymd').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function exportBelinaCsv(Request $request): HttpResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);

        $csv = $this->batchSync->exportBelinaEmployeesCsv($actor);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="betterdoc-belina-employees-'.now()->format('Ymd').'.csv"',
        ]);
    }

    public function downloadTemplate(Request $request, string $kind): HttpResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);

        if ($kind === 'belina-employees') {
            $content = "employee_number,email,name,department_name,job_title,hire_date\nBEL-001,jane.doe@company.lan,Jane Doe,Finance,Payroll Officer,2026-01-15\n";
            $filename = 'belina-employees-template.csv';
        } elseif ($kind === 'sage-vendors') {
            $content = "vendor_code,name,tax_id_tin,vat_number,currency,email\nSAGE-V01,Harare Office Supplies Ltd,200044112,10008822,USD,accounts@supplies.co.zw\n";
            $filename = 'sage-pastel-vendors-template.csv';
        } else {
            abort(404);
        }

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function storeBridgeAgent(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);

        /** @var array{name: string, erp_target: string, integration_connection_id?: string|null} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'erp_target' => ['required', Rule::in(['sage_pastel_evolution', 'sage_pastel_partner', 'belina_payroll', 'custom_lan_erp'])],
            'integration_connection_id' => ['nullable', 'uuid'],
        ]);

        $res = $this->bridge->initiatePairing(
            actor: $actor,
            name: $validated['name'],
            erpTarget: $validated['erp_target'],
            connectionId: $validated['integration_connection_id'] ?? null,
            request: $request,
        );

        return back()
            ->with('issued_bridge_pairing_code', $res['pairing_code'])
            ->with('success', "Windows LAN Bridge '{$res['agent']->name}' created. Copy the one-time pairing code below (valid 24h).");
    }

    public function enqueueBridgeJob(Request $request, ErpBridgeAgent $agent): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);
        abort_unless($agent->tenant_id === $actor->tenant_id, 404);

        /** @var array{job_type: string} $validated */
        $validated = $request->validate([
            'job_type' => ['required', Rule::in(['health_check', 'pull_vendors', 'pull_purchase_orders', 'push_invoices', 'pull_employees'])],
        ]);

        if ($agent->status === 'revoked') {
            return back()->with('error', 'Cannot queue jobs for a revoked bridge agent.');
        }

        $job = $this->bridge->enqueueJob($actor, $agent, $validated['job_type'], $request);

        return back()->with('success', "Queued '{$job->job_type}' job for LAN Bridge '{$agent->name}'.");
    }

    public function revokeBridgeAgent(Request $request, ErpBridgeAgent $agent): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);
        abort_unless($agent->tenant_id === $actor->tenant_id, 404);

        $this->bridge->revokeAgent($actor, $agent, $request);

        return back()->with('success', "LAN Bridge Agent '{$agent->name}' revoked.");
    }

    public function downloadBridgeConfig(Request $request, ErpBridgeAgent $agent): HttpResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeIntegrations($actor);
        abort_unless($agent->tenant_id === $actor->tenant_id, 404);

        $config = [
            'betterdoc_url' => rtrim(config()->string('app.url', 'https://betterdoc.lan'), '/'),
            'erp_target' => $agent->erp_target,
            'bridge_token' => '',
            'sage_evolution_odbc' => 'DRIVER={ODBC Driver 17 for SQL Server};SERVER=localhost\\SQLEXPRESS;DATABASE=SageEvolution;UID=betterdoc_ro;PWD=CHANGE_ME',
            'vendors_csv_path' => 'C:\\Pastel\\Exports\\vendors.csv',
            'belina_employees_csv_path' => 'C:\\Belina\\Exports\\employees.csv',
            'invoice_export_dir' => 'C:\\Pastel\\BetterDocOutbox',
        ];

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $filename = 'betterdoc-bridge-'.preg_replace('/[^a-z0-9_-]/i', '-', strtolower($agent->name)).'.json';

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function authorizeIntegrations(User $actor): void
    {
        PermissionCatalog::syncTenantRolesAndPermissions($actor->tenant_id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($actor->tenant_id);
        abort_unless(
            $actor->hasRole(BetterDocRole::SystemAdmin->value) && $actor->can('integrations.manage'),
            403
        );
    }
}
