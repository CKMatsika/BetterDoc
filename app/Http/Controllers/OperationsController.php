<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BackupTier;
use App\Enums\BetterDocRole;
use App\Enums\HostingMode;
use App\Enums\SyncTargetDriver;
use App\Exceptions\AirGapViolationException;
use App\Models\BackupSnapshot;
use App\Models\Document;
use App\Models\SyncOutboxEvent;
use App\Models\SyncTarget;
use App\Models\User;
use App\Services\Backup\BackupDrService;
use App\Services\Sync\SyncGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

class OperationsController extends Controller
{
    public function __construct(
        private readonly SyncGatewayService $sync,
        private readonly BackupDrService $backups,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        abort_unless($user->hasAnyRole([BetterDocRole::SystemAdmin->value, BetterDocRole::Auditor->value, BetterDocRole::RecordsOfficer->value]), 403);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        $syncTargets = SyncTarget::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get()
            ->map(static fn (SyncTarget $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'driver' => $t->driver->value,
                'endpoint_url' => $t->endpoint_url,
                'bucket_or_site' => $t->bucket_or_site,
                'max_classification_rank' => $t->max_classification_rank,
                'conflict_strategy' => $t->conflict_strategy,
                'enabled' => $t->enabled,
                'last_synced_at' => $t->last_synced_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $outboxEvents = SyncOutboxEvent::query()
            ->with(['target', 'document'])
            ->where('tenant_id', $tenant->id)
            ->latest()
            ->limit(40)
            ->get()
            ->map(static fn (SyncOutboxEvent $e): array => [
                'id' => $e->id,
                'target_name' => $e->target !== null ? $e->target->name : 'Unassigned',
                'doc_number' => $e->document !== null ? $e->document->doc_number : '—',
                'classification_rank' => $e->classification_rank,
                'status' => $e->status->value,
                'conflict_branch_label' => $e->conflict_branch_label,
                'conflict_details' => $e->conflict_details,
                'attempts' => $e->attempts,
                'synced_at' => $e->synced_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $backupSnapshots = BackupSnapshot::query()
            ->with(['creator'])
            ->where('tenant_id', $tenant->id)
            ->latest()
            ->limit(30)
            ->get()
            ->map(static fn (BackupSnapshot $b): array => [
                'id' => $b->id,
                'snapshot_label' => $b->snapshot_label,
                'backup_tier' => $b->backup_tier->value,
                'size_bytes' => $b->size_bytes,
                'manifest_sha256' => $b->manifest_sha256,
                'status' => $b->status,
                'creator_name' => $b->creator?->name,
                'verified_at' => $b->verified_at?->toIso8601String(),
                'last_restored_at' => $b->last_restored_at?->toIso8601String(),
                'restore_duration_ms' => $b->restore_duration_ms,
                'created_at' => $b->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $documents = Document::query()
            ->where('tenant_id', $tenant->id)
            ->latest()
            ->limit(40)
            ->get(['id', 'doc_number', 'title', 'classification_rank'])
            ->toArray();

        $airGapped = config()->boolean('betterdoc.air_gapped', true)
            || $tenant->air_gapped
            || $tenant->hosting_mode === HostingMode::AirGappedOnPrem;

        return Inertia::render('Admin/Operations', [
            'syncTargets' => $syncTargets,
            'outboxEvents' => $outboxEvents,
            'backupSnapshots' => $backupSnapshots,
            'clearedDocuments' => $documents,
            'airGapped' => $airGapped,
            'syncEnabled' => config()->boolean('betterdoc.sync.enabled', false),
            'targetRtoSeconds' => config()->integer('betterdoc.backup.target_rto_seconds', 7200),
            'canManageOperations' => $user->hasAnyRole([BetterDocRole::SystemAdmin->value, BetterDocRole::Auditor->value]),
        ]);
    }

    public function storeSyncTarget(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        abort_unless($user->hasRole(BetterDocRole::SystemAdmin->value), 403);

        /** @var array{name: string, driver: string, endpoint_url?: string|null, bucket_or_site: string, max_classification_rank: int, conflict_strategy?: string} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', Rule::enum(SyncTargetDriver::class)],
            'endpoint_url' => ['nullable', 'string', 'max:255'],
            'bucket_or_site' => ['required', 'string', 'max:160'],
            'max_classification_rank' => ['required', 'integer', 'min:0', 'max:4'],
            'conflict_strategy' => ['nullable', Rule::in(['branch_conflict', 'source_wins', 'target_wins'])],
        ]);

        /** @var SyncTarget $target */
        $target = SyncTarget::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => trim($validated['name']),
            'driver' => $validated['driver'],
            'endpoint_url' => $validated['endpoint_url'] ?? null,
            'bucket_or_site' => trim($validated['bucket_or_site']),
            'max_classification_rank' => $validated['max_classification_rank'],
            'conflict_strategy' => $validated['conflict_strategy'] ?? 'branch_conflict',
            'enabled' => true,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['target' => $target], 201);
        }

        return back()->with('success', "Sync target '{$target->name}' configured (Max Rank L{$target->max_classification_rank}).");
    }

    public function enqueueSync(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        abort_unless($user->hasRole(BetterDocRole::SystemAdmin->value), 403);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        /** @var array{document_id: string, sync_target_id: string} $validated */
        $validated = $request->validate([
            'document_id' => ['required', 'uuid'],
            'sync_target_id' => ['required', 'uuid'],
        ]);

        /** @var Document $document */
        $document = Document::query()->where('tenant_id', $tenant->id)->where('id', $validated['document_id'])->firstOrFail();
        Gate::authorize('view', $document);

        /** @var SyncTarget $target */
        $target = SyncTarget::query()->where('tenant_id', $tenant->id)->where('id', $validated['sync_target_id'])->firstOrFail();

        try {
            $event = $this->sync->enqueueDocumentSync($tenant, $user, $document, $target, $request);
        } catch (AirGapViolationException|RuntimeException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            throw ValidationException::withMessages(['sync' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json(['outbox_event' => $event], 201);
        }

        return back()->with('success', "Encrypted outbox item queued for '{$document->doc_number}'.");
    }

    public function processSync(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        abort_unless($user->hasRole(BetterDocRole::SystemAdmin->value), 403);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        try {
            $res = $this->sync->processOutbox($tenant, $user);
        } catch (AirGapViolationException|RuntimeException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            throw ValidationException::withMessages(['sync' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json($res);
        }

        return back()->with('success', "Outbox processed: {$res['synced']} synced, {$res['conflict_branched']} branched, {$res['failed']} failed.");
    }

    public function createBackup(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        abort_unless($user->hasAnyRole([BetterDocRole::SystemAdmin->value, BetterDocRole::Auditor->value]), 403);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        /** @var array{backup_tier?: string, snapshot_label?: string|null} $validated */
        $validated = $request->validate([
            'backup_tier' => ['nullable', Rule::enum(BackupTier::class)],
            'snapshot_label' => ['nullable', 'string', 'max:160'],
        ]);

        $tier = BackupTier::tryFrom($validated['backup_tier'] ?? 'tier1_local') ?? BackupTier::Tier1Local;
        $snap = $this->backups->createEncryptedSnapshot(
            tenant: $tenant,
            actor: $user,
            tier: $tier,
            label: $validated['snapshot_label'] ?? '',
            request: $request,
        );

        if ($request->wantsJson()) {
            return response()->json(['snapshot' => $snap], 201);
        }

        return back()->with('success', "Encrypted 3-2-1 snapshot '{$snap->snapshot_label}' created ({$snap->size_bytes} bytes).");
    }

    public function restoreDrill(Request $request, BackupSnapshot $backupSnapshot): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        abort_unless($backupSnapshot->tenant_id === $user->tenant_id, 404);
        abort_unless($user->hasAnyRole([BetterDocRole::SystemAdmin->value, BetterDocRole::Auditor->value]), 403);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        $drill = $this->backups->verifyAndRunRestoreDrill($backupSnapshot, $tenant, $user, $request);

        if ($request->wantsJson()) {
            return response()->json($drill);
        }

        $msg = $drill['valid']
            ? "Restore Drill PASSED in {$drill['duration_ms']}ms (<2h RTO): {$drill['restored_documents']} docs, {$drill['restored_blobs']} blobs, audit chain intact."
            : 'Restore Drill FAILED: '.($drill['reason'] ?? 'verification mismatch');

        return back()->with($drill['valid'] ? 'success' : 'error', $msg);
    }
}
