<?php

declare(strict_types=1);

use App\Enums\TwoPersonStatus;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Compartment;
use App\Models\Document;
use App\Models\TwoPersonRequest;
use App\Models\User;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('prevents creating documents above clearance or unassigned compartments and enforces strict Bell-LaPadula write-down', function (): void {
    /** @var User $sqnLdr */
    $sqnLdr = User::query()->where('email', 'sqnldr.ndlovu@afz.mil.zw')->firstOrFail(); // Rank 2, OPS only
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail(); // Rank 3, OPS+INTEL

    $secretLevel = ClearanceLevel::query()->where('tenant_id', $sqnLdr->tenant_id)->where('rank', 3)->firstOrFail();
    $confidentialLevel = ClearanceLevel::query()->where('tenant_id', $sqnLdr->tenant_id)->where('rank', 2)->firstOrFail();
    $restrictedLevel = ClearanceLevel::query()->where('tenant_id', $sqnLdr->tenant_id)->where('rank', 1)->firstOrFail();
    $intelComp = Compartment::query()->where('tenant_id', $sqnLdr->tenant_id)->where('code', 'INTEL')->firstOrFail();

    $dbContext = app(DbSecurityContext::class);
    $cabinet = $dbContext->runAs($sqnLdr, static fn (): Cabinet => Cabinet::query()->where('name', 'Administrative Orders')->firstOrFail());

    $this->signIn($sqnLdr);

    // Cannot create Secret doc as Rank 2 Confidential officer
    $this->post('/documents', [
        'cabinet_id' => $cabinet->id,
        'classification_level_id' => $secretLevel->id,
        'doc_number' => 'AFZ/TEST/901',
        'title' => 'Unauthorized Over-Classified Record',
    ])->assertSessionHasErrors('classification_level_id');

    // Cannot tag INTEL compartment when user only holds OPS
    $this->post('/documents', [
        'cabinet_id' => $cabinet->id,
        'classification_level_id' => $confidentialLevel->id,
        'doc_number' => 'AFZ/TEST/902',
        'title' => 'Unauthorized Compartment Tag',
        'compartment_ids' => [$intelComp->id],
    ])->assertSessionHasErrors('compartment_ids');

    // Can create Restricted doc within own clearance & upgrade it to Confidential
    $this->post('/documents', [
        'cabinet_id' => $cabinet->id,
        'classification_level_id' => $restrictedLevel->id,
        'doc_number' => 'AFZ/TEST/903',
        'title' => 'Signals Readiness Report',
    ])->assertRedirect();

    $doc = $dbContext->runAs($sqnLdr, static fn (): Document => Document::query()->where('doc_number', 'AFZ/TEST/903')->firstOrFail());

    // Upgrade 1 -> 2 allowed immediately
    $this->post("/documents/{$doc->id}/reclassify", [
        'target_classification_level_id' => $confidentialLevel->id,
        'reason' => 'Operational sensitivity increased after exercise briefing.',
    ])->assertSessionDoesntHaveErrors();

    $dbContext->runAs($sqnLdr, function () use ($doc): void {
        expect($doc->refresh()->classification_rank)->toBe(2);
    });

    // Attempting write-down (3 -> 1) as Wing Commander on Secret doc queues Two-Person Request instead of lowering immediately
    $secretDoc = $dbContext->runAs($wgCdr, static fn (): Document => Document::query()->where('doc_number', 'AFZ/OPS/2026/088')->firstOrFail());
    $this->signIn($wgCdr);

    $this->post("/documents/{$secretDoc->id}/reclassify", [
        'target_classification_level_id' => $restrictedLevel->id,
        'reason' => 'Requesting declassification for historical unit archive.',
    ])->assertSessionDoesntHaveErrors();

    $dbContext->runAs($wgCdr, function () use ($secretDoc): void {
        expect($secretDoc->refresh()->classification_rank)->toBe(3)
            ->and(TwoPersonRequest::query()->where('target_id', $secretDoc->id)->where('status', TwoPersonStatus::Pending->value)->count())->toBe(2);
    });
});
