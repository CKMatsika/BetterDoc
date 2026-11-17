<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\BreakGlassSession;
use App\Models\Document;
use App\Models\User;
use App\Services\Security\BreakGlassService;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('requires justification and two independent cleared approvers for break-glass access', function (): void {
    /** @var User $sqnLdr */
    $sqnLdr = User::query()->where('email', 'sqnldr.ndlovu@afz.mil.zw')->firstOrFail(); // Rank 2
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail(); // Rank 3
    /** @var User $avm */
    $avm = User::query()->where('email', 'avm.chikwanda@afz.mil.zw')->firstOrFail(); // Rank 4

    $dbContext = app(DbSecurityContext::class);
    $secretDoc = $dbContext->runAs($avm, static fn (): Document => Document::query()->where('doc_number', 'AFZ/OPS/2026/088')->firstOrFail());

    // Initially, Rank 2 officer gets 404 on Secret doc
    $this->signIn($sqnLdr);
    $this->get("/documents/{$secretDoc->id}")->assertNotFound();

    // Short justification rejected
    $this->post('/break-glass', [
        'document_id' => $secretDoc->id,
        'justification' => 'Too short',
    ])->assertSessionHasErrors('justification');

    // Valid operational justification submitted
    $this->post('/break-glass', [
        'document_id' => $secretDoc->id,
        'justification' => 'Urgent search-and-rescue airspace corridor verification during night operations.',
        'duration_hours' => 2,
    ])->assertSessionDoesntHaveErrors();

    /** @var BreakGlassSession $bg */
    $bg = $dbContext->runAs($sqnLdr, static fn (): BreakGlassSession => BreakGlassSession::query()
        ->where('user_id', $sqnLdr->id)
        ->where('document_id', $secretDoc->id)
        ->firstOrFail()
    );

    // 1st approval by Wing Commander
    $this->signIn($wgCdr, mfaVerified: true, stepUpFresh: true);
    $this->post("/break-glass/{$bg->id}/approve-first")->assertSessionDoesntHaveErrors();

    // Same approver CANNOT give 2nd approval
    $this->post("/break-glass/{$bg->id}/approve-second")->assertSessionHasErrors('break_glass');

    // Only 1st approval is not yet active -> requester still gets 404
    $this->signIn($sqnLdr);
    $this->get("/documents/{$secretDoc->id}")->assertNotFound();

    // 2nd distinct approval by AVM activates break-glass
    $this->signIn($avm, mfaVerified: true, stepUpFresh: true);
    $this->post("/break-glass/{$bg->id}/approve-second")->assertSessionDoesntHaveErrors();

    // Requester now sees document with Break-Glass watermark flag and loud audit event
    $this->signIn($sqnLdr);
    $this->get("/documents/{$secretDoc->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('isBreakGlass', true));

    expect(AuditEvent::query()->where('event_type', 'document.viewed_break_glass')->where('resource_id', $secretDoc->id)->exists())->toBeTrue();

    // Once revoked, access immediately returns 404
    $dbContext->runAs($avm, fn () => app(BreakGlassService::class)->revoke($bg->refresh(), $avm));
    $this->signIn($sqnLdr);
    $this->get("/documents/{$secretDoc->id}")->assertNotFound();
});
