<?php

declare(strict_types=1);

use App\Enums\CertificateCustodyMode;
use App\Enums\EnvelopeMode;
use App\Models\Document;
use App\Models\SignatureEnvelope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pki\InternalCaService;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('blocks under-cleared users from signing, reassignment, certificate download, and classified /verify/{token} metadata', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail(); // Rank 3 OPS+INTEL
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail(); // Rank 1 no comps
    /** @var User $avm */
    $avm = User::query()->where('email', 'avm.chikwanda@afz.mil.zw')->firstOrFail(); // Rank 4 all comps
    $dbContext = app(DbSecurityContext::class);

    $caService = app(InternalCaService::class);
    $dbContext->runAs($wgCdr, function () use ($caService, $tenant, $wgCdr, $avm): void {
        $caService->ensureBootstrapped($tenant, $wgCdr);
        $caService->issueUserCertificate($wgCdr, CertificateCustodyMode::HsmManaged, $wgCdr);
        $caService->issueUserCertificate($avm, CertificateCustodyMode::HsmManaged, $wgCdr);
    });

    /** @var Document $secretDoc */
    $secretDoc = $dbContext->runAs($wgCdr, static fn (): Document => Document::query()->where('doc_number', 'AFZ/OPS/2026/088')->firstOrFail());

    // 1. Lower-cleared Corporal CANNOT initiate or be added as signer on Secret doc
    $this->signIn($wgCdr);
    $this->post("/documents/{$secretDoc->id}/envelopes", [
        'title' => 'Illegal Low-Clearance Signer Test',
        'mode' => EnvelopeMode::Ordered->value,
        'signers' => [
            ['signer_id' => $cpl->id, 'sign_order' => 1],
        ],
    ])->assertSessionHasErrors('signers');

    // 2. Valid Secret envelope between wgCdr and AVM
    $this->post("/documents/{$secretDoc->id}/envelopes", [
        'title' => 'Secret Patrol Order Execution',
        'mode' => EnvelopeMode::Ordered->value,
        'signers' => [
            ['signer_id' => $wgCdr->id, 'sign_order' => 1],
            ['signer_id' => $avm->id, 'sign_order' => 2],
        ],
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    /** @var SignatureEnvelope $secretEnv */
    $secretEnv = $dbContext->runAs($wgCdr, static fn () => SignatureEnvelope::query()->where('document_id', $secretDoc->id)->latest()->firstOrFail());
    $firstReq = $dbContext->runAs($wgCdr, static fn () => $secretEnv->requests()->orderBy('sign_order')->firstOrFail());

    // Reassigning to under-cleared Corporal is blocked cleanly
    $this->post("/signatures/requests/{$firstReq->id}/reassign", [
        'new_signer_id' => $cpl->id,
    ])->assertSessionHasErrors('new_signer_id');

    // Sign both & seal
    $this->signIn($wgCdr, mfaVerified: true, stepUpFresh: true);
    $this->post("/signatures/requests/{$firstReq->id}/sign", ['reason' => 'Wing Commander order'])->assertRedirect();
    $secondReq = $dbContext->runAs($avm, static fn () => $secretEnv->requests()->orderByDesc('sign_order')->firstOrFail());
    $this->signIn($avm, mfaVerified: true, stepUpFresh: true);
    $this->post("/signatures/requests/{$secondReq->id}/sign", ['reason' => 'AVM authorization'])->assertRedirect();

    // 3. Corporal gets 404 on certificate download & sees REDACTED title on QR /verify/{token}
    $this->signIn($cpl);
    $this->get("/signatures/envelopes/{$secretEnv->id}/certificate")->assertNotFound();

    $this->get("/verify/{$secretEnv->verification_token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('document.metadata_hidden', true)
            ->where('document.doc_number', '[REDACTED]')
        );

    // 4. Cleared AVM sees full document number & title on /verify/{token}
    $this->signIn($avm);
    $this->get("/verify/{$secretEnv->verification_token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('document.metadata_hidden', false)
            ->where('document.doc_number', 'AFZ/OPS/2026/088')
        );
});
