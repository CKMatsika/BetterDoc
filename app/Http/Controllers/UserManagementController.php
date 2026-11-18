<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BetterDocRole;
use App\Enums\UserStatus;
use App\Models\ClearanceLevel;
use App\Models\Compartment;
use App\Models\OrgUnit;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Security\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

class UserManagementController extends Controller
{
    public function __construct(
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeUsers($actor);

        PermissionCatalog::syncTenantRolesAndPermissions($actor->tenant_id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($actor->tenant_id);

        $users = User::query()
            ->with(['orgUnit', 'clearanceLevel', 'compartments'])
            ->where('tenant_id', $actor->tenant_id)
            ->orderByDesc('clearance_rank')
            ->orderBy('name')
            ->get()
            ->map(static function (User $u): array {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'service_number' => $u->service_number,
                    'rank_title' => $u->rank_title,
                    'status' => $u->status->value,
                    'clearance_level_id' => $u->clearance_level_id,
                    'clearance_rank' => $u->clearance_rank,
                    'clearance_name' => $u->clearanceLevel?->name,
                    'clearance_color' => $u->clearanceLevel?->color_hex,
                    'org_unit_id' => $u->org_unit_id,
                    'org_unit_name' => $u->orgUnit?->name,
                    'compartment_ids' => $u->compartments->pluck('id')->values()->all(),
                    'compartment_codes' => $u->compartments->pluck('code')->values()->all(),
                    'roles' => $u->getRoleNames()->values()->all(),
                    'permissions' => $u->getAllPermissions()->pluck('name')->values()->all(),
                    'direct_permissions' => $u->getDirectPermissions()->pluck('name')->values()->all(),
                    'mfa_enforced' => $u->mfa_enforced,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'roles' => BetterDocRole::values(),
            'permissionsCatalog' => PermissionCatalog::all(),
            'orgUnits' => OrgUnit::query()->where('tenant_id', $actor->tenant_id)->orderBy('name')->get(['id', 'code', 'name', 'unit_type'])->toArray(),
            'clearanceLevels' => ClearanceLevel::query()->where('tenant_id', $actor->tenant_id)->orderBy('rank')->get(['id', 'rank', 'code', 'name', 'color_hex'])->toArray(),
            'compartments' => Compartment::query()->where('tenant_id', $actor->tenant_id)->orderBy('code')->get(['id', 'code', 'name'])->toArray(),
        ]);
    }

    public function storeOrgUnit(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeUsers($actor);

        /** @var array{code: string, name: string, unit_type?: string|null, parent_id?: string|null} $validated */
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'alpha_dash'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'unit_type' => ['nullable', 'string', 'max:40'],
            'parent_id' => ['nullable', 'uuid'],
        ]);

        $parent = ! empty($validated['parent_id'])
            ? OrgUnit::query()->where('tenant_id', $actor->tenant_id)->where('id', $validated['parent_id'])->first()
            : null;

        $codeSlug = strtolower(preg_replace('/[^a-z0-9]/i', '', $validated['code']) ?: 'dept');
        $path = $parent instanceof OrgUnit ? "{$parent->path}.{$codeSlug}" : "hq.{$codeSlug}";

        OrgUnit::query()->firstOrCreate(
            ['tenant_id' => $actor->tenant_id, 'code' => strtoupper(trim($validated['code']))],
            [
                'parent_id' => $parent?->id,
                'name' => trim($validated['name']),
                'unit_type' => $validated['unit_type'] ?? 'department',
                'path' => $path,
            ]
        );

        return back()->with('success', "Department '{$validated['name']}' created.");
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeUsers($actor);

