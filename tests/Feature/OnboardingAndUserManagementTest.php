<?php

declare(strict_types=1);

use App\Enums\BetterDocRole;
use App\Enums\ProfileCode;
use App\Enums\UserStatus;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\OrganisationSetting;
use App\Models\OrgUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Security\DbSecurityContext;
use App\Services\Security\PermissionCatalog;
use Spatie\Permission\PermissionRegistrar;

it('allows self-service organisation registration for corporate and government portals with empty starter workspaces', function (): void {
    config()->set('betterdoc.demo_switcher_enabled', false);
    config()->set('betterdoc.self_registration_enabled', true);

    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->where('demoUsers', [])
        );

    $this->get('/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/Register')
            ->has('organisationTypes', 4)
        );

    $response = $this->post('/register', [
        'organisation_type' => ProfileCode::Corporate->value,
        'organisation_name' => 'Clemie Corp Holdings',
        'name' => 'Clemie Super Admin',
        'email' => 'clemie90@gmail.com',
        'password' => 'Caution90@#$%',
        'password_confirmation' => 'Caution90@#$%',
    ]);

    $response->assertRedirect(route('settings.branding', ['onboarding' => '1']));

    /** @var User $superAdmin */
    $superAdmin = User::query()->where('email', 'clemie90@gmail.com')->firstOrFail();
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->findOrFail($superAdmin->tenant_id);

    expect($tenant->name)->toBe('Clemie Corp Holdings')
        ->and($tenant->active_profile_code)->toBe(ProfileCode::Corporate)
        ->and($superAdmin->mfa_enforced)->toBeFalse();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    expect($superAdmin->hasRole(BetterDocRole::SystemAdmin->value))->toBeTrue()
        ->and($superAdmin->can('manage-branding'))->toBeTrue()
        ->and($superAdmin->can('manage-users'))->toBeTrue();

    // Empty starter workspace assertions under PostgreSQL RLS
    app(DbSecurityContext::class)->runAs($superAdmin, function () use ($tenant): void {
        expect(Document::query()->where('tenant_id', $tenant->id)->count())->toBe(0)
            ->and(OrgUnit::query()->where('tenant_id', $tenant->id)->count())->toBe(1)
            ->and(Cabinet::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
    });

    /** @var OrganisationSetting $setting */
    $setting = OrganisationSetting::query()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($setting->landing_mode)->toBe('portal')
        ->and($setting->is_public_default)->toBeTrue()
        ->and($setting->support_email)->toBeNull();

    // Onboarding user can access Settings > Branding and Users & Roles immediately
    $this->get('/settings/branding')->assertOk();
    $this->get('/admin/users')->assertOk();

    // Logging out and logging back in with real credentials works cleanly
    $this->post('/logout')->assertRedirect('/login');

    $this->post('/login', [
        'identifier' => 'clemie90@gmail.com',
        'password' => 'Caution90@#$%',
    ])->assertRedirect('/dashboard');

    $this->get('/dashboard')->assertOk();
    $this->post('/logout');

    // Second registration using Government profile also succeeds cleanly
    $this->post('/register', [
        'organisation_type' => ProfileCode::Government->value,
        'organisation_name' => 'Ministry of Public Works',
        'name' => 'Ministry Admin',
        'email' => 'registry.admin@works.gov.zw',
        'password' => 'GovSecure!2026',
        'password_confirmation' => 'GovSecure!2026',
    ])->assertRedirect(route('settings.branding', ['onboarding' => '1']));

    /** @var User $govAdmin */
    $govAdmin = User::query()->where('email', 'registry.admin@works.gov.zw')->firstOrFail();
    /** @var Tenant $govTenant */
    $govTenant = Tenant::query()->findOrFail($govAdmin->tenant_id);

    expect($govTenant->active_profile_code)->toBe(ProfileCode::Government);
});

