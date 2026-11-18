<?php

declare(strict_types=1);

namespace App\Services\Pki;

use App\Enums\CertificateCustodyMode;
use App\Enums\CertificateStatus;
use App\Models\CertificateAuthority;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserCertificate;
use App\Services\Audit\ImmutableAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class InternalCaService
{
    public function __construct(
        private readonly HsmCustodyService $hsm,
        private readonly SignerBridgeService $bridge,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * Initialize or return the tenant's online Intermediate Issuing CA
     * (with offline Root CA, local RFC 3161 TSA, OCSP responder, and Sealing Authority leaf cert).
     */
    public function ensureBootstrapped(Tenant $tenant, ?User $actor = null, ?Request $request = null): CertificateAuthority
    {
        /** @var CertificateAuthority|null $existingInter */
        $existingInter = CertificateAuthority::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'intermediate')
            ->first();

        if ($existingInter !== null && $this->hsm->hasKey($tenant->id, $existingInter->pkcs11_key_label)) {
            return $existingInter;
        }

        $baseUrl = rtrim(config()->string('app.url', 'https://betterdoc.lan'), '/');
        $raw = $this->bridge->execute('bootstrap_pki', [
            'tenant_name' => $tenant->name,
            'tenant_slug' => $tenant->slug,
            'ocsp_base_url' => "{$baseUrl}/pki/ocsp/{$tenant->id}",
            'crl_url' => "{$baseUrl}/pki/ca/{$tenant->id}/crl.der",
        ]);

        return DB::transaction(function () use ($tenant, $raw, $actor, $request): CertificateAuthority {
            /** @var array<string, mixed> $rootData */
            $rootData = $this->arrayField($raw, 'root');
            /** @var array<string, mixed> $interData */
            $interData = $this->arrayField($raw, 'intermediate');
            /** @var array<string, mixed> $tsaData */
            $tsaData = $this->arrayField($raw, 'tsa');
            /** @var array<string, mixed> $ocspData */
            $ocspData = $this->arrayField($raw, 'ocsp');
            /** @var array<string, mixed> $sealData */
            $sealData = $this->arrayField($raw, 'seal');

            $root = $this->persistCaRecord($tenant, 'root', "{$tenant->name} Offline Root CA", null, $rootData, 'hsm-root-ca');
            $inter = $this->persistCaRecord($tenant, 'intermediate', "{$tenant->name} Intermediate Issuing CA", $root->id, $interData, 'hsm-inter-ca');
            $this->persistCaRecord($tenant, 'tsa', "{$tenant->name} RFC 3161 TSA", $inter->id, $tsaData, 'hsm-tsa-signer');
            $this->persistCaRecord($tenant, 'ocsp', "{$tenant->name} OCSP Responder", $inter->id, $ocspData, 'hsm-ocsp-signer');
            $this->persistCaRecord($tenant, 'seal', "{$tenant->name} Envelope Seal Authority", $inter->id, $sealData, 'hsm-seal-signer');

            // Generate initial empty DER CRL
            $this->regenerateCrl($tenant, $inter);

            if ($actor !== null) {
                $this->audit->log(
                    tenantId: $tenant->id,
                    eventType: 'pki.ca_bootstrapped',
                    resourceType: 'certificate_authority',
                    resourceId: $inter->id,
                    actor: $actor,
                    classificationRank: $actor->clearance_rank,
                    after: ['root_serial' => $root->serial_number, 'inter_serial' => $inter->serial_number],
                    request: $request,
                );
            }

            return $inter;
        });
    }

    public function issueUserCertificate(
        User $subjectUser,
        CertificateCustodyMode $custodyMode = CertificateCustodyMode::HsmManaged,
        ?User $actor = null,
        int $validDays = 365,
        ?string $csrPem = null,
        ?string $pkcs11SlotId = null,
        ?Request $request = null,
    ): UserCertificate {
        /** @var Tenant $tenant */
        $tenant = $subjectUser->tenant ?? Tenant::query()->findOrFail($subjectUser->tenant_id);
        $inter = $this->ensureBootstrapped($tenant, $actor, $request);

        $caKey = $this->hsm->unsealKey($tenant->id, $inter->pkcs11_key_label);
        $baseUrl = rtrim(config()->string('app.url', 'https://betterdoc.lan'), '/');

        try {
            $issued = $this->bridge->execute('issue_user_cert', [
                'ca_cert_pem' => $inter->certificate_pem,
                'ca_key_pem' => $caKey,
                'common_name' => trim(($subjectUser->rank_title ? $subjectUser->rank_title.' ' : '').$subjectUser->name),
                'email' => $subjectUser->email,
                'org_name' => $tenant->name,
                'org_unit' => $subjectUser->orgUnit->code ?? 'OPERATIONS',
                'ocsp_url' => "{$baseUrl}/pki/ocsp/{$tenant->id}",
                'crl_url' => "{$baseUrl}/pki/ca/{$tenant->id}/crl.der",
                'valid_days' => $validDays,
                'csr_pem' => $csrPem,
            ]);
        } finally {
            sodium_memzero($caKey);
        }

        $certId = (string) Str::uuid7();
        $keyLabel = $custodyMode === CertificateCustodyMode::SmartcardPkcs11
            ? 'smartcard-token-'.$certId
            : 'hsm-user-'.$certId;

        if ($custodyMode !== CertificateCustodyMode::SmartcardPkcs11 && is_string($issued['private_key_pem'] ?? null)) {
            $priv = $issued['private_key_pem'];
            $this->hsm->storeSealedKey($tenant->id, $keyLabel, $priv);
            sodium_memzero($priv);
        }

        $validFrom = Carbon::parse($this->stringField($issued, 'valid_from'));
        $validTo = Carbon::parse($this->stringField($issued, 'valid_to'));

        /** @var UserCertificate $record */
        $record = UserCertificate::query()->create([
            'id' => $certId,
            'tenant_id' => $tenant->id,
            'user_id' => $subjectUser->id,
            'ca_id' => $inter->id,
            'serial_number' => $this->stringField($issued, 'serial_number'),
            'subject_dn' => $this->stringField($issued, 'subject_dn'),
            'issuer_dn' => $this->stringField($issued, 'issuer_dn'),
            'certificate_pem' => $this->stringField($issued, 'certificate_pem'),
            'fingerprint_sha256' => $this->stringField($issued, 'fingerprint_sha256'),
            'custody_mode' => $custodyMode,
            'pkcs11_key_label' => $keyLabel,
            'pkcs11_slot_id' => $pkcs11SlotId,
            'status' => $validTo->isPast() ? CertificateStatus::Expired : CertificateStatus::Active,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ]);

        $auditActor = $actor ?? $subjectUser;
        $this->audit->log(
            tenantId: $tenant->id,
            eventType: 'pki.certificate_issued',
            resourceType: 'user_certificate',
            resourceId: $record->id,
            actor: $auditActor,
            classificationRank: $auditActor->clearance_rank,
            after: [
                'subject_user_id' => $subjectUser->id,
                'serial_number' => $record->serial_number,
                'custody_mode' => $custodyMode->value,
                'fingerprint_sha256' => $record->fingerprint_sha256,
            ],
            request: $request,
        );

        return $record;
    }

    public function revokeCertificate(
        UserCertificate $cert,
        User $actor,
        string $reason = 'cessation_of_operation',
        ?Request $request = null,
    ): UserCertificate {
        if ($cert->status === CertificateStatus::Revoked) {
            return $cert;
        }

        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($cert->tenant_id);
        $inter = $this->authorityByRole($tenant->id, 'intermediate');

        DB::transaction(function () use ($cert, $actor, $reason, $tenant, $inter, $request): void {
            $cert->update([
                'status' => CertificateStatus::Revoked,
                'revoked_at' => now(),
                'revocation_reason' => $reason,
                'revoked_by' => $actor->id,
            ]);

            $this->regenerateCrl($tenant, $inter);

            $cert->update(['crl_published_at' => now()]);

            $this->audit->log(
                tenantId: $tenant->id,
                eventType: 'pki.certificate_revoked',
                resourceType: 'user_certificate',
                resourceId: $cert->id,
                actor: $actor,
                classificationRank: $actor->clearance_rank,
                after: [
                    'serial_number' => $cert->serial_number,
                    'reason' => $reason,
                    'crl_number' => $inter->refresh()->crl_number,
                ],
                request: $request,
            );
        });

        return $cert->refresh();
    }

    public function regenerateCrl(Tenant $tenant, ?CertificateAuthority $inter = null): string
    {
        $inter ??= $this->authorityByRole($tenant->id, 'intermediate');
        $inter->increment('crl_number');
        $inter->refresh();

        $revoked = UserCertificate::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', CertificateStatus::Revoked->value)
            ->whereNotNull('revoked_at')
            ->get()
            ->map(static fn (UserCertificate $c): array => [
                'serial_number' => $c->serial_number,
                'revoked_at' => $c->revoked_at?->toIso8601String() ?? now()->toIso8601String(),
                'reason' => $c->revocation_reason ?? 'cessation_of_operation',
            ])
            ->values()
            ->all();

        $caKey = $this->hsm->unsealKey($tenant->id, $inter->pkcs11_key_label);
        try {
            $res = $this->bridge->execute('build_crl', [
                'ca_cert_pem' => $inter->certificate_pem,
                'ca_key_pem' => $caKey,
                'crl_number' => $inter->crl_number,
                'revoked_entries' => $revoked,
            ]);
        } finally {
            sodium_memzero($caKey);
        }

        $crlDer = base64_decode($this->stringField($res, 'crl_der_b64'), true);
        if ($crlDer === false) {
            throw new RuntimeException('Failed decoding generated DER CRL.');
        }

        $crlPath = $this->hsm->crlPath($tenant->id);
        File::ensureDirectoryExists(dirname($crlPath));
        File::put($crlPath, $crlDer);

        return $crlDer;
    }

    public function latestCrlDer(Tenant $tenant): string
    {
        $path = $this->hsm->crlPath($tenant->id);
        if (! File::exists($path)) {
            $this->ensureBootstrapped($tenant);
        }

        return File::exists($path) ? File::get($path) : $this->regenerateCrl($tenant);
    }

    public function buildOcspResponseDer(Tenant $tenant, string $serialNumber): string
    {
        $cleanSerial = strtoupper(trim($serialNumber));
        /** @var UserCertificate|null $cert */
        $cert = UserCertificate::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('serial_number', $cleanSerial)
            ->first();

        if ($cert === null) {
            throw new RuntimeException("Certificate serial [{$cleanSerial}] not found.");
        }

        $inter = $this->authorityByRole($tenant->id, 'intermediate');
        $ocspCa = $this->authorityByRole($tenant->id, 'ocsp');
        $ocspKey = $this->hsm->unsealKey($tenant->id, $ocspCa->pkcs11_key_label);

        try {
            $res = $this->bridge->execute('build_ocsp', [
                'target_cert_pem' => $cert->certificate_pem,
                'issuer_cert_pem' => $inter->certificate_pem,
                'responder_cert_pem' => $ocspCa->certificate_pem,
                'responder_key_pem' => $ocspKey,
                'revoked_at' => $cert->revoked_at?->toIso8601String(),
                'revocation_reason' => $cert->revocation_reason,
            ]);
        } finally {
            sodium_memzero($ocspKey);
        }

        $der = base64_decode($this->stringField($res, 'ocsp_der_b64'), true);
        if ($der === false) {
            throw new RuntimeException('Failed decoding DER OCSP response.');
        }

        return $der;
    }

    public function authorityByRole(string $tenantId, string $role): CertificateAuthority
    {
        /** @var CertificateAuthority $ca */
        $ca = CertificateAuthority::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('role', $role)
            ->firstOrFail();

        return $ca;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistCaRecord(
        Tenant $tenant,
        string $role,
        string $name,
        ?string $parentId,
        array $data,
        string $keyLabelPrefix,
    ): CertificateAuthority {
        $id = (string) Str::uuid7();
        $label = "{$keyLabelPrefix}-{$tenant->slug}";
        $privKey = $this->stringField($data, 'private_key_pem');
        $this->hsm->storeSealedKey($tenant->id, $label, $privKey);
        sodium_memzero($privKey);

        /** @var CertificateAuthority $ca */
        $ca = CertificateAuthority::query()->create([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'parent_ca_id' => $parentId,
            'name' => $name,
            'role' => $role,
            'serial_number' => $this->stringField($data, 'serial_number'),
            'subject_dn' => $this->stringField($data, 'subject_dn'),
            'issuer_dn' => $this->stringField($data, 'issuer_dn'),
            'certificate_pem' => $this->stringField($data, 'certificate_pem'),
            'fingerprint_sha256' => $this->stringField($data, 'fingerprint_sha256'),
            'pkcs11_key_label' => $label,
            'crl_number' => 1,
            'valid_from' => Carbon::parse($this->stringField($data, 'valid_from')),
            'valid_to' => Carbon::parse($this->stringField($data, 'valid_to')),
        ]);

        return $ca;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function arrayField(array $data, string $key): array
    {
        $val = $data[$key] ?? null;
        if (! is_array($val)) {
            throw new RuntimeException("Missing array field [{$key}] from PKI output.");
        }

        /** @var array<string, mixed> $typed */
        $typed = $val;

        return $typed;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function stringField(array $data, string $key): string
    {
        $val = $data[$key] ?? null;
        if (! is_string($val)) {
            throw new RuntimeException("Missing string field [{$key}] from PKI output.");
        }

        return $val;
    }
}
