<?php

declare(strict_types=1);

use App\Mail\BrandedNotificationMail;
use App\Models\DemoRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Branding\BrandingService;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;
use Database\Seeders\GoldenKnotFinancialSeeder;
use Illuminate\Http\UploadedFile;

it('enforces landing modes (portal default, demo, off), logged-in redirect, security headers, and zero info disclosure', function (): void {
    $this->seed([AfzDefenceSeeder::class]);

    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();

    // 1. Default mode = portal: renders Portal Landing/Show without sales copy or software stack leakage
    config()->set('betterdoc.landing_mode', 'portal');
    $portalResp = $this->get('/')->assertOk();
    $portalResp->assertHeader('X-Content-Type-Options', 'nosniff');
    $portalResp->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $portalResp->assertHeader('Referrer-Policy', 'no-referrer');
    expect($portalResp->headers->get('Content-Security-Policy'))->toContain("default-src 'self'");
    expect($portalResp->getContent())
        ->toContain('BetterDoc DMS')
        ->not->toContain('Laravel')
        ->not->toContain('wgcdr.moyo@afz.mil.zw')
        ->not->toContain('Typical cloud-first DMS');

    // 2. Demo mode renders full marketing layout + comparison table
    config()->set('betterdoc.landing_mode', 'demo');
    $demoResp = $this->get('/')->assertOk();
    $demoResp->assertInertia(fn ($page) => $page
        ->component('Landing/Show')
        ->where('mode', 'demo')
    );

    // 3. Off mode redirects "/" directly to /login
    config()->set('betterdoc.landing_mode', 'off');
    $this->get('/')->assertRedirect('/login');

    // 4. Logged-in users visiting "/" always redirect to /dashboard
    config()->set('betterdoc.landing_mode', 'portal');
    $this->signIn($wgCdr);
    $this->get('/')->assertRedirect('/dashboard');
});

