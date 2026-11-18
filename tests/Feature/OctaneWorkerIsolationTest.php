<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\User;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('leaves zero PostgreSQL RLS context bleed across sequential requests on the same worker connection', function (): void {
    /** @var User $avm */
    $avm = User::query()->where('email', 'avm.chikwanda@afz.mil.zw')->firstOrFail();
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail();

    $topSecret = app(DbSecurityContext::class)->runAs(
        $avm,
        static fn (): Document => Document::query()->where('doc_number', 'AFZ/CMD/2026/003')->firstOrFail()
    );

    // Request 1: Top Secret Air Vice-Marshal reads Top Secret document
    $this->signIn($avm);
    $this->get("/documents/{$topSecret->id}")->assertOk();

    // After request completes, PostgreSQL session GUCs must be cleanly wiped
    $leakedUser = DB::selectOne("SELECT current_setting('betterdoc.user_id', true) AS uid");
    expect((string) ($leakedUser->uid ?? ''))->toBe('');

    // Request 2 on the same worker: Corporal (Rank 1) gets 404 on that exact Top Secret document
    $this->signIn($cpl);
    $this->get("/documents/{$topSecret->id}")->assertNotFound();

    // Direct raw query after middleware exits returns 0 rows
    expect(DB::table('documents')->count())->toBe(0);
});
