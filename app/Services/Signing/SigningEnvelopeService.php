<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Enums\CertificateStatus;
use App\Enums\EnvelopeMode;
use App\Enums\EnvelopeStatus;
use App\Enums\PrincipalType;
use App\Enums\SignatureRequestStatus;
use App\Enums\SignatureTier;
use App\Exceptions\InsufficientClearanceException;
use App\Models\Document;
use App\Models\DocumentAclGrant;
use App\Models\DocumentVersion;
use App\Models\SignatureEnvelope;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Models\UserCertificate;
use App\Models\UserSession;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Pki\InternalCaService;
use App\Services\Security\ClearancePolicyEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SigningEnvelopeService
{
    public function __construct(
        private readonly ClearancePolicyEngine $policyEngine,
        private readonly InternalCaService $ca,
        private readonly PadesSignerService $signerService,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * Create a single-party or multi-party (ordered or parallel) signing envelope.
     *
     * @param  list<array{signer_id: string, sign_order?: int, tier?: string, field_coords?: array{page?: int, x?: float|int, y?: float|int, width?: float|int, height?: float|int}}>  $signers
     */
    public function createEnvelope(
        Document $document,
        User $creator,
        string $title,
        EnvelopeMode $mode,
        array $signers,
        ?string $message = null,
        ?Request $request = null,
    ): SignatureEnvelope {
        $sourceVersion = $document->currentVersion;
        if (! $sourceVersion instanceof DocumentVersion || $sourceVersion->mime_type !== 'application/pdf') {
            throw ValidationException::withMessages([
                'envelope' => 'Signing envelopes require a PDF current document version.',
            ]);
        }

        if ($signers === []) {
            throw ValidationException::withMessages(['signers' => 'At least one cleared signer is required.']);
        }

        // Validate every signer meets MAC clearance + compartments before persisting
        foreach ($signers as $entry) {
            $signerId = $entry['signer_id'];
            try {
                $this->policyEngine->assertCanShareToRecipient(
                    grantor: $creator,
                    document: $document,
                    principalType: PrincipalType::User,
                    principalId: $signerId,
                    requestedPerms: ['perm_view' => true, 'perm_sign' => true],
                    request: $request,
                );
            } catch (InsufficientClearanceException $e) {
                throw ValidationException::withMessages(['signers' => $e->getMessage()]);
            }
        }

        return DB::transaction(function () use ($document, $sourceVersion, $creator, $title, $mode, $signers, $message, $request): SignatureEnvelope {
            $envelopeId = (string) Str::uuid7();

            /** @var SignatureEnvelope $envelope */
            $envelope = SignatureEnvelope::query()->create([
                'id' => $envelopeId,
                'tenant_id' => $document->tenant_id,
                'document_id' => $document->id,
                'source_version_id' => $sourceVersion->id,
                'working_version_id' => $sourceVersion->id,
                'created_by' => $creator->id,
                'mode' => $mode,
                'status' => EnvelopeStatus::InProgress,
                'title' => $title,
                'message' => $message,
                'verification_token' => bin2hex(random_bytes(16)),
                'expires_at' => now()->addDays(14),
            ]);

            usort($signers, static fn (array $a, array $b): int => (int) ($a['sign_order'] ?? 1) <=> (int) ($b['sign_order'] ?? 1));
            $firstOrder = (int) ($signers[0]['sign_order'] ?? 1);

            foreach ($signers as $idx => $entry) {
                $order = (int) ($entry['sign_order'] ?? ($idx + 1));
                $tier = SignatureTier::tryFrom((string) ($entry['tier'] ?? SignatureTier::Tier2Pades->value)) ?? SignatureTier::Tier2Pades;
                $rawCoords = $entry['field_coords'] ?? [];
                $coords = [
                    'page' => max(1, (int) ($rawCoords['page'] ?? 1)),
                    'x' => (float) ($rawCoords['x'] ?? (48 + ($idx % 2) * 230)),
                    'y' => (float) ($rawCoords['y'] ?? (560 + intdiv($idx, 2) * 74)),
                    'width' => (float) ($rawCoords['width'] ?? 195),
                    'height' => (float) ($rawCoords['height'] ?? 58),
                ];

                $isReady = $mode === EnvelopeMode::Parallel || $order === $firstOrder;

                SignatureRequest::query()->create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $document->tenant_id,
                    'envelope_id' => $envelope->id,
                    'signer_id' => $entry['signer_id'],
                    'sign_order' => $order,
                    'tier' => $tier,
                    'status' => $isReady ? SignatureRequestStatus::Ready : SignatureRequestStatus::Pending,
                    'field_name' => 'SentinelSig_'.($idx + 1).'_'.substr(str_replace('-', '', $envelope->id), 0, 6),
                    'field_coords_json' => $coords,
                ]);

                // Ensure signer holds explicit ACL view+sign access
                DocumentAclGrant::query()->firstOrCreate(
                    [
                        'tenant_id' => $document->tenant_id,
                        'document_id' => $document->id,
                        'principal_type' => PrincipalType::User,
                        'principal_id' => $entry['signer_id'],
                    ],
                    [
                        'perm_view' => true,
                        'perm_sign' => true,
                        'granted_by' => $creator->id,
                    ]
                );
            }

            $this->audit->log(
                tenantId: $document->tenant_id,
                eventType: 'signature.envelope_created',
                resourceType: 'signature_envelope',
                resourceId: $envelope->id,
                actor: $creator,
                classificationRank: $document->classification_rank,
                after: ['document_id' => $document->id, 'mode' => $mode->value, 'signers_count' => count($signers)],
                request: $request,
            );

            return $envelope->load('requests.signer');
        });
    }

    public function signRequest(
        SignatureRequest $sigRequest,
        User $actor,
        ?UserSession $session,
        string $reason,
        string $location = 'SENTINEL LAN',
        ?SignatureTier $overrideTier = null,
        ?string $typedName = null,
        ?string $drawnSvgPath = null,
        ?Request $request = null,
    ): SignatureRequest {
        $envelope = $sigRequest->envelope()->with(['document', 'workingVersion', 'requests'])->firstOrFail();
        $document = $envelope->document;
        if (! $document instanceof Document) {
            throw new RuntimeException('Envelope missing parent document.');
        }

        $this->assertSignerTurnReady($sigRequest, $envelope, $actor);

        $tier = $overrideTier ?? $sigRequest->tier;
        $workingVersion = $envelope->workingVersion ?? $document->currentVersion;
        if (! $workingVersion instanceof DocumentVersion) {
            throw new RuntimeException('No PDF version available to sign.');
        }

        $certSerial = null;
        $userCertId = null;
        $tsaSerial = null;
        $tsaTs = null;

        if ($tier === SignatureTier::Tier2Pades) {
            $this->assertFreshMfaStepUp($session);
            $userCert = $this->resolveActiveSigningCertificate($actor);
            $padesRes = $this->signerService->signHsmPades(
                document: $document,
                sourceVersion: $workingVersion,
                signer: $actor,
                userCert: $userCert,
                fieldName: $sigRequest->field_name,
                coords: $sigRequest->field_coords_json,
                reason: $reason,
                location: $location,
                useLta: false,
            );
            $newVersion = $padesRes['version'];
            $certSerial = $padesRes['cert_serial'];
            $userCertId = $userCert->id;
            $tsaSerial = $padesRes['tsa_token_serial'];
            $tsaTs = $padesRes['tsa_timestamp'];
        } elseif ($tier === SignatureTier::Tier1Stamp) {
            $newVersion = $this->signerService->applyTier1Stamp(
                document: $document,
                sourceVersion: $workingVersion,
                signer: $actor,
                coords: $sigRequest->field_coords_json,
                reason: $reason,
                typedName: $typedName,
                drawnSvgPath: $drawnSvgPath,
            );
        } elseif ($tier === SignatureTier::Tier3Workflow) {
            $userCert = UserCertificate::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('user_id', $actor->id)
                ->where('status', CertificateStatus::Active->value)
                ->latest()
                ->first();

            if ($userCert !== null) {
                $padesRes = $this->signerService->signHsmPades(
                    document: $document,
                    sourceVersion: $workingVersion,
                    signer: $actor,
                    userCert: $userCert,
                    fieldName: $sigRequest->field_name,
                    coords: $sigRequest->field_coords_json,
                    reason: "Workflow Approval: {$reason}",
                    location: $location,
                );
                $newVersion = $padesRes['version'];
                $certSerial = $padesRes['cert_serial'];
                $userCertId = $userCert->id;
                $tsaSerial = $padesRes['tsa_token_serial'];
                $tsaTs = $padesRes['tsa_timestamp'];
            } else {
                $newVersion = $this->signerService->applyTier1Stamp(
                    document: $document,
                    sourceVersion: $workingVersion,
                    signer: $actor,
                    coords: $sigRequest->field_coords_json,
                    reason: "Workflow Approval: {$reason}",
                    typedName: trim(($actor->rank_title ? $actor->rank_title.' ' : '').$actor->name),
                );
            }
        } else {
            throw ValidationException::withMessages([
                'tier' => 'Hardware Smartcard (Tier 2B) signatures must use the prepare-external/finalize-external digest flow.',
            ]);
        }

        return $this->commitSignedOutcome(
            sigRequest: $sigRequest,
            envelope: $envelope,
            document: $document,
            actor: $actor,
            tier: $tier,
            newVersion: $newVersion,
            reason: $reason,
            location: $location,
            userCertId: $userCertId,
            certSerial: $certSerial,
            tsaSerial: $tsaSerial,
            tsaTs: $tsaTs,
            typedName: $typedName,
            drawnSvgPath: $drawnSvgPath,
            request: $request,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function prepareSmartcardSigning(
        SignatureRequest $sigRequest,
        User $actor,
        ?UserSession $session,
        string $reason,
        string $location = 'Workstation Smartcard',
    ): array {
        $this->assertFreshMfaStepUp($session);

        $envelope = $sigRequest->envelope()->with(['document', 'workingVersion'])->firstOrFail();
        $document = $envelope->document;
        if (! $document instanceof Document) {
            throw new RuntimeException('Envelope missing document.');
        }
        $this->assertSignerTurnReady($sigRequest, $envelope, $actor);

        $workingVersion = $envelope->workingVersion ?? $document->currentVersion;
        if (! $workingVersion instanceof DocumentVersion) {
            throw new RuntimeException('No PDF version available.');
        }

        $userCert = $this->resolveActiveSigningCertificate($actor, preferSmartcard: true);

        $state = $this->signerService->prepareSmartcardDigest(
            document: $document,
            sourceVersion: $workingVersion,
            signer: $actor,
            userCert: $userCert,
            fieldName: $sigRequest->field_name,
            coords: $sigRequest->field_coords_json,
            reason: $reason,
            location: $location,
        );

        $token = (string) Str::uuid7();
        cache()->put("sentinel:ext_sig:{$sigRequest->id}:{$token}", [
            'state' => $state,
            'working_version_id' => $workingVersion->id,
            'user_certificate_id' => $userCert->id,
            'reason' => $reason,
            'location' => $location,
            'actor_id' => $actor->id,
        ], now()->addMinutes(5));

        return [
            'challenge_token' => $token,
            'signed_attrs_b64' => $state['signed_attrs_b64'],
            'signed_attrs_sha256' => $state['signed_attrs_sha256'],
            'cert_serial' => $userCert->serial_number,
        ];
    }

    public function finalizeSmartcardSigning(
        SignatureRequest $sigRequest,
        User $actor,
        string $challengeToken,
        string $signatureB64,
        ?Request $request = null,
    ): SignatureRequest {
        $cacheKey = "sentinel:ext_sig:{$sigRequest->id}:{$challengeToken}";
        /** @var mixed $cached */
        $cached = cache()->pull($cacheKey);
        if (
            ! is_array($cached)
            || ($cached['actor_id'] ?? null) !== $actor->id
            || ! is_string($cached['working_version_id'] ?? null)
            || ! is_string($cached['user_certificate_id'] ?? null)
            || ! is_string($cached['reason'] ?? null)
            || ! is_string($cached['location'] ?? null)
            || ! is_array($cached['state'] ?? null)
        ) {
            throw ValidationException::withMessages(['challenge_token' => 'Expired or invalid smartcard signing challenge.']);
        }

        $envelope = $sigRequest->envelope()->with(['document', 'requests'])->firstOrFail();
        $document = $envelope->document;
        if (! $document instanceof Document) {
            throw new RuntimeException('Envelope missing document.');
        }

        $workingVersion = DocumentVersion::query()->findOrFail($cached['working_version_id']);
        $userCert = UserCertificate::query()->findOrFail($cached['user_certificate_id']);

        /** @var array<string, mixed> $preparedState */
        $preparedState = $cached['state'];

        $res = $this->signerService->finalizeSmartcardSignature(
            document: $document,
            sourceVersion: $workingVersion,
            signer: $actor,
            userCert: $userCert,
            preparedState: $preparedState,
            signatureB64: $signatureB64,
        );

        return $this->commitSignedOutcome(
            sigRequest: $sigRequest,
            envelope: $envelope,
            document: $document,
            actor: $actor,
            tier: SignatureTier::Tier2Smartcard,
            newVersion: $res['version'],
            reason: $cached['reason'],
            location: $cached['location'],
            userCertId: $userCert->id,
            certSerial: $res['cert_serial'],
            tsaSerial: $res['tsa_token_serial'],
            tsaTs: $res['tsa_timestamp'],
            typedName: null,
            drawnSvgPath: null,
            request: $request,
        );
    }

    public function decline(SignatureRequest $sigRequest, User $actor, string $reason, ?Request $request = null): SignatureRequest
    {
        if ($sigRequest->signer_id !== $actor->id) {
            throw ValidationException::withMessages(['signer' => 'Only the assigned signer may decline this request.']);
        }
        if ($sigRequest->status === SignatureRequestStatus::Signed) {
            throw ValidationException::withMessages(['signer' => 'Signed requests cannot be declined.']);
        }

        $envelope = $sigRequest->envelope()->with('document')->firstOrFail();
        $document = $envelope->document;

        DB::transaction(function () use ($sigRequest, $envelope, $document, $actor, $reason, $request): void {
            $sigRequest->update([
                'status' => SignatureRequestStatus::Declined,
                'decline_reason' => $reason,
            ]);
            $envelope->update(['status' => EnvelopeStatus::Declined]);

            $this->audit->log(
                tenantId: $envelope->tenant_id,
                eventType: 'signature.declined',
                resourceType: 'signature_request',
                resourceId: $sigRequest->id,
                actor: $actor,
                classificationRank: $document->classification_rank ?? 0,
                after: ['envelope_id' => $envelope->id, 'reason' => $reason],
                request: $request,
            );
        });

        return $sigRequest->refresh();
    }

    public function reassign(SignatureRequest $sigRequest, User $actor, string $newSignerId, ?Request $request = null): SignatureRequest
    {
        $envelope = $sigRequest->envelope()->with('document')->firstOrFail();
        $document = $envelope->document;
        if (! $document instanceof Document) {
            throw new RuntimeException('Missing parent document.');
        }

        if ($sigRequest->signer_id !== $actor->id && $envelope->created_by !== $actor->id) {
            throw ValidationException::withMessages(['signer' => 'You are not authorized to reassign this signature slot.']);
        }
        if ($sigRequest->status === SignatureRequestStatus::Signed) {
            throw ValidationException::withMessages(['signer' => 'Cannot reassign an already-signed slot.']);
        }

        try {
            $this->policyEngine->assertCanShareToRecipient(
                grantor: $envelope->creator ?? $actor,
                document: $document,
                principalType: PrincipalType::User,
                principalId: $newSignerId,
                requestedPerms: ['perm_view' => true, 'perm_sign' => true],
                request: $request,
            );
        } catch (InsufficientClearanceException $e) {
            throw ValidationException::withMessages(['new_signer_id' => $e->getMessage()]);
        }

        return DB::transaction(function () use ($sigRequest, $envelope, $document, $actor, $newSignerId, $request): SignatureRequest {
            $priorStatus = $sigRequest->status;
            $priorSignerId = $sigRequest->signer_id;
            $sigRequest->update(['status' => SignatureRequestStatus::Reassigned]);

            /** @var SignatureRequest $replacement */
            $replacement = SignatureRequest::query()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $envelope->tenant_id,
                'envelope_id' => $envelope->id,
                'signer_id' => $newSignerId,
                'delegated_from_id' => $priorSignerId,
                'sign_order' => $sigRequest->sign_order,
                'tier' => $sigRequest->tier,
                'status' => $priorStatus,
                'field_name' => $sigRequest->field_name.'_R',
                'field_coords_json' => $sigRequest->field_coords_json,
            ]);

            DocumentAclGrant::query()->firstOrCreate(
                [
                    'tenant_id' => $document->tenant_id,
                    'document_id' => $document->id,
                    'principal_type' => PrincipalType::User,
                    'principal_id' => $newSignerId,
                ],
                [
                    'perm_view' => true,
                    'perm_sign' => true,
                    'granted_by' => $actor->id,
                ]
            );

            $this->audit->log(
                tenantId: $envelope->tenant_id,
                eventType: 'signature.reassigned',
                resourceType: 'signature_request',
                resourceId: $replacement->id,
                actor: $actor,
                classificationRank: $document->classification_rank,
                before: ['signer_id' => $priorSignerId],
                after: ['signer_id' => $newSignerId],
                request: $request,
            );

            return $replacement;
        });
    }

    public function remind(SignatureRequest $sigRequest, User $actor, ?Request $request = null): SignatureRequest
    {
        $envelope = $sigRequest->envelope()->with('document')->firstOrFail();
        $sigRequest->update([
            'reminded_at' => now(),
            'reminder_count' => $sigRequest->reminder_count + 1,
        ]);

        $this->audit->log(
            tenantId: $envelope->tenant_id,
            eventType: 'signature.reminder_sent',
            resourceType: 'signature_request',
            resourceId: $sigRequest->id,
            actor: $actor,
            classificationRank: $envelope->document->classification_rank ?? 0,
            after: ['reminder_count' => $sigRequest->reminder_count, 'target_signer_id' => $sigRequest->signer_id],
            request: $request,
        );

        return $sigRequest->refresh();
    }

    private function commitSignedOutcome(
        SignatureRequest $sigRequest,
        SignatureEnvelope $envelope,
        Document $document,
        User $actor,
        SignatureTier $tier,
        DocumentVersion $newVersion,
        string $reason,
        string $location,
        ?string $userCertId,
        ?string $certSerial,
        ?string $tsaSerial,
        ?Carbon $tsaTs,
        ?string $typedName,
        ?string $drawnSvgPath,
        ?Request $request,
    ): SignatureRequest {
        $auditEvent = $this->audit->log(
            tenantId: $envelope->tenant_id,
            eventType: "signature.{$tier->value}_applied",
            resourceType: 'signature_request',
            resourceId: $sigRequest->id,
            actor: $actor,
            classificationRank: $document->classification_rank,
            after: [
                'envelope_id' => $envelope->id,
                'document_id' => $document->id,
                'version_id' => $newVersion->id,
                'cert_serial' => $certSerial,
                'tsa_token_serial' => $tsaSerial,
                'sha256' => $newVersion->sha256_hash,
            ],
            request: $request,
        );

        $sigRequest->update([
            'tier' => $tier,
            'status' => SignatureRequestStatus::Signed,
            'signature_reason' => $reason,
            'signature_location' => $location,
            'typed_name' => $typedName,
            'drawn_stamp_svg' => $drawnSvgPath,
            'user_certificate_id' => $userCertId,
            'cert_serial' => $certSerial,
            'tsa_token_serial' => $tsaSerial,
            'tsa_timestamp' => $tsaTs,
            'document_sha256_at_sign' => $newVersion->sha256_hash,
            'signed_version_id' => $newVersion->id,
            'audit_event_id' => $auditEvent->seq_id,
            'signed_at' => now(),
        ]);

        $envelope->update(['working_version_id' => $newVersion->id]);

        // Advance ordered queue or seal completed envelope
        $remaining = SignatureRequest::query()
            ->where('envelope_id', $envelope->id)
            ->whereIn('status', [SignatureRequestStatus::Pending->value, SignatureRequestStatus::Ready->value])
            ->orderBy('sign_order')
            ->get();

        if ($remaining->isEmpty()) {
            $signedRows = SignatureRequest::query()
                ->with('signer')
                ->where('envelope_id', $envelope->id)
                ->where('status', SignatureRequestStatus::Signed->value)
                ->orderBy('sign_order')
                ->get();

            $signedList = [];
            foreach ($signedRows as $r) {
                $signedList[] = [
                    'signer_name' => $r->signer->name ?? 'Officer',
                    'tier' => $r->tier->value,
                    'signed_at' => $r->signed_at?->toIso8601String(),
                    'cert_serial' => $r->cert_serial,
                    'tsa_token_serial' => $r->tsa_token_serial,
                    'reason' => $r->signature_reason,
                    'audit_event_id' => $r->audit_event_id,
                ];
            }

            $sealedVer = $this->signerService->sealCompletedEnvelope(
                envelope: $envelope->refresh(),
                document: $document->refresh(),
                workingVersion: $newVersion,
                sealingActor: $actor,
                manifest: $signedList,
            );

            $envelope->update([
                'status' => EnvelopeStatus::Completed,
                'completed_at' => now(),
            ]);

            $this->audit->log(
                tenantId: $envelope->tenant_id,
                eventType: 'signature.envelope_sealed',
                resourceType: 'signature_envelope',
                resourceId: $envelope->id,
                actor: $actor,
                classificationRank: $document->classification_rank,
                after: [
                    'sealed_version_id' => $sealedVer->id,
                    'verification_token' => $envelope->verification_token,
                ],
                request: $request,
            );
        } elseif ($envelope->mode === EnvelopeMode::Ordered) {
            $next = $remaining->first();
            if ($next->status === SignatureRequestStatus::Pending) {
                $next->update(['status' => SignatureRequestStatus::Ready]);
            }
        }

        return $sigRequest->refresh();
    }

    private function assertSignerTurnReady(SignatureRequest $sigRequest, SignatureEnvelope $envelope, User $actor): void
    {
        if ($sigRequest->signer_id !== $actor->id) {
            throw ValidationException::withMessages(['signer' => 'You are not the designated signer for this field.']);
        }
        if ($envelope->status === EnvelopeStatus::Declined || $envelope->status === EnvelopeStatus::Cancelled) {
            throw ValidationException::withMessages(['envelope' => "Envelope is {$envelope->status->value} and closed for signing."]);
        }
        if ($sigRequest->status !== SignatureRequestStatus::Ready) {
            throw ValidationException::withMessages(['signer' => 'Signing is waiting for an earlier ordered signer or has already completed.']);
        }
    }

    private function assertFreshMfaStepUp(?UserSession $session): void
    {
        if (! config()->boolean('sentinel.pki.require_fresh_mfa_for_pades', true)) {
            return;
        }

        $ttl = config()->integer('sentinel.security.step_up_mfa_ttl_minutes', 5);
        if ($session === null || ! $session->isMfaFresh($ttl)) {
            throw ValidationException::withMessages([
                'mfa' => "Fresh MFA step-up verification (within {$ttl} minutes) is required before applying a PAdES X.509 signature.",
            ]);
        }
    }

    private function resolveActiveSigningCertificate(User $user, bool $preferSmartcard = false): UserCertificate
    {
        $query = UserCertificate::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('status', CertificateStatus::Active->value)
            ->whereNull('revoked_at')
            ->where('valid_to', '>', now());

        if ($preferSmartcard) {
            $scCert = (clone $query)->where('custody_mode', 'smartcard_pkcs11')->latest()->first();
            if ($scCert !== null) {
                return $scCert;
            }
        }

        /** @var UserCertificate|null $existing */
        $existing = $query->latest()->first();
        if ($existing !== null) {
            return $existing;
        }

        return $this->ca->issueUserCertificate($user);
    }
}
