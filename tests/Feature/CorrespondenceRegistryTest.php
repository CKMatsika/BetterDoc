<?php

declare(strict_types=1);

use App\Enums\CorrespondenceDirection;
use App\Models\Document;
use App\Models\User;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('auto-numbers correspondence entries and isolates classified registry records via RLS', function (): void {
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail(); // Rank 1
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail(); // Rank 3

    $dbContext = app(DbSecurityContext::class);
    $publicDoc = $dbContext->runAs($cpl, static fn (): Document => Document::query()->where('doc_number', 'AFZ/SIG/2026/014')->firstOrFail());

    // Lower-cleared Corporal sees ONLY their cleared entry (AFZ/SIG/2026/001), NEVER Secret AFZ/OPS/2026/088 entry
    $this->signIn($cpl);
    $this->get('/registry')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries', 1)
            ->where('entries.0.document.doc_number', 'AFZ/SIG/2026/001')
        );

    // Register a new Incoming entry on cleared document
    $this->post('/registry', [
        'document_id' => $publicDoc->id,
        'direction' => CorrespondenceDirection::Incoming->value,
        'external_party' => 'Civil Aviation Authority',
        'security_folio_no' => 'FOLIO-SIG-99',
        'physical_file_barcode' => 'BAR-SIG-0099',
        'bring_up_date' => now()->addDays(3)->toDateString(),
    ])->assertSessionDoesntHaveErrors();

    $this->get('/registry')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries', 2)
        );

    // Wing Commander sees all 3 entries including Secret AFZ/OPS/2026/088 correspondence
    $this->signIn($wgCdr);
    $this->get('/registry')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries', 3)
        );
});
