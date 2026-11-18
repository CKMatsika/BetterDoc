<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BetterDocRole;
use App\Enums\ProfileCode;
use App\Models\ClearanceLevel;
use App\Models\Compartment;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Profile\ProfileManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

class AdminProfileController extends Controller
{
    public function __construct(
        private readonly ProfileManager $profiles,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        return Inertia::render('Admin/Profiles', [
            'availablePacks' => $this->profiles->listAvailablePacks(),
            'activeConfig' => $this->profiles->activeConfig($tenant),
            'clearanceLevels' => ClearanceLevel::query()
                ->where('tenant_id', $tenant->id)
                ->orderBy('rank')
                ->get()
                ->toArray(),
            'compartments' => Compartment::query()
                ->where('tenant_id', $tenant->id)
                ->orderBy('code')
                ->get()
                ->toArray(),
            'canSwitchProfile' => $user->hasRole(BetterDocRole::SystemAdmin->value),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        abort_unless($user->hasRole(BetterDocRole::SystemAdmin->value), 403);

        /** @var array{profile_code: string} $validated */
        $validated = $request->validate([
            'profile_code' => ['required', 'string', 'in:defence,financial,corporate'],
        ]);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        $code = ProfileCode::from($validated['profile_code']);
        $profile = $this->profiles->applyToTenant($tenant, $code, syncTaxonomy: false);

        $this->audit->log(
            tenantId: $tenant->id,
            eventType: 'admin.profile_switched',
            resourceType: 'organization_profile',
            resourceId: $profile->id,
            actor: $user,
            classificationRank: $user->clearance_rank,
            after: ['profile_code' => $code->value, 'version' => $profile->version],
            request: $request,
        );

        return back()->with('success', "Profile switched to {$code->label()}.");
    }
}
