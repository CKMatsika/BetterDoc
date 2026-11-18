<?php

declare(strict_types=1);

use App\Enums\ProfileCode;
use App\Exceptions\AirGapViolationException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Profile\ProfileManager;
use Database\Seeders\AfzDefenceSeeder;
use Database\Seeders\CorporateSeeder;
use Database\Seeders\GoldenKnotFinancialSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('enforces offline TOTP MFA challenge, account lockout, and IP CIDR restriction', function (): void {
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    $demoPassword = (string) env('BETTERDOC_DEMO_PASSWORD', 'BetterDocDemo!2026');

    // Login using Service Number succeeds and redirects to MFA challenge
    $this->post('/login', [
        'identifier' => 'AFZ-0142',
        'password' => $demoPassword,
    ])->assertRedirect(route('mfa.challenge'));

    // Preserve session cookie across requests just like a browser
    $this->withCookie((string) config('session.cookie'), session()->getId());

    // Dashboard blocked before MFA challenge verified
    $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));

    // Generate valid offline TOTP token and verify
    $secret = Crypt::decryptString((string) $wgCdr->two_factor_secret);
    $otp = (new Google2FA)->getCurrentOtp($secret);

    $this->post('/mfa-challenge', ['code' => $otp])
        ->assertRedirect('/dashboard');

    $this->get('/dashboard')->assertOk();

    // Test IP subnet restriction denial
    $wgCdr->update(['allowed_cidrs' => ['10.20.0.0/16']]);
    $this->post('/logout');
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.50.99'])
        ->post('/login', ['identifier' => $wgCdr->email, 'password' => $demoPassword])
        ->assertSessionHasErrors('identifier');

    // Reset CIDR and test 5 failed password attempts lockout
    $wgCdr->update(['allowed_cidrs' => null]);
    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['identifier' => $wgCdr->email, 'password' => 'wrong-pass'])
            ->assertSessionHasErrors('identifier');
    }
    expect($wgCdr->refresh()->locked_until)->not->toBeNull();
});

it('hard-blocks outbound HTTP requests to external hosts in air-gapped mode while allowing local services', function (): void {
    config()->set('betterdoc.air_gapped', true);
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    expect(fn () => Http::get('https://api.openai.com/v1/models'))
        ->toThrow(AirGapViolationException::class);

    $localResponse = Http::get('http://ollama:11434/api/tags');
    expect($localResponse->successful())->toBeTrue();
});

it('switches organization profiles via Pennant while honoring air-gap cloud blocks', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    $profiles = app(ProfileManager::class);

    expect(Feature::for($tenant)->active('correspondence_registry'))->toBeTrue()
        ->and(Feature::for($tenant)->active('ap_automation'))->toBeFalse()
        ->and(Feature::for($tenant)->active('cloud_sync'))->toBeFalse();

    $profiles->applyToTenant($tenant, ProfileCode::Financial, syncTaxonomy: false);

    // Even when Financial profile enables cloud_sync, global air-gapped flag still forces cloud_sync OFF
    expect(Feature::for($tenant)->active('ap_automation'))->toBeTrue()
        ->and(Feature::for($tenant)->active('zimra_e_invoicing'))->toBeTrue()
        ->and(Feature::for($tenant)->active('cloud_sync'))->toBeFalse();
});

it('seeds corporate portal with password-only login and allows Golden Knot SystemAdmin to switch to corporate profile', function (): void {
    $this->seed([GoldenKnotFinancialSeeder::class, CorporateSeeder::class]);

    /** @var Tenant $corpTenant */
    $corpTenant = Tenant::query()->where('slug', 'zimcorp-corporate')->firstOrFail();
    expect($corpTenant->active_profile_code)->toBe(ProfileCode::Corporate);

    $demoPassword = config()->string('betterdoc.demo_password', 'BetterDocDemo!2026');
    $this->post('/login', [
        'identifier' => 'admin@zimcorp.co.zw',
        'password' => $demoPassword,
    ])->assertRedirect('/dashboard');

    /** @var User $gkAdmin */
    $gkAdmin = User::query()->where('email', 'sysadmin@golden-knot.co.zw')->firstOrFail();
    $this->signIn($gkAdmin);

    $this->post('/admin/profiles/apply', ['profile_code' => ProfileCode::Corporate->value])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    /** @var Tenant $gkTenant */
    $gkTenant = Tenant::query()->where('slug', 'golden-knot')->firstOrFail();
    expect($gkTenant->active_profile_code)->toBe(ProfileCode::Corporate);
});
