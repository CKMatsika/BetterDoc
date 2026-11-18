<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\BetterDocRole;
use App\Enums\HostingMode;
use App\Enums\ProfileCode;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Compartment;
use App\Models\OrganisationSetting;
use App\Models\OrgUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Branding\BrandingService;
use App\Services\Profile\ProfileManager;
use App\Services\Security\DbSecurityContext;
use App\Services\Security\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

class RegisterOrganisationController extends Controller
{
    public function __construct(
        private readonly ProfileManager $profiles,
        private readonly BrandingService $branding,
        private readonly DbSecurityContext $dbContext,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function show(): Response
    {
        abort_unless(config()->boolean('betterdoc.self_registration_enabled', true), 404);

        $types = [
            [
                'code' => ProfileCode::Corporate->value,
                'name' => 'Corporate & Enterprise',
                'summary' => 'Public / Internal / Confidential ladder, AP 3-way matching, ZIMRA e-invoicing, Contracts & HR Vault.',
            ],
            [
                'code' => ProfileCode::Government->value,
                'name' => 'Government & Ministry',
                'summary' => 'Official / Secret registry clearances, Correspondence Registry, Gazette tracking & long-term retention.',
            ],
            [
                'code' => ProfileCode::Financial->value,
                'name' => 'Financial & Banking',
                'summary' => 'Strict write-down protection, KYC/AML/Credit/Treasury compartments, Dual-person approvals & compliance packs.',
            ],
            [
                'code' => ProfileCode::Defence->value,
                'name' => 'Defence & National Security',
                'summary' => '5-tier Top Secret ladder, OPS/INTEL/LOG compartments, enforced air-gap & PAdES cryptographic signing.',
            ],
        ];

        return Inertia::render('Auth/Register', [
            'organisationTypes' => $types,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(config()->boolean('betterdoc.self_registration_enabled', true), 404);

        /** @var array{organisation_type: string, organisation_name: string, name: string, email: string, password: string} $validated */
        $validated = $request->validate([
            'organisation_type' => ['required', Rule::in(['corporate', 'government', 'financial', 'defence'])],
            'organisation_name' => ['required', 'string', 'min:2', 'max:160'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $profileCode = ProfileCode::from($validated['organisation_type']);
        $pack = $this->profiles->loadPack($profileCode);
        $orgName = trim($validated['organisation_name']);
        $slugBase = Str::slug($orgName) ?: 'org';
        $slug = substr($slugBase, 0, 48).'-'.strtolower(Str::random(5));

        $airGapped = (bool) ($pack['air_gapped_enforced'] ?? false);
        $hostingDefault = is_string($pack['hosting_mode_default'] ?? null)
            ? HostingMode::tryFrom($pack['hosting_mode_default']) ?? HostingMode::AirGappedOnPrem
            : HostingMode::AirGappedOnPrem;

        /** @var User $superAdmin */
        $superAdmin = DB::transaction(function () use ($validated, $profileCode, $orgName, $slug, $airGapped, $hostingDefault): User {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->create([
                'slug' => $slug,
                'name' => $orgName,
                'hosting_mode' => ($airGapped ? HostingMode::AirGappedOnPrem : $hostingDefault)->value,
                'air_gapped' => $airGapped,
                'active_profile_code' => $profileCode,
            ]);

            $this->profiles->applyToTenant($tenant, $profileCode, syncTaxonomy: true);
            PermissionCatalog::syncTenantRolesAndPermissions($tenant->id);

            /** @var OrgUnit $hq */
            $hq = OrgUnit::query()->create([
                'tenant_id' => $tenant->id,
                'code' => 'HQ',
                'name' => 'Headquarters',
                'unit_type' => 'headquarters',
                'path' => 'hq',
            ]);

            /** @var ClearanceLevel $topLevel */
            $topLevel = ClearanceLevel::query()
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('rank')
                ->firstOrFail();

            $compartmentIds = Compartment::query()
                ->where('tenant_id', $tenant->id)
                ->pluck('id')
                ->all();

            /** @var User $user */
            $user = User::query()->create([
                'tenant_id' => $tenant->id,
                'org_unit_id' => $hq->id,
                'clearance_level_id' => $topLevel->id,
                'clearance_rank' => $topLevel->rank,
                'service_number' => 'ADMIN-001',
                'rank_title' => 'Super Admin',
                'name' => trim($validated['name']),
                'email' => strtolower(trim($validated['email'])),
                'password' => Hash::make($validated['password']),
                'status' => UserStatus::Active,
                'mfa_enforced' => false,
            ]);

            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
            $user->syncRoles([BetterDocRole::SystemAdmin->value]);
            $user->syncPermissions(PermissionCatalog::all());
            $user->compartments()->sync($compartmentIds);

            return $user;
        });

        // Create empty starter workspace & clean unconfigured portal branding under PostgreSQL RLS
        $this->dbContext->runAs($superAdmin, function () use ($superAdmin, $orgName, $profileCode, $request): void {
            Cabinet::query()->create([
                'tenant_id' => $superAdmin->tenant_id,
                'org_unit_id' => $superAdmin->org_unit_id,
                'name' => 'General Workspace',
                'description' => 'Default empty cabinet ready for your organisation folder taxonomy.',
                'default_classification_rank' => 0,
            ]);

            $shortName = trim(substr($orgName, 0, 32));
            $preset = $this->branding->presets()[$profileCode->value] ?? $this->branding->presets()['betterdoc'];

            /** @var OrganisationSetting $setting */
            $setting = OrganisationSetting::query()->create([
                'tenant_id' => $superAdmin->tenant_id,
                'is_public_default' => true,
                'profile_key' => $profileCode->value,
                'name' => $orgName,
                'short_name' => $shortName,
                'tagline' => "{$orgName} Secure Document Portal",
                'primary_color' => $preset['primary_color'],
                'accent_color' => $preset['accent_color'],
                'welcome_title' => "Welcome to {$orgName}",
                'welcome_text' => 'Configure your company logo, welcome notice, and support details in Settings > Branding, then create users and assign roles.',
                'notice_text' => '',
                'notice_enabled' => false,
                'support_email' => null,
                'support_phone' => null,
                'support_hours' => null,
                'address' => null,
                'website' => null,
                'show_powered_by' => true,
                'landing_mode' => 'portal',
            ]);

            $this->branding->activatePublicDefault($setting);

            $this->audit->log(
                tenantId: $superAdmin->tenant_id,
                eventType: 'organisation.registered',
                resourceType: 'tenant',
                resourceId: $superAdmin->tenant_id,
                actor: $superAdmin,
                classificationRank: $superAdmin->clearance_rank,
                after: [
                    'organisation_name' => $orgName,
                    'profile_code' => $profileCode->value,
                    'super_admin_email' => $superAdmin->email,
                ],
                request: $request,
            );
        });

        Auth::login($superAdmin);
        $request->session()->regenerate();

        UserSession::query()->create([
            'user_id' => $superAdmin->id,
            'session_id' => $request->session()->getId(),
            'ip_address' => (string) $request->ip(),
            'user_agent_hash' => hash('sha256', (string) ($request->userAgent() ?? 'unknown')),
            'device_label' => substr((string) ($request->userAgent() ?? 'Workstation'), 0, 100),
            'mfa_verified_at' => now(),
            'last_seen_at' => now(),
        ]);

        return redirect()
            ->route('settings.branding', ['onboarding' => '1'])
            ->with('success', "Empty '{$orgName}' ({$profileCode->label()}) workspace created! Configure your branding, contact details & logo below, then create your users.");
    }
}
