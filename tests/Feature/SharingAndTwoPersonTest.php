<?php

declare(strict_types=1);

use App\Enums\PrincipalType;
use App\Enums\TwoPersonStatus;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\TwoPersonRequest;
use App\Models\User;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('auto-denies sharing to under-cleared or uncompartmented recipients and logs denial', function (): void {
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail(); // Rank 3 OPS+INTEL
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail(); // Rank 1 no comps
    /** @var User $avm */
    $avm = User::query()->where('email', 'avm.chikwanda@afz.mil.zw')->firstOrFail(); // Rank 4 all comps

    $dbContext = app(DbSecurityContext::class);
    $intelDoc = $dbContext->runAs($wgCdr, static fn (): Document => Document::query()->where('doc_number', 'AFZ/INTEL/2026/019')->firstOrFail());

    $this->signIn($wgCdr);

    // Sharing Secret OPS+INTEL doc to Restricted Corporal fails with validation error & audit log
    $this->post("/documents/{$intelDoc->id}/share", [
        'principal_type' => PrincipalType::User->value,
        'principal_id' => $cpl->id,
        'perm_view' => true,
    ])->assertSessionHasErrors('principal_id');

    expect(AuditEvent::query()->where('event_type', 'share.denied_insufficient_clearance')->where('resource_id', $intelDoc->id)->exists())->toBeTrue();

    // Sharing to equally/higher cleared AVM with OPS+INTEL succeeds
    $this->post("/documents/{$intelDoc->id}/share", [
        'principal_type' => PrincipalType::User->value,
        'principal_id' => $avm->id,
        'perm_view' => true,
        'perm_print' => true,
    ])->assertSessionDoesntHaveErrors();
});

it('enforces two-person non-self-approval, fresh MFA step-up, and atomic declassification', function (): void {
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail(); // Initiated pending request in seeder
    /** @var User $avm */
    $avm = User::query()->where('email', 'avm.chikwanda@afz.mil.zw')->firstOrFail(); // Rank 4 Approver
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail(); // Rank 1

    $dbContext = app(DbSecurityContext::class);

    /** @var TwoPersonRequest $tpRequest */
    $tpRequest = $dbContext->runAs($wgCdr, static fn (): TwoPersonRequest => TwoPersonRequest::query()
        ->where('tenant_id', $wgCdr->tenant_id)
        ->where('status', TwoPersonStatus::Pending->value)
        ->firstOrFail()
    );

    // 1. Requester cannot self-approve
    $this->signIn($wgCdr, mfaVerified: true, stepUpFresh: true);
    $this->post("/approvals/{$tpRequest->id}/approve")->assertSessionHasErrors('approval');

    // 2. Under-cleared user cannot approve
    $this->signIn($cpl, mfaVerified: true, stepUpFresh: true);
    $this->post("/approvals/{$tpRequest->id}/approve")->assertSessionHasErrors('approval');

    // 3. Cleared Approver with stale MFA step-up (>5 min ago) is blocked
    $this->signIn($avm, mfaVerified: true, stepUpFresh: false);
    $this->post("/approvals/{$tpRequest->id}/approve")->assertSessionHasErrors('approval');

    // 4. Cleared Approver with fresh MFA step-up approves and executes declassification atomically
    $this->signIn($avm, mfaVerified: true, stepUpFresh: true);
    $this->post("/approvals/{$tpRequest->id}/approve")->assertSessionDoesntHaveErrors();

    $dbContext->runAs($avm, function () use ($tpRequest): void {
        $tpRequest->refresh();
        $doc = Document::query()->findOrFail($tpRequest->target_id);
        expect($tpRequest->status)->toBe(TwoPersonStatus::Approved)
            ->and($doc->classification_rank)->toBe(2);
    });
});
