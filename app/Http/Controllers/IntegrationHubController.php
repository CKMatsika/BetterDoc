<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BetterDocRole;
use App\Enums\ErpSystemType;
use App\Models\ExternalMapping;
use App\Models\IntegrationConnection;
use App\Models\IntegrationDelivery;
use App\Models\IntegrationWebhook;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Integration\ErpBatchSyncService;
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
                'is_active' => $c->is_active,
                'last_sync_at' => $c->last_sync_at?->toIso8601String(),
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

        return Inertia::render('Admin/Integrations', [
            'tokens' => $tokens,
            'availableAbilities' => self::API_ABILITIES,
            'connections' => $connections,
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

        /** @var array{name: string, system_type: string, transport: string, base_url?: string|null, api_key?: string|null} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'system_type' => ['required', Rule::in(ErpSystemType::values())],
            'transport' => ['required', Rule::in(['rest_api', 'webhook', 'csv_batch', 'local_bridge'])],
            'base_url' => ['nullable', 'url', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:2000'],
        ]);

        IntegrationConnection::query()->create([
            'tenant_id' => $actor->tenant_id,
            'created_by' => $actor->id,
            'name' => trim($validated['name']),
            'system_type' => $validated['system_type'],
            'transport' => $validated['transport'],
            'base_url' => isset($validated['base_url']) && trim($validated['base_url']) !== '' ? trim($validated['base_url']) : null,
            'credentials_encrypted' => ! empty($validated['api_key'])
                ? json_encode(['api_key' => $validated['api_key']], JSON_THROW_ON_ERROR)
                : null,
            'is_active' => true,
        ]);

        return back()->with('success', "Connector '{$validated['name']}' saved.");
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
