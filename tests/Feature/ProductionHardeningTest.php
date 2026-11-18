<?php

declare(strict_types=1);

use App\Enums\BetterDocRole;
use App\Enums\ProfileCode;
use App\Enums\UserStatus;
use App\Models\ClearanceLevel;
use App\Models\User;
use App\Models\UserSession;
use Database\Seeders\AfzDefenceSeeder;
use Database\Seeders\CorporateSeeder;
use Illuminate\Support\Facades\RateLimiter;

it('enforces route rate limits on login and MFA verification endpoints', function (): void {
    $this->seed([AfzDefenceSeeder::class]);
    RateLimiter::clear('login');
    RateLimiter::clear('mfa');

    for ($i = 0; $i < 10; $i++) {
        $this->post('/login', [
            'identifier' => 'nobody@betterdoc.lan',
            'password' => 'WrongPassword!123',
        ])->assertSessionHasErrors('identifier');
    }

    $this->post('/login', [
        'identifier' => 'nobody@betterdoc.lan',
        'password' => 'WrongPassword!123',
    ])->assertStatus(429);

    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    $this->actingAs($wgCdr);

    for ($i = 0; $i < 10; $i++) {
        $this->post('/mfa-challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code');
    }

    $this->post('/mfa-challenge', ['code' => '000000'])
        ->assertStatus(429);
});

it('hard-blocks demo-login in production environment even if enabled in config', function (): void {
    $this->seed([CorporateSeeder::class]);

    /** @var User $corpAdmin */
    $corpAdmin = User::query()->where('email', 'admin@zimcorp.co.zw')->firstOrFail();
    config()->set('betterdoc.demo_switcher_enabled', true);

    app()->detectEnvironment(static fn (): string => 'production');
    try {
        $this->withSession(['_token' => 'test-csrf'])
            ->post('/demo-login', [
                '_token' => 'test-csrf',
                'user_id' => $corpAdmin->id,
            ])
            ->assertNotFound();
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }
});

it('enforces strong 12+ character mixed-case number-and-symbol passwords on onboarding and user creation', function (): void {
    config()->set('betterdoc.self_registration_enabled', true);

    $this->post('/register', [
        'organisation_type' => ProfileCode::Corporate->value,
        'organisation_name' => 'StrongPass Corp',
        'name' => 'Admin User',
        'email' => 'admin@strongpass.test',
        'password' => 'weakpass',
        'password_confirmation' => 'weakpass',
    ])->assertSessionHasErrors('password');

    $this->post('/register', [
        'organisation_type' => ProfileCode::Corporate->value,
        'organisation_name' => 'StrongPass Corp',
        'name' => 'Admin User',
        'email' => 'admin@strongpass.test',
        'password' => 'Caution90@#$%',
        'password_confirmation' => 'Caution90@#$%',
    ])->assertRedirect(route('settings.branding', ['onboarding' => '1']));

    /** @var User $admin */
    $admin = User::query()->where('email', 'admin@strongpass.test')->firstOrFail();
    /** @var ClearanceLevel $level */
    $level = ClearanceLevel::query()->where('tenant_id', $admin->tenant_id)->orderBy('rank')->firstOrFail();

    $this->post('/admin/users', [
        'name' => 'Staff Member',
        'email' => 'staff@strongpass.test',
        'password' => 'simplepassword',
        'clearance_level_id' => $level->id,
        'roles' => [BetterDocRole::Author->value],
    ])->assertSessionHasErrors('password');
});

it('immediately revokes active sessions and blocks suspended users mid-session', function (): void {
    $this->seed([CorporateSeeder::class]);

    /** @var User $sysAdmin */
    $sysAdmin = User::query()->where('email', 'admin@zimcorp.co.zw')->firstOrFail();
    /** @var User $staff */
    $staff = User::query()->where('email', 'clerk@zimcorp.co.zw')->firstOrFail();

    UserSession::query()->create([
        'user_id' => $staff->id,
        'session_id' => 'active-session-id',
        'ip_address' => '127.0.0.1',
        'user_agent_hash' => hash('sha256', 'Symfony'),
        'device_label' => 'Browser',
        'mfa_verified_at' => now(),
        'last_seen_at' => now(),
    ]);

    $this->signIn($sysAdmin);
    $this->patch("/admin/users/{$staff->id}", [
        'clearance_level_id' => $staff->clearance_level_id,
        'org_unit_id' => $staff->org_unit_id,
        'roles' => [BetterDocRole::Author->value],
        'status' => UserStatus::Suspended->value,
    ])->assertSessionDoesntHaveErrors();

    expect($staff->refresh()->status)->toBe(UserStatus::Suspended)
        ->and(UserSession::query()->where('user_id', $staff->id)->whereNull('revoked_at')->count())->toBe(0);

    $this->actingAs($staff);
    $this->get('/dashboard')->assertForbidden();
});

it('emits cross-domain and HSTS security headers over HTTPS', function (): void {
    $response = $this->get('https://betterdoc.lan/');
    $response->assertOk();
    $response->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