it('restricts branding admin to manage-branding system admins, sanitizes uploads, checks contrast, and propagates changes everywhere', function (): void {
    $this->seed([AfzDefenceSeeder::class, GoldenKnotFinancialSeeder::class]);

    /** @var User $nonAdmin */
    $nonAdmin = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    /** @var User $sysAdmin */
    $sysAdmin = User::query()->where('email', 'sysadmin@golden-knot.co.zw')->firstOrFail();
    /** @var Tenant $gkTenant */
    $gkTenant = Tenant::query()->where('slug', 'golden-knot')->firstOrFail();

    // Non-admin user cannot view or mutate branding settings
    $this->signIn($nonAdmin);
    $this->get('/settings/branding')->assertForbidden();
    $this->post('/settings/branding', ['name' => 'Unauthorized'])->assertForbidden();
    $this->post('/settings/branding/preset', ['profile_key' => 'corporate'])->assertForbidden();
    $this->post('/settings/branding/reset')->assertForbidden();

    // SystemAdmin with manage-branding permission accesses settings cleanly
    $this->signIn($sysAdmin);
    $this->get('/settings/branding')->assertOk();

    // Reject malicious script SVG upload and forged extension upload
    $badSvg = UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $badBin = UploadedFile::fake()->createWithContent('shell.png', '<?php echo "evil"; ?>');

    $this->post('/settings/branding', [
        'name' => 'Test',
        'short_name' => 'TST',
        'tagline' => 'Tagline',
        'primary_color' => '#0E7C66',
        'accent_color' => '#F59E0B',
        'welcome_title' => 'Portal',
        'logo' => $badSvg,
    ])->assertSessionHasErrors('logo');

    $this->post('/settings/branding', [
        'name' => 'Test',
        'short_name' => 'TST',
        'tagline' => 'Tagline',
        'primary_color' => '#0E7C66',
        'accent_color' => '#F59E0B',
        'welcome_title' => 'Portal',
        'logo' => $badBin,
    ])->assertSessionHasErrors('logo');

    // Valid raster PNG (800x800 resized cleanly to max 512px via GD) + SVG with inline event stripped
    $gd = imagecreatetruecolor(800, 400);
    expect($gd)->not->toBeFalse();
    $bg = imagecolorallocate($gd, 14, 124, 102);
    if ($bg !== false) {
        imagefilledrectangle($gd, 0, 0, 799, 399, $bg);
    }
    ob_start();
    imagepng($gd);
    $pngBytes = (string) ob_get_clean();
    imagedestroy($gd);

    $validPng = UploadedFile::fake()->createWithContent('acacia-logo.png', $pngBytes);
    $eventSvg = UploadedFile::fake()->createWithContent(
        'acacia-dark.svg',
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect onload="alert(9)" width="20" height="20" fill="#0E7C66"/></svg>'
    );

    $this->post('/settings/branding', [
        'name' => 'Acacia Vault Holdings & <Co>',
        'short_name' => 'Acacia DMS',
        'tagline' => 'Sovereign branch & head-office document hub',
        'primary_color' => '#0E7C66',
        'accent_color' => '#F59E0B',
        'welcome_title' => 'Acacia Staff Portal',
        'welcome_text' => 'Welcome aboard.',
        'notice_enabled' => true,
        'notice_text' => 'System maintenance Saturday 6pm',
        'support_email' => 'helpdesk@acacia.lan',
        'support_phone' => '+263 24 900 1000',
        'support_hours' => 'Mon-Fri 08:00-17:00',
        'address' => '14 Borrowdale Road, Harare',
        'show_powered_by' => true,
        'landing_mode' => 'portal',
        'logo' => $validPng,
        'logo_dark' => $eventSvg,
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    // Check WCAG contrast evaluator detects low-contrast yellow combinations cleanly
    $lowContrast = app(BrandingService::class)->evaluateContrast('#EAB308', '#FACC15');
    expect($lowContrast['warnings'])->not->toBeEmpty();

    // Clear authenticated session -> verify "/", "/login", uploaded logos, page title, and rendered email ALL reflect Acacia branding at once
    $this->post('/logout');
    $this->app['auth']->forgetGuards();

    $landing = $this->get('/')->assertOk();
    $landing->assertInertia(fn ($page) => $page
        ->component('Landing/Show')
        ->where('mode', 'portal')
        ->where('branding.name', 'Acacia Vault Holdings & <Co>')
        ->where('branding.notice_text', 'System maintenance Saturday 6pm')
        ->where('branding.notice_enabled', true)
    );

    // Ensure raw unescaped HTML "<Co>" does not inject raw HTML tags inside Blade head/title
    expect($landing->getContent())->toContain('Acacia Vault Holdings &amp; &lt;Co&gt;');

    $login = $this->get('/login')->assertOk();
    $login->assertInertia(fn ($page) => $page
        ->where('branding.short_name', 'Acacia DMS')
    );

    $payload = app(BrandingService::class)->toPublicPayload(null, $gkTenant);
    expect($payload['logo_url'])->not->toBeNull()
        ->and($payload['logo_dark_url'])->not->toBeNull();

    $this->get((string) $payload['logo_url'])->assertOk()->assertHeader('Content-Type', 'image/png');
    $svgResp = $this->get((string) $payload['logo_dark_url'])->assertOk();
    expect($svgResp->getContent())->not->toContain('onload');

    // Branded Email renders org name, colours, escaped text, and help desk
    $mailable = new BrandedNotificationMail(
        subjectLine: 'Certificate Issued',
        headingText: 'Signing Certificate Active',
        bodyText: 'Your internal X.509 certificate is ready.',
        tenant: $gkTenant,
    );
    $renderedMail = $mailable->render();
    expect($renderedMail)
        ->toContain('Acacia Vault Holdings &amp; &lt;Co&gt;')
        ->toContain('#0E7C66')
        ->toContain('helpdesk@acacia.lan')
        ->toContain('Powered by BetterDoc DMS');

    // Preset switch & reset-to-defaults restore BetterDoc DMS cleanly
    $this->signIn($sysAdmin);
    $this->post('/settings/branding/preset', ['profile_key' => 'corporate'])->assertRedirect();
    expect(app(BrandingService::class)->toPublicPayload($sysAdmin)['name'])->toBe('ZimCorp Commercial Holdings');

    $this->post('/settings/branding/reset')->assertRedirect();
    expect(app(BrandingService::class)->toPublicPayload($sysAdmin)['name'])->toBe('BetterDoc DMS');
});

it('validates, stores offline, and rate-limits (5/min) demo requests in demo mode and lists them for admins only', function (): void {
    $this->seed([AfzDefenceSeeder::class]);
    /** @var User $sysAdmin */
    $sysAdmin = User::query()->where('email', 'fltlt.nyoni@afz.mil.zw')->firstOrFail();

    // Reject demo intake when mode is portal
    config()->set('betterdoc.landing_mode', 'portal');
    $this->postJson('/demo-request', [
        'name' => 'Jane Doe',
        'organisation' => 'National Archives',
        'work_email' => 'jane@archives.gov.zw',
    ])->assertNotFound();

    // Switch to demo mode -> invalid fields fail validation
    config()->set('betterdoc.landing_mode', 'demo');
    $this->postJson('/demo-request', [
        'name' => '',
        'organisation' => '',
        'work_email' => 'not-an-email',
    ])->assertStatus(422);

    // First 5 requests from an IP pass, 6th request within the same minute hits 429 rate limit
    for ($i = 1; $i <= 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.20.5'])
            ->postJson('/demo-request', [
                'name' => "Requester {$i}",
                'organisation' => 'National Archives',
                'work_email' => "req{$i}@archives.gov.zw",
                'phone' => '+263 24 000 0000',
                'message' => 'Offline site demo please.',
            ])
            ->assertCreated();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.10.20.5'])
        ->postJson('/demo-request', [
            'name' => 'Requester 6',
            'organisation' => 'National Archives',
            'work_email' => 'req6@archives.gov.zw',
        ])
        ->assertStatus(429);

    // PostgreSQL RLS shields demo_requests from anonymous/non-admin SQL reads and exposes only to SystemAdmin
    expect(DemoRequest::query()->count())->toBe(0);
    expect(app(DbSecurityContext::class)->runAs($sysAdmin, static fn (): int => DemoRequest::query()->count()))->toBe(5);

    // Admin sees submitted demo requests inside Settings > Branding
    $this->signIn($sysAdmin);
    $this->get('/settings/branding')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('demoRequests', 5)
            ->where('demoRequests.0.organisation', 'National Archives')
        );
});
