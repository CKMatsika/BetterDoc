<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BetterDocRole;
use App\Models\AuditCheckpoint;
use App\Models\AuditEvent;
use App\Models\CompliancePack;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

class AuditController extends Controller
{
    public function __construct(
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        abort_unless(
            $user->hasAnyRole([BetterDocRole::Auditor->value, BetterDocRole::SystemAdmin->value, BetterDocRole::RecordsOfficer->value]),
            403
        );

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        $events = AuditEvent::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderByDesc('seq_id')
            ->limit(100)
            ->get()
            ->map(fn (AuditEvent $event): array => $this->audit->formatEventForViewer($event, $user))
            ->values()
            ->all();

        $verification = $this->audit->verifyChain($tenant);
        $checkpointVerification = $this->audit->verifyCheckpoints($tenant);
        $checkpoints = AuditCheckpoint::query()
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(10)
            ->get()
            ->toArray();

        $compliancePacks = CompliancePack::query()
            ->with(['generator'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(20)
            ->get()
            ->map(static fn (CompliancePack $p): array => [
                'id' => $p->id,
                'pack_name' => $p->pack_name,
                'regulation_standard' => $p->regulation_standard,
                'event_count' => $p->event_count,
                'head_hash' => $p->head_hash,
                'manifest_sha256' => $p->manifest_sha256,
                'generator_name' => $p->generator?->name,
                'created_at' => $p->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('Audit/Index', [
            'events' => $events,
            'verification' => $verification,
            'checkpointVerification' => $checkpointVerification,
            'checkpoints' => $checkpoints,
            'compliancePacks' => $compliancePacks,
            'canExportCompliance' => $user->hasAnyRole([BetterDocRole::Auditor->value, BetterDocRole::SystemAdmin->value]),
        ]);
    }

    public function checkpoint(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        abort_unless($user->hasRole(BetterDocRole::Auditor->value), 403);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        $this->audit->createCheckpoint($tenant);

        return back()->with('success', 'Signed audit checkpoint anchored.');
    }
}
