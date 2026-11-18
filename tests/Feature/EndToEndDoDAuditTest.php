<?php

declare(strict_types=1);

use App\Enums\CdrStatus;
use App\Enums\CertificateCustodyMode;
use App\Enums\EnvelopeMode;
use App\Enums\PrincipalType;
use App\Enums\SignatureTier;
use App\Exceptions\AirGapViolationException;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\CostCentre;
use App\Models\DocumentVersion;
use App\Models\Printer;
use App\Models\SignatureEnvelope;
use App\Models\SignatureRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Pki\InternalCaService;
use App\Services\Search\DocumentSearchService;
use App\Services\Security\BetterDocHttpClientGuard;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;
use GuzzleHttp\Psr7\Request as PsrRequest;

it('executes full air-gapped Definition-of-Done audit: upload -> OCR/CDR -> search -> share -> route -> PAdES sign -> offline verify -> secure print -> AI RAG -> audit chain', function (): void {
    $this->seed([AfzDefenceSeeder::class]);

    // 0. Unplug internet enforcement: external hosts strictly rejected
    config()->set('betterdoc.air_gapped', true);
    $guard = app(BetterDocHttpClientGuard::class);
    expect(static fn () => $guard->assertOutboundAllowed(new PsrRequest('POST', 'https://api.openai.com/v1/chat/completions')))
        ->toThrow(AirGapViolationException::class);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    /** @var User $sqnLdr */
    $sqnLdr = User::query()->where('email', 'sqnldr.ndlovu@afz.mil.zw')->firstOrFail();
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail();

    $dbContext = app(DbSecurityContext::class);

    // Bootstrap internal PKI & issue signing certificates
    $ca = app(InternalCaService::class);
    $dbContext->runAs($wgCdr, static function () use ($ca, $tenant, $wgCdr, $sqnLdr): void {
        $ca->ensureBootstrapped($tenant, $wgCdr);
        $ca->issueUserCertificate($wgCdr, CertificateCustodyMode::HsmManaged, $wgCdr);
        $ca->issueUserCertificate($sqnLdr, CertificateCustodyMode::HsmManaged, $wgCdr);
    });

    [$cabinet, $levelL2, $printerOps, $costCentre, $workflowDef] = $dbContext->runAs($wgCdr, static function () use ($tenant): array {
        return [
            Cabinet::query()->where('tenant_id', $tenant->id)->where('name', 'Operational Signals Registry')->firstOrFail(),
            ClearanceLevel::query()->where('tenant_id', $tenant->id)->where('rank', 2)->firstOrFail(),
            Printer::query()->where('tenant_id', $tenant->id)->where('cups_queue_name', 'afz-ops-secret')->firstOrFail(),
            CostCentre::query()->where('tenant_id', $tenant->id)->where('code', 'CC-HQ-OPS')->firstOrFail(),
            WorkflowDefinition::query()->where('tenant_id', $tenant->id)->firstOrFail(),
        ];
    });

    // Build real valid PDF with searchable token
    $stream = "BT\n/F1 12 Tf\n72 720 Td\n(Air-Gap DoD Exercise Brief TokenDodOmega77) Tj\nET\n";
    $objs = [
        "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
        "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
        "4 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n{$stream}endstream\nendobj\n",
        "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
    ];
    $pdfBytes = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objs as $obj) {
        $offsets[] = strlen($pdfBytes);
        $pdfBytes .= $obj;
    }
    $xref = strlen($pdfBytes);
    $pdfBytes .= "xref\n0 6\n0000000000 65535 f \n";
    for ($i = 1; $i <= 5; $i++) {
        $pdfBytes .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdfBytes .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

    // 1. Resumable Chunked Upload + AV/CDR/OCR/Encryption
    $this->signIn($wgCdr);
    $initRes = $this->postJson('/uploads/init', [
        'filename' => 'dod-exercise.pdf',
        'mime_type' => 'application/pdf',
        'total_size' => strlen($pdfBytes),
        'sha256_expected' => hash('sha256', $pdfBytes),
    ])->assertCreated();
    $sessionId = (string) $initRes->json('session_id');

    $this->putChunk("/uploads/{$sessionId}/chunks/0", $pdfBytes, hash('sha256', $pdfBytes))->assertOk();

    $completeRes = $this->postJson("/uploads/{$sessionId}/complete", [
        'cabinet_id' => $cabinet->id,
        'classification_level_id' => $levelL2->id,
        'doc_number' => 'AFZ/DOD/2026/900',
        'title' => 'Air-Gapped DoD Verification Directive',
        'summary' => 'Verified offline end-to-end compliance directive.',
    ])->assertCreated();

    $docId = (string) $completeRes->json('document_id');
    expect($completeRes->json('av_status'))->toBe('clean')
        ->and($completeRes->json('cdr_status'))->toBe(CdrStatus::Sanitized->value);

    // 2. Permission-Safe Full-Text Search (wgCdr finds; cpl L1 is blocked with 0 hits & 404)
    $hitsCleared = $dbContext->runAs($wgCdr, static fn (): array => app(DocumentSearchService::class)->search($wgCdr, 'TokenDodOmega77'));
    expect($hitsCleared['total'])->toBeGreaterThanOrEqual(1);

    $this->signIn($cpl);
    $this->get("/documents/{$docId}")->assertNotFound();
    $hitsUnclear = $dbContext->runAs($cpl, static fn (): array => app(DocumentSearchService::class)->search($cpl, 'TokenDodOmega77'));
    expect($hitsUnclear['total'])->toBe(0);

    // 3. Share explicitly with sqnLdr (L2) including sign + print permissions
    $this->signIn($wgCdr);
    $this->post("/documents/{$docId}/share", [
        'principal_type' => PrincipalType::User->value,
        'principal_id' => $sqnLdr->id,
        'perm_view' => true,
        'perm_download' => true,
        'perm_print' => true,
        'perm_sign' => true,
    ])->assertRedirect();

    // 4. Route through BPMN Workflow
    $this->post("/documents/{$docId}/workflows/start", [
        'definition_id' => $workflowDef->id,
    ])->assertRedirect();

    // 5. Initiate Signing Envelope + PAdES Sign + Offline QR Verification
    $this->post("/documents/{$docId}/envelopes", [
        'title' => 'DoD Final Sign-Off',
        'mode' => EnvelopeMode::Ordered->value,
        'signers' => [
            ['signer_id' => $sqnLdr->id, 'sign_order' => 1, 'tier' => SignatureTier::Tier2Pades->value],
        ],
    ])->assertRedirect();

    /** @var SignatureRequest $sigReq */
    $sigReq = $dbContext->runAs($wgCdr, static function () use ($docId): SignatureRequest {
        $env = SignatureEnvelope::query()->where('document_id', $docId)->latest()->firstOrFail();

        return $env->requests()->firstOrFail();
    });

    $this->signIn($sqnLdr, mfaVerified: true, stepUpFresh: true);
    $this->post("/signatures/requests/{$sigReq->id}/sign", [
        'reason' => 'Approved under unplugged air-gap drill',
        'location' => 'Harare HQ Air-Gap LAN',
    ])->assertRedirect();

    /** @var SignatureEnvelope $sealedEnv */
    $sealedEnv = $dbContext->runAs($sqnLdr, static fn (): SignatureEnvelope => SignatureEnvelope::query()->where('document_id', $docId)->latest()->firstOrFail());
    /** @var DocumentVersion $signedVer */
    $signedVer = $dbContext->runAs($sqnLdr, static fn (): DocumentVersion => DocumentVersion::query()->findOrFail($sealedEnv->sealed_version_id));

    // Offline LAN / QR Verification Page loads cleanly with 200
    $token = (string) $sealedEnv->verification_token;
    $this->get("/verify/{$token}")->assertOk();
    $this->post("/documents/{$docId}/versions/{$signedVer->id}/verify")->assertRedirect();

    // 6. Queue Watermarked PIN Secure Print Job
    $this->postJson("/documents/{$docId}/print", [
        'printer_id' => $printerOps->id,
        'cost_centre_id' => $costCentre->id,
        'copies' => 1,
    ])->assertCreated();

    // 7. Local Air-Gapped Hybrid RAG Query (sqnLdr sees cleared L2 citation; cpl sees zero L2 citations)
    $aiCleared = $this->postJson('/ai/chat', ['query' => 'What does TokenDodOmega77 specify?'])->assertOk();
    expect($aiCleared->json('message.content'))->toBeString();

    $this->signIn($cpl);
    $aiUnclear = $this->postJson('/ai/chat', ['query' => 'What does TokenDodOmega77 specify?'])->assertOk();
    expect($aiUnclear->json('message.cited_doc_ids') ?? [])->not->toContain($docId);

    // 8. Final Audit Hash Chain & Checkpoint Integrity Verification
    $audit = app(ImmutableAuditLogger::class);
    expect($audit->verifyChain($tenant)['valid'])->toBeTrue();
});