it('lets the Super Admin configure branding, create departments and users, assign roles and permissions, and blocks non-admins', function (): void {
    config()->set('betterdoc.self_registration_enabled', true);

    $this->post('/register', [
        'organisation_type' => ProfileCode::Corporate->value,
        'organisation_name' => 'Horizon Enterprise',
        'name' => 'Clemie Admin',
        'email' => 'clemie90@gmail.com',
        'password' => 'Caution90@#$%',
        'password_confirmation' => 'Caution90@#$%',
    ])->assertRedirect(route('settings.branding', ['onboarding' => '1']));

    /** @var User $superAdmin */
    $superAdmin = User::query()->where('email', 'clemie90@gmail.com')->firstOrFail();
    $tenantId = $superAdmin->tenant_id;

    // Step 1: Super Admin customizes branding and verifies "/" switches to branded portal
    $this->post('/settings/branding', [
        'name' => 'Horizon Configured Portal',
        'short_name' => 'Horizon',
        'tagline' => 'Verified Corporate Records Hub',
        'primary_color' => '#0F172A',
        'accent_color' => '#0E7C66',
        'welcome_title' => 'Horizon Staff Workspace',
        'welcome_text' => 'Configured by Super Admin with zero code changes.',
        'notice_enabled' => true,
        'notice_text' => 'Welcome to our configured portal.',
        'support_email' => 'helpdesk@horizon.co.zw',
        'support_phone' => '+263 24 200 1122',
        'landing_mode' => 'portal',
        'show_powered_by' => true,
    ])->assertSessionDoesntHaveErrors();

    // Step 2: Create custom department
    $this->post('/admin/org-units', [
        'code' => 'FIN',
        'name' => 'Finance Department',
        'unit_type' => 'department',
    ])->assertSessionDoesntHaveErrors();

    /** @var OrgUnit $finUnit */
    $finUnit = OrgUnit::query()
        ->where('tenant_id', $tenantId)
        ->where('code', 'FIN')
        ->firstOrFail();

    /** @var ClearanceLevel $internalLevel */
    $internalLevel = ClearanceLevel::query()
        ->where('tenant_id', $tenantId)
        ->where('rank', 1)
        ->firstOrFail();

    /** @var ClearanceLevel $confidentialLevel */
    $confidentialLevel = ClearanceLevel::query()
        ->where('tenant_id', $tenantId)
        ->where('rank', 2)
        ->firstOrFail();

    // Step 3: Create user with explicit Role & Granular Permissions
    $this->post('/admin/users', [
        'name' => 'Tariro Records',
        'email' => 'tariro@horizon.co.zw',
        'password' => 'UserPass!2026',
        'rank_title' => 'Records Officer',
        'org_unit_id' => $finUnit->id,
        'clearance_level_id' => $internalLevel->id,
        'roles' => [BetterDocRole::RecordsOfficer->value],
        'permissions' => ['documents.view', 'documents.create', 'compliance.export'],
        'mfa_enforced' => false,
    ])->assertSessionDoesntHaveErrors();

    /** @var User $staffUser */
    $staffUser = User::query()->where('email', 'tariro@horizon.co.zw')->firstOrFail();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
    expect($staffUser->org_unit_id)->toBe($finUnit->id)
        ->and($staffUser->clearance_rank)->toBe(1)
        ->and($staffUser->hasRole(BetterDocRole::RecordsOfficer->value))->toBeTrue()
        ->and($staffUser->can('documents.create'))->toBeTrue()
        ->and($staffUser->can('compliance.export'))->toBeTrue()
        ->and($staffUser->can('manage-users'))->toBeFalse();

    // Step 4: Super Admin updates staff user's roles, permissions, clearance, and suspends/reactivates
    $this->patch("/admin/users/{$staffUser->id}", [
        'clearance_level_id' => $confidentialLevel->id,
        'org_unit_id' => $finUnit->id,
        'roles' => [BetterDocRole::Approver->value, BetterDocRole::RecordsOfficer->value],
        'permissions' => PermissionCatalog::all(),
        'status' => UserStatus::Active->value,
        'mfa_enforced' => false,
    ])->assertSessionDoesntHaveErrors();

    $staffUser->refresh();
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
    expect($staffUser->clearance_rank)->toBe(2)
        ->and($staffUser->hasRole(BetterDocRole::Approver->value))->toBeTrue()
        ->and($staffUser->can('documents.approve'))->toBeTrue();

    // Step 5: Non-SystemAdmin user is blocked from /admin/users endpoints and unauthenticated "/" renders custom portal
    $this->post('/logout');

    $landingContent = $this->get('/')->assertOk()->getContent();
    expect($landingContent)->toContain('Horizon Configured Portal');

    $this->signIn($staffUser);
    $this->get('/admin/users')->assertForbidden();
    $this->post('/admin/org-units', ['code' => 'HACK', 'name' => 'Unauthorized'])->assertForbidden();
    $this->post('/admin/users', [
        'name' => 'Rogue',
        'email' => 'rogue@horizon.co.zw',
        'password' => 'RoguePass!2026',
        'clearance_level_id' => $internalLevel->id,
        'roles' => [BetterDocRole::Reader->value],
    ])->assertForbidden();
    $this->patch("/admin/users/{$superAdmin->id}", [
        'clearance_level_id' => $internalLevel->id,
        'roles' => [BetterDocRole::Reader->value],
        'status' => UserStatus::Suspended->value,
    ])->assertForbidden();
});
