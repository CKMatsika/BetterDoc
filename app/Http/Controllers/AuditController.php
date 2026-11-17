<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SentinelRole;
use App\Models\AuditCheckpoint;
use App\Models\AuditEvent;
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
            $user->hasAnyRole([SentinelRole::Auditor->value, SentinelRole::SystemAdmin->value, SentinelRole::RecordsOfficer->value]),
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
        $checkpoints = AuditCheckpoint::query()
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(10)
            ->get()
            ->toArray();

        return Inertia::render('Audit/Index', [
            'events' => $events,
            'verification' => $verification,
            'checkpoints' => $checkpoints,
        ]);
    }

    public function checkpoint(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        abort_unless($user->hasRole(SentinelRole::Auditor->value), 403);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        $this->audit->createCheckpoint($tenant);

        return back()->with('success', 'Signed audit checkpoint anchored.');
    }
}
