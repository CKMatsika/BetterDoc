<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\User;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;
use Database\Seeders\GoldenKnotFinancialSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class, GoldenKnotFinancialSeeder::class]);
});

it('hides higher-classification and missing-compartment documents via 404 anti-enumeration and listing scopes', function (): void {
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail();
    /** @var User $avm */
    $avm = User::query()->where('email', 'avm.chikwanda@afz.mil.zw')->firstOrFail();
    /** @var User $sysAdmin */
    $sysAdmin = User::query()->where('email', 'fltlt.nyoni@afz.mil.zw')->firstOrFail();

    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();

    $dbContext = app(DbSecurityContext::class);

    [$secretDoc, $topSecretDoc] = $dbContext->runAs($avm, function (): array {
        return [
            Document::query()->where('doc_number', 'AFZ/OPS/2026/088')->firstOrFail(),
            Document::query()->where('doc_number', 'AFZ/CMD/2026/003')->firstOrFail(),
        ];
    });
    $intelDoc = $dbContext->runAs($wgCdr, static fn (): Document => Document::query()->where('doc_number', 'AFZ/INTEL/2026/019')->firstOrFail());

    // Lower-clearance Corporal (Rank 1, no compartments) gets uniform 404 on Rank 3/4 docs
    $this->signIn($cpl);

    $this->get("/documents/{$secretDoc->id}")->assertNotFound();
    $this->get("/documents/{$intelDoc->id}")->assertNotFound();
    $this->get("/documents/{$topSecretDoc->id}")->assertNotFound();

    // Non-existent random UUID ALSO returns 404 (indistinguishable oracle)
    $this->get('/documents/01933a10-0000-7000-8000-000000000099')->assertNotFound();

    // Vault listing & search must exclude Secret/Top Secret docs and report exact cleared count (2)
    $this->get('/documents?q=Zambezi')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Documents/Index')
            ->has('documents', 0)
        );

    $this->get('/documents')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('documents', 2)
            ->has('cabinets', 1) // Higher-classified cabinets (rank 2, rank 3) are ALSO hidden by RLS
        );

    // Separation of Duty: SystemAdmin with Rank 1 STILL cannot access Secret/Top Secret documents
    $this->signIn($sysAdmin);
    $this->get("/documents/{$secretDoc->id}")->assertNotFound();
    $this->get("/documents/{$topSecretDoc->id}")->assertNotFound();

    // Wing Commander (Rank 3, OPS+INTEL) sees Secret & Intel docs, but still gets 404 on Top Secret
    $this->signIn($wgCdr);
    $this->get("/documents/{$secretDoc->id}")->assertOk();
    $this->get("/documents/{$intelDoc->id}")->assertOk();
    $this->get("/documents/{$topSecretDoc->id}")->assertNotFound();
});

it('enforces PostgreSQL FORCE ROW LEVEL SECURITY even on raw DB::table queries', function (): void {
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail();
    /** @var User $avm */
    $avm = User::query()->where('email', 'avm.chikwanda@afz.mil.zw')->firstOrFail();

    $dbContext = app(DbSecurityContext::class);

    $avmRawCount = $dbContext->runAs($avm, static fn (): int => DB::table('documents')->count());
    $cplRawCount = $dbContext->runAs($cpl, static fn (): int => DB::table('documents')->count());

    expect($avmRawCount)->toBe(4)
        ->and($cplRawCount)->toBe(2);
});

it('blocks cross-tenant document access and enforces HR subject shield', function (): void {
    /** @var User $avm */
    $avm = User::query()->where('email', 'avm.chikwanda@afz.mil.zw')->firstOrFail();
    /** @var User $cro */
    $cro = User::query()->where('email', 'cro.gumbo@golden-knot.co.zw')->firstOrFail();
    /** @var User $analyst */
    $analyst = User::query()->where('email', 'analyst.chigumba@golden-knot.co.zw')->firstOrFail();

    $dbContext = app(DbSecurityContext::class);

    [$gkPublic, $gkHrDoc] = $dbContext->runAs($cro, function (): array {
        return [
            Document::query()->where('doc_number', 'GK/PUB/2026/001')->firstOrFail(),
            Document::query()->where('doc_number', 'GK/HR/2026/044')->firstOrFail(),
        ];
    });

    // AFZ Top Secret user gets 404 on Golden Knot Public doc
    $this->signIn($avm);
    $this->get("/documents/{$gkPublic->id}")->assertNotFound();

    // Credit Analyst cannot view their own HR restricted disciplinary inquiry
    $this->signIn($analyst);
    $this->get("/documents/{$gkHrDoc->id}")->assertNotFound();
});