        PermissionCatalog::syncTenantRolesAndPermissions($actor->tenant_id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($actor->tenant_id);

        /** @var array{
         *   name: string,
         *   email: string,
         *   password: string,
         *   service_number?: string|null,
         *   rank_title?: string|null,
         *   org_unit_id?: string|null,
         *   clearance_level_id: string,
         *   compartment_ids?: list<string>,
         *   roles: list<string>,
         *   permissions?: list<string>,
         *   mfa_enforced?: bool
         * } $validated
         */
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            'service_number' => ['nullable', 'string', 'max:48'],
            'rank_title' => ['nullable', 'string', 'max:80'],
            'org_unit_id' => ['nullable', 'uuid'],
            'clearance_level_id' => ['required', 'uuid'],
            'compartment_ids' => ['nullable', 'array'],
            'compartment_ids.*' => ['uuid'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(BetterDocRole::values())],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(PermissionCatalog::all())],
            'mfa_enforced' => ['nullable', 'boolean'],
        ]);

        /** @var ClearanceLevel $level */
        $level = ClearanceLevel::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('id', $validated['clearance_level_id'])
            ->firstOrFail();

        $orgUnitId = $validated['org_unit_id'] ?? $actor->org_unit_id;
        if ($orgUnitId !== '') {
            OrgUnit::query()->where('tenant_id', $actor->tenant_id)->where('id', $orgUnitId)->firstOrFail();
        }

        /** @var User $newUser */
        $newUser = User::query()->create([
            'tenant_id' => $actor->tenant_id,
            'org_unit_id' => $orgUnitId,
            'clearance_level_id' => $level->id,
            'clearance_rank' => $level->rank,
            'service_number' => isset($validated['service_number']) && trim($validated['service_number']) !== '' ? trim($validated['service_number']) : null,
            'rank_title' => isset($validated['rank_title']) && trim($validated['rank_title']) !== '' ? trim($validated['rank_title']) : null,
            'name' => trim($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'password' => Hash::make($validated['password']),
            'status' => UserStatus::Active,
            'mfa_enforced' => (bool) ($validated['mfa_enforced'] ?? false),
        ]);

        $newUser->syncRoles($validated['roles']);
        $newUser->syncPermissions($validated['permissions'] ?? []);

        $validCompIds = Compartment::query()
            ->where('tenant_id', $actor->tenant_id)
            ->whereIn('id', $validated['compartment_ids'] ?? [])
            ->pluck('id')
            ->all();
        $newUser->compartments()->sync($validCompIds);

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'admin.user_created',
            resourceType: 'user',
            resourceId: $newUser->id,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            after: [
                'email' => $newUser->email,
                'roles' => $validated['roles'],
                'permissions' => $validated['permissions'] ?? [],
                'clearance_rank' => $level->rank,
            ],
            request: $request,
        );

        if ($request->wantsJson()) {
            return response()->json(['user' => $newUser], 201);
        }

        return back()->with('success', "User '{$newUser->name}' created with assigned roles & permissions.");
    }

    public function updateAccess(Request $request, User $targetUser): RedirectResponse|JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeUsers($actor);
        abort_unless($targetUser->tenant_id === $actor->tenant_id, 404);

        PermissionCatalog::syncTenantRolesAndPermissions($actor->tenant_id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($actor->tenant_id);

        /** @var array{
         *   clearance_level_id: string,
         *   org_unit_id?: string|null,
         *   compartment_ids?: list<string>,
         *   roles: list<string>,
         *   permissions?: list<string>,
         *   status: string,
         *   mfa_enforced?: bool,
         *   password?: string|null
         * } $validated
         */
        $validated = $request->validate([
            'clearance_level_id' => ['required', 'uuid'],
            'org_unit_id' => ['nullable', 'uuid'],
            'compartment_ids' => ['nullable', 'array'],
            'compartment_ids.*' => ['uuid'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(BetterDocRole::values())],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(PermissionCatalog::all())],
            'status' => ['required', Rule::in([UserStatus::Active->value, UserStatus::Suspended->value])],
            'mfa_enforced' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        /** @var ClearanceLevel $level */
        $level = ClearanceLevel::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('id', $validated['clearance_level_id'])
            ->firstOrFail();

        $updates = [
            'clearance_level_id' => $level->id,
            'clearance_rank' => $level->rank,
            'status' => $validated['status'],
            'mfa_enforced' => (bool) ($validated['mfa_enforced'] ?? $targetUser->mfa_enforced),
        ];

        if (! empty($validated['org_unit_id'])) {
            OrgUnit::query()->where('tenant_id', $actor->tenant_id)->where('id', $validated['org_unit_id'])->firstOrFail();
            $updates['org_unit_id'] = $validated['org_unit_id'];
        }

        if (! empty($validated['password'])) {
            $updates['password'] = Hash::make($validated['password']);
        }

        $targetUser->update($updates);
        $targetUser->syncRoles($validated['roles']);
        $targetUser->syncPermissions($validated['permissions'] ?? []);

        if ($validated['status'] === UserStatus::Suspended->value) {
            UserSession::query()
                ->where('user_id', $targetUser->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }

        $validCompIds = Compartment::query()
            ->where('tenant_id', $actor->tenant_id)
            ->whereIn('id', $validated['compartment_ids'] ?? [])
            ->pluck('id')
            ->all();
        $targetUser->compartments()->sync($validCompIds);

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'admin.user_access_updated',
            resourceType: 'user',
            resourceId: $targetUser->id,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            after: [
                'email' => $targetUser->email,
                'roles' => $validated['roles'],
                'permissions' => $validated['permissions'] ?? [],
                'clearance_rank' => $level->rank,
                'status' => $validated['status'],
            ],
            request: $request,
        );

        if ($request->wantsJson()) {
            return response()->json(['user' => $targetUser->refresh()]);
        }

        return back()->with('success', "Updated roles & permissions for '{$targetUser->name}'.");
    }

    private function authorizeUsers(User $actor): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($actor->tenant_id);
        abort_unless(
            $actor->hasRole(BetterDocRole::SystemAdmin->value) && $actor->can('manage-users'),
            403
        );
    }
}
