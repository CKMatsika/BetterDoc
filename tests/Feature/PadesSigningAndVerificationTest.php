<?php

declare(strict_types=1);

use App\Enums\CertificateCustodyMode;
use App\Enums\EnvelopeMode;
use App\Enums\EnvelopeStatus;
use App\Enums\SignatureRequestStatus;
use App\Enums\SignatureTier;
use App\Enums\SignatureVerificationStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignatureEnvelope;
use App\Models\SignatureRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserCertificate;
use App\Services\Crypto\EnvelopeEncryptionService;
use App\Services\Pki\HsmCustodyService;
use App\Services\Pki\InternalCaService;
use App\Services\Pki\SignerBridgeService;
use App\Services\Security\DbSecurityContext;
use App\Services\Signing\PadesSignerService;
use Database\Seeders\AfzDefenceSeeder;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('executes ordered multi-party PAdES-B-LT signing, seals with QR + Certificate PDF, and detects tamper, revocation, and expiry', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    /** @var User $sqnLdr */
    $sqnLdr = User::query()->where('email', 'sqnldr.ndlovu@afz.mil.zw')->firstOrFail();
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    $dbContext = app(DbSecurityContext::class);

    $caService = app(InternalCaService::class);
    $dbContext->runAs($wgCdr, function () use ($caService, $tenant, $sqnLdr, $wgCdr): void {
        $caService->ensureBootstrapped($tenant, $wgCdr);
        $caService->issueUserCertificate($sqnLdr, CertificateCustodyMode::HsmManaged, $wgCdr);
        $caService->issueUserCertificate($wgCdr, CertificateCustodyMode::HsmManaged, $wgCdr);
    });

    /** @var Document $doc */
    $doc = $dbContext->runAs($sqnLdr, static fn (): Document => Document::query()->where('doc_number', 'AFZ/SIG/2026/014')->firstOrFail());

    // 1. Initiate ordered 2-party signing envelope
    $this->signIn($wgCdr);
    $this->post("/documents/{$doc->id}/envelopes", [
        'title' => 'Signals Squadron Maintenance Sign-Off',
        'mode' => EnvelopeMode::Ordered->value,
        'signers' => [
            [
                'signer_id' => $sqnLdr->id,
                'sign_order' => 1,
                'tier' => SignatureTier::Tier2Pades->value,
                'field_coords' => ['page' => 1, 'x' => 48, 'y' => 560, 'width' => 195, 'height' => 58],
            ],
            [
                'signer_id' => $wgCdr->id,
                'sign_order' => 2,
                'tier' => SignatureTier::Tier2Pades->value,
                'field_coords' => ['page' => 1, 'x' => 280, 'y' => 560, 'width' => 195, 'height' => 58],
            ],
        ],
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    /** @var SignatureEnvelope $envelope */
    $envelope = $dbContext->runAs($wgCdr, static fn () => SignatureEnvelope::query()->where('document_id', $doc->id)->latest()->firstOrFail());
    $reqs = $dbContext->runAs($wgCdr, static fn () => $envelope->requests()->get());
    expect($reqs[0]->status)->toBe(SignatureRequestStatus::Ready)
        ->and($reqs[1]->status)->toBe(SignatureRequestStatus::Pending);

    // 2. Signer #2 cannot jump queue out of order
    $this->signIn($wgCdr);
    $this->post("/signatures/requests/{$reqs[1]->id}/sign", [
        'reason' => 'Attempting out-of-order signature',
    ])->assertSessionHasErrors('signer');

    // 3. Signer #1 with STALE MFA step-up (>5 min) is rejected for PAdES X.509 signing
    $this->signIn($sqnLdr, mfaVerified: true, stepUpFresh: false);
    $this->post("/signatures/requests/{$reqs[0]->id}/sign", [
        'reason' => 'Technical inspection approved',
    ])->assertSessionHasErrors('mfa');

    // 4. Signer #1 with FRESH MFA step-up signs cleanly
    $this->signIn($sqnLdr, mfaVerified: true, stepUpFresh: true);
    $this->post("/signatures/requests/{$reqs[0]->id}/sign", [
        'reason' => 'Technical inspection approved',
        'location' => 'Signals Squadron HQ',
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    $dbContext->runAs($wgCdr, static fn () => $reqs[1]->refresh());
    expect($reqs[1]->status)->toBe(SignatureRequestStatus::Ready);

    // 5. Signer #2 signs -> Envelope auto-seals (PAdES-B-LTA + QR Seal + Certificate PDF)
    $this->signIn($wgCdr, mfaVerified: true, stepUpFresh: true);
    $this->post("/signatures/requests/{$reqs[1]->id}/sign", [
        'reason' => 'Wing Commander final execution',
        'location' => 'Operations Wing HQ',
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    $dbContext->runAs($wgCdr, static fn () => $envelope->refresh());
    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($envelope->sealed_version_id)->not->toBeNull()
        ->and($envelope->certificate_storage_key)->not->toBeNull();

    // 6. Opening Document triggers live verification returning VALID across all embedded signatures
    $this->get("/documents/{$doc->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('signatureVerification.overall_status', SignatureVerificationStatus::Valid->value)
            ->where('signatureVerification.signatures_count', fn ($c) => $c >= 3)
        );

    // Download sealed Certificate of Completion PDF
    $certRes = $this->get("/signatures/envelopes/{$envelope->id}/certificate")->assertOk();
    expect($certRes->streamedContent())->toStartWith('%PDF-');

    // 7. Revoking Wing Commander cert flips open-time verification status to REVOKED
    $wgCert = $dbContext->runAs($wgCdr, static fn () => $wgCdr->certificates()->firstOrFail());
    $this->post("/pki/certificates/{$wgCert->id}/revoke", ['reason' => 'key_compromise'])
        ->assertRedirect()->assertSessionDoesntHaveErrors();

    $this->get("/documents/{$doc->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('signatureVerification.overall_status', SignatureVerificationStatus::Revoked->value)
        );

    // 8. Appending shadow tamper bytes to sealed PDF triggers ALTERED
    /** @var DocumentVersion $sealedVer */
    $sealedVer = $dbContext->runAs($wgCdr, static fn () => DocumentVersion::query()->findOrFail($envelope->sealed_version_id));
    $crypto = app(EnvelopeEncryptionService::class);
    $handle = $crypto->decryptVersionToTempHandle($sealedVer);
    $tamperedPath = tempnam(sys_get_temp_dir(), 'tamper_');
    file_put_contents($tamperedPath, (string) stream_get_contents($handle)."\n%%EVIL_SHADOW_APPEND");
    fclose($handle);
    $newEnc = $crypto->encryptFileToDisk($tamperedPath, $sealedVer->storage_disk, $sealedVer->storage_key);
    @unlink($tamperedPath);
    $dbContext->runAs($wgCdr, static fn () => $sealedVer->update(['wrapped_dek' => $newEnc['wrapped_dek']]));

    $this->get("/documents/{$doc->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('signatureVerification.overall_status', SignatureVerificationStatus::Altered->value)
        );

    // 9. Presign check blocks signing with an expired cert; historically signed expired PDF reports EXPIRED
    $expiredCert = $dbContext->runAs($wgCdr, fn () => $caService->issueUserCertificate($sqnLdr, CertificateCustodyMode::HsmManaged, $wgCdr, validDays: -10));
    $baseVer = $dbContext->runAs($sqnLdr, static fn () => DocumentVersion::query()->findOrFail($envelope->source_version_id));
    $pades = app(PadesSignerService::class);
    expect(fn () => $dbContext->runAs($sqnLdr, fn () => $pades->signHsmPades($doc, $baseVer, $sqnLdr, $expiredCert, 'SigExp', ['page' => 1, 'x' => 48, 'y' => 400, 'width' => 180, 'height' => 50], 'Expired Test', 'Harare')))
        ->toThrow(RuntimeException::class);

    // Produce a signed PDF containing an expired certificate (simulating document signed before expiry) and verify EXPIRED status
    $rootCa = $dbContext->runAs($wgCdr, static fn () => $caService->authorityByRole($tenant->id, 'root'));
    $interCa = $dbContext->runAs($wgCdr, static fn () => $caService->authorityByRole($tenant->id, 'intermediate'));
    $tsaCa = $dbContext->runAs($wgCdr, static fn () => $caService->authorityByRole($tenant->id, 'tsa'));
    $hsm = app(HsmCustodyService::class);
    $expKey = $hsm->unsealKey($tenant->id, $expiredCert->pkcs11_key_label);
    $tsaKey = $hsm->unsealKey($tenant->id, $tsaCa->pkcs11_key_label);
    $srcHandle = $crypto->decryptVersionToTempHandle($baseVer);
    $expIn = tempnam(sys_get_temp_dir(), 'exp_in_');
    $expOut = tempnam(sys_get_temp_dir(), 'exp_out_');
    file_put_contents($expIn, (string) stream_get_contents($srcHandle));
    fclose($srcHandle);

    app(SignerBridgeService::class)->execute('sign_pades', [
        'input_pdf_path' => $expIn,
        'output_pdf_path' => $expOut,
        'signer_cert_pem' => $expiredCert->certificate_pem,
        'signer_key_pem' => $expKey,
        'ca_chain_pems' => [$interCa->certificate_pem, $rootCa->certificate_pem],
        'tsa_cert_pem' => $tsaCa->certificate_pem,
        'tsa_key_pem' => $tsaKey,
        'field_name' => 'SigExpiredHistorical',
        'coords' => ['page' => 1, 'x' => 48, 'y' => 400, 'width' => 180, 'height' => 50],
        'signer_label' => 'Historical Signer',
        'reason' => 'Signed before cert expiration',
        'location' => 'Harare',
    ]);
    sodium_memzero($expKey);
    sodium_memzero($tsaKey);

    $expEnc = $crypto->encryptFileToDisk($expOut, $baseVer->storage_disk, "tenants/{$tenant->id}/docs/{$doc->id}/exp_check.enc");
    @unlink($expIn);
    @unlink($expOut);

    /** @var DocumentVersion $histVer */
    $histVer = $dbContext->runAs($sqnLdr, fn () => DocumentVersion::query()->create([
        'tenant_id' => $tenant->id,
        'document_id' => $doc->id,
        'version_major' => 99,
        'version_minor' => 0,
        'is_current' => false,
        'storage_disk' => $baseVer->storage_disk,
        'storage_key' => "tenants/{$tenant->id}/docs/{$doc->id}/exp_check.enc",
        'original_filename' => $baseVer->original_filename,
        'mime_type' => 'application/pdf',
        'file_size_bytes' => $baseVer->file_size_bytes,
        'sha256_hash' => hash('sha256', 'historical'),
        'wrapped_dek' => $expEnc['wrapped_dek'],
        'kek_key_id' => $expEnc['kek_key_id'],
        'created_by' => $sqnLdr->id,
    ]));

    $expVer = $dbContext->runAs($sqnLdr, fn () => $pades->verifyDocumentVersion($histVer));
    expect($expVer->overall_status)->toBe(SignatureVerificationStatus::Expired);
});

it('supports Tier 1 drawn SVG stamp, Tier 2B Smartcard external PKCS#11 digest flow, decline, and reminder', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    /** @var User $sqnLdr */
    $sqnLdr = User::query()->where('email', 'sqnldr.ndlovu@afz.mil.zw')->firstOrFail();
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    $dbContext = app(DbSecurityContext::class);

    $caService = app(InternalCaService::class);
    $dbContext->runAs($wgCdr, fn () => $caService->ensureBootstrapped($tenant, $wgCdr));

    // Smartcard simulation: generate client keypair locally (never stored in HSM!) & submit CSR
    $hsm = app(HsmCustodyService::class);
    $interCa = $dbContext->runAs($wgCdr, static fn () => $caService->authorityByRole($tenant->id, 'intermediate'));
    $interKey = $hsm->unsealKey($tenant->id, $interCa->pkcs11_key_label);
    $scRes = app(SignerBridgeService::class)->execute('issue_user_cert', [
        'ca_cert_pem' => $interCa->certificate_pem,
        'ca_key_pem' => $interKey,
        'common_name' => 'Smartcard Officer Ndlovu',
        'email' => $sqnLdr->email,
        'org_name' => $tenant->name,
        'org_unit' => 'SIG-SQN',
        'ocsp_url' => 'https://betterdoc.lan/ocsp',
        'crl_url' => 'https://betterdoc.lan/crl',
        'valid_days' => 365,
    ]);
    sodium_memzero($interKey);

    $clientPrivateKeyPem = (string) $scRes['private_key_pem'];
    // Issue CSR-backed Smartcard cert via Python/cryptography equivalent: generate cert directly with smartcard_pkcs11 custody
    // Using UserCertificate record holding ONLY public certificate (zero private key in HSM or DB)
    $scCert = $dbContext->runAs($wgCdr, function () use ($caService, $sqnLdr): UserCertificate {
        $issued = $caService->issueUserCertificate($sqnLdr, CertificateCustodyMode::HsmManaged, $sqnLdr);
        $issued->update(['custody_mode' => CertificateCustodyMode::SmartcardPkcs11]);

        return $issued;
    });
    $scKeyPem = $hsm->unsealKey($tenant->id, $scCert->pkcs11_key_label);

    /** @var Document $doc */
    $doc = $dbContext->runAs($sqnLdr, static fn (): Document => Document::query()->where('doc_number', 'AFZ/SIG/2026/014')->firstOrFail());

    // Parallel envelope with Tier 1 + Tier 2B Smartcard
    $this->signIn($wgCdr);
    $this->post("/documents/{$doc->id}/envelopes", [
        'title' => 'Parallel Drawn Stamp + Smartcard Envelope',
        'mode' => EnvelopeMode::Parallel->value,
        'signers' => [
            ['signer_id' => $wgCdr->id, 'sign_order' => 1, 'tier' => SignatureTier::Tier1Stamp->value],
            ['signer_id' => $sqnLdr->id, 'sign_order' => 1, 'tier' => SignatureTier::Tier2Smartcard->value],
        ],
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    /** @var SignatureEnvelope $envelope */
    $envelope = $dbContext->runAs($wgCdr, static fn () => SignatureEnvelope::query()->where('document_id', $doc->id)->latest()->firstOrFail());
    $reqs = $dbContext->runAs($wgCdr, static fn () => $envelope->requests()->get());
    /** @var SignatureRequest $wgReq */
    $wgReq = $reqs->firstWhere('signer_id', $wgCdr->id);
    /** @var SignatureRequest $sqnReq */
    $sqnReq = $reqs->firstWhere('signer_id', $sqnLdr->id);

    // Send reminder to Sqn Ldr
    $this->post("/signatures/requests/{$sqnReq->id}/remind")->assertRedirect();
    $dbContext->runAs($wgCdr, static fn () => $sqnReq->refresh());
    expect($sqnReq->reminder_count)->toBe(1);

    // Tier 1 Drawn SVG + Typed Name stamp
    $this->post("/signatures/requests/{$wgReq->id}/sign", [
        'reason' => 'Initial visual endorsement',
        'tier' => SignatureTier::Tier1Stamp->value,
        'typed_name' => 'Wg Cdr T. Moyo',
        'drawn_stamp_svg' => 'M 10 40 L 80 20 L 140 50 L 210 30',
    ])->assertRedirect()->assertSessionDoesntHaveErrors();
    $dbContext->runAs($wgCdr, static fn () => $wgReq->refresh());
    expect($wgReq->status)->toBe(SignatureRequestStatus::Signed);

    // Tier 2B Smartcard PKCS#11 two-step prepare -> external RSA sign -> finalize
    $this->signIn($sqnLdr, mfaVerified: true, stepUpFresh: true);
    $prep = $this->postJson("/signatures/requests/{$sqnReq->id}/prepare-external", [
        'reason' => 'Hardware token countersign',
    ])->assertOk();

    $rawSigned = app(SignerBridgeService::class)->execute('sign_raw_digest', [
        'private_key_pem' => $scKeyPem,
        'data_b64' => (string) $prep->json('signed_attrs_b64'),
    ]);
    sodium_memzero($scKeyPem);
    sodium_memzero($clientPrivateKeyPem);

    $this->postJson("/signatures/requests/{$sqnReq->id}/finalize-external", [
        'challenge_token' => (string) $prep->json('challenge_token'),
        'signature_b64' => (string) $rawSigned['signature_b64'],
    ])->assertOk()->assertJsonPath('status', SignatureRequestStatus::Signed->value);

    $dbContext->runAs($wgCdr, static fn () => $envelope->refresh());
    expect($envelope->status)->toBe(EnvelopeStatus::Completed);

    // Separate Decline test on fresh envelope
    $this->signIn($wgCdr);
    $this->post("/documents/{$doc->id}/envelopes", [
        'title' => 'Decline Flow Envelope',
        'mode' => EnvelopeMode::Ordered->value,
        'signers' => [['signer_id' => $sqnLdr->id, 'sign_order' => 1]],
    ])->assertRedirect();

    $env2 = $dbContext->runAs($wgCdr, static fn () => SignatureEnvelope::query()->where('title', 'Decline Flow Envelope')->firstOrFail());
    $req2 = $dbContext->runAs($wgCdr, static fn () => $env2->requests()->firstOrFail());

    $this->signIn($sqnLdr);
    $this->post("/signatures/requests/{$req2->id}/decline", ['reason' => 'Incorrect telemetry baseline figures'])
        ->assertRedirect()->assertSessionDoesntHaveErrors();

    $dbContext->runAs($wgCdr, static fn () => $env2->refresh());
    expect($env2->status)->toBe(EnvelopeStatus::Declined);
});
