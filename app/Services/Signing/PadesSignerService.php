<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Enums\AvStatus;
use App\Enums\CdrStatus;
use App\Enums\OcrStatus;
use App\Enums\SignatureVerificationStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignatureEnvelope;
use App\Models\SignatureVerification;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserCertificate;
use App\Services\Crypto\EnvelopeEncryptionService;
use App\Services\Pki\HsmCustodyService;
use App\Services\Pki\InternalCaService;
use App\Services\Pki\SignerBridgeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PadesSignerService
{
    public function __construct(
        private readonly InternalCaService $ca,
        private readonly HsmCustodyService $hsm,
        private readonly SignerBridgeService $bridge,
        private readonly EnvelopeEncryptionService $crypto,
    ) {}

    /**
     * Tier 1: Stamp drawn SVG path or typed signature visually onto PDF.
     *
     * @param  array{page: int, x: float|int, y: float|int, width: float|int, height: float|int}  $coords
     */
    public function applyTier1Stamp(
        Document $document,
        DocumentVersion $sourceVersion,
        User $signer,
        array $coords,
        string $reason,
        ?string $typedName = null,
        ?string $drawnSvgPath = null,
    ): DocumentVersion {
        $inPath = $this->decryptVersionToTempFile($sourceVersion);
        $outPath = tempnam(sys_get_temp_dir(), 'sentinel_t1_out_');
        if ($outPath === false) {
            @unlink($inPath);
            throw new RuntimeException('Unable to allocate temporary PDF output path.');
        }

        try {
            $this->bridge->execute('stamp_tier1', [
                'input_pdf_path' => $inPath,
                'output_pdf_path' => $outPath,
                'coords' => $coords,
                'signer_name' => $signer->name,
                'rank_title' => $signer->rank_title ?? '',
                'reason' => $reason,
                'typed_name' => $typedName,
                'drawn_svg_path' => $drawnSvgPath,
            ]);

            return $this->storeSignedVersion(
                document: $document,
                sourceVersion: $sourceVersion,
                signedPdfPath: $outPath,
                signer: $signer,
                note: "Tier-1 Stamp signed by {$signer->name}",
                isMajorSeal: false,
            );
        } finally {
            @unlink($inPath);
            @unlink($outPath);
        }
    }

    /**
     * Tier 2 / Tier 3 (HSM-backed X.509 PAdES-B-LT + RFC 3161 TSA + /DSS).
     *
     * @param  array{page: int, x: float|int, y: float|int, width: float|int, height: float|int}  $coords
     * @return array{version: DocumentVersion, cert_serial: string, tsa_token_serial: string, tsa_timestamp: Carbon, sha256: string}
     */
    public function signHsmPades(
        Document $document,
        DocumentVersion $sourceVersion,
        User $signer,
        UserCertificate $userCert,
        string $fieldName,
        array $coords,
        string $reason,
        string $location,
        bool $useLta = false,
    ): array {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($document->tenant_id);
        $rootCa = $this->ca->authorityByRole($tenant->id, 'root');
        $interCa = $this->ca->authorityByRole($tenant->id, 'intermediate');
        $tsaCa = $this->ca->authorityByRole($tenant->id, 'tsa');

        $signerKeyPem = $this->hsm->unsealKey($tenant->id, $userCert->pkcs11_key_label);
        $tsaKeyPem = $this->hsm->unsealKey($tenant->id, $tsaCa->pkcs11_key_label);
        $crlDer = $this->ca->latestCrlDer($tenant);
        $ocspDer = $this->ca->buildOcspResponseDer($tenant, $userCert->serial_number);

        $inPath = $this->decryptVersionToTempFile($sourceVersion);
        $outPath = tempnam(sys_get_temp_dir(), 'sentinel_pades_out_');
        if ($outPath === false) {
            sodium_memzero($signerKeyPem);
            sodium_memzero($tsaKeyPem);
            @unlink($inPath);
            throw new RuntimeException('Unable to allocate signed PDF temp file.');
        }

        try {
            $signerLabel = trim(($signer->rank_title ? $signer->rank_title.' ' : '').$signer->name);
            $res = $this->bridge->execute('sign_pades', [
                'input_pdf_path' => $inPath,
                'output_pdf_path' => $outPath,
                'signer_cert_pem' => $userCert->certificate_pem,
                'signer_key_pem' => $signerKeyPem,
                'ca_chain_pems' => [$interCa->certificate_pem, $rootCa->certificate_pem],
                'tsa_cert_pem' => $tsaCa->certificate_pem,
                'tsa_key_pem' => $tsaKeyPem,
                'crl_der_b64' => base64_encode($crlDer),
                'ocsp_der_b64' => base64_encode($ocspDer),
                'field_name' => $fieldName,
                'coords' => $coords,
                'signer_label' => $signerLabel,
                'reason' => $reason,
                'location' => $location,
                'use_lta' => $useLta,
            ]);

            $newVersion = $this->storeSignedVersion(
                document: $document,
                sourceVersion: $sourceVersion,
                signedPdfPath: $outPath,
                signer: $signer,
                note: "PAdES signed by {$signerLabel} (Cert {$userCert->serial_number})",
                isMajorSeal: false,
            );

            $this->verifyDocumentVersion($newVersion);

            $certSerial = is_string($res['cert_serial'] ?? null) ? $res['cert_serial'] : $userCert->serial_number;
            $tsaSerial = is_string($res['tsa_token_serial'] ?? null) ? $res['tsa_token_serial'] : '';
            $tsaTs = is_string($res['tsa_timestamp'] ?? null) ? Carbon::parse($res['tsa_timestamp']) : now();
            $sha = is_string($res['sha256'] ?? null) ? $res['sha256'] : $newVersion->sha256_hash;

            return [
                'version' => $newVersion,
                'cert_serial' => $certSerial,
                'tsa_token_serial' => $tsaSerial,
                'tsa_timestamp' => $tsaTs,
                'sha256' => $sha,
            ];
        } finally {
            sodium_memzero($signerKeyPem);
            sodium_memzero($tsaKeyPem);
            @unlink($inPath);
            @unlink($outPath);
        }
    }

    /**
     * Tier 2B Step 1: Prepare ByteRange & exact CMS SignedAttributes digest for Smartcard / PKCS#11 client signing.
     *
     * @param  array{page: int, x: float|int, y: float|int, width: float|int, height: float|int}  $coords
     * @return array<string, mixed>
     */
    public function prepareSmartcardDigest(
        Document $document,
        DocumentVersion $sourceVersion,
        User $signer,
        UserCertificate $userCert,
        string $fieldName,
        array $coords,
        string $reason,
        string $location,
    ): array {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($document->tenant_id);
        $rootCa = $this->ca->authorityByRole($tenant->id, 'root');
        $interCa = $this->ca->authorityByRole($tenant->id, 'intermediate');

        $inPath = $this->decryptVersionToTempFile($sourceVersion);
        try {
            $signerLabel = trim(($signer->rank_title ? $signer->rank_title.' ' : '').$signer->name);

            return $this->bridge->execute('prepare_external', [
                'input_pdf_path' => $inPath,
                'signer_cert_pem' => $userCert->certificate_pem,
                'ca_chain_pems' => [$interCa->certificate_pem, $rootCa->certificate_pem],
                'field_name' => $fieldName,
                'coords' => $coords,
                'signer_label' => $signerLabel,
                'reason' => $reason,
                'location' => $location,
            ]);
        } finally {
            @unlink($inPath);
        }
    }

    /**
     * Tier 2B Step 2: Finalize Smartcard signature with raw RSA signature bytes over SignedAttributes.
     *
     * @param  array<string, mixed>  $preparedState
     * @return array{version: DocumentVersion, cert_serial: string, tsa_token_serial: string, tsa_timestamp: Carbon, sha256: string}
     */
    public function finalizeSmartcardSignature(
        Document $document,
        DocumentVersion $sourceVersion,
        User $signer,
        UserCertificate $userCert,
        array $preparedState,
        string $signatureB64,
    ): array {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($document->tenant_id);
        $tsaCa = $this->ca->authorityByRole($tenant->id, 'tsa');
        $tsaKeyPem = $this->hsm->unsealKey($tenant->id, $tsaCa->pkcs11_key_label);
        $crlDer = $this->ca->latestCrlDer($tenant);
        $ocspDer = $this->ca->buildOcspResponseDer($tenant, $userCert->serial_number);

        $outPath = tempnam(sys_get_temp_dir(), 'sentinel_ext_out_');
        if ($outPath === false) {
            sodium_memzero($tsaKeyPem);
            throw new RuntimeException('Unable to allocate external signature output file.');
        }

        try {
            $res = $this->bridge->execute('finalize_external', [
                'state' => $preparedState,
                'signature_b64' => $signatureB64,
                'output_pdf_path' => $outPath,
                'tsa_cert_pem' => $tsaCa->certificate_pem,
                'tsa_key_pem' => $tsaKeyPem,
                'crl_der_b64' => base64_encode($crlDer),
                'ocsp_der_b64' => base64_encode($ocspDer),
            ]);

            $signerLabel = trim(($signer->rank_title ? $signer->rank_title.' ' : '').$signer->name);
            $newVersion = $this->storeSignedVersion(
                document: $document,
                sourceVersion: $sourceVersion,
                signedPdfPath: $outPath,
                signer: $signer,
                note: "Smartcard PAdES signed by {$signerLabel} (Cert {$userCert->serial_number})",
                isMajorSeal: false,
            );

            $this->verifyDocumentVersion($newVersion);

            return [
                'version' => $newVersion,
                'cert_serial' => is_string($res['cert_serial'] ?? null) ? $res['cert_serial'] : $userCert->serial_number,
                'tsa_token_serial' => is_string($res['tsa_token_serial'] ?? null) ? $res['tsa_token_serial'] : '',
                'tsa_timestamp' => is_string($res['tsa_timestamp'] ?? null) ? Carbon::parse($res['tsa_timestamp']) : now(),
                'sha256' => is_string($res['sha256'] ?? null) ? $res['sha256'] : $newVersion->sha256_hash,
            ];
        } finally {
            sodium_memzero($tsaKeyPem);
            @unlink($outPath);
        }
    }

    /**
     * Seal completed multi-party envelope:
     * 1. Apply visible QR Code PAdES-B-LTA Organization Seal onto signed PDF.
     * 2. Build & sign tamper-evident Certificate of Completion PDF stored envelope-encrypted.
     *
     * @param  list<array{signer_name: string, tier: string, signed_at: string|null, cert_serial: string|null, tsa_token_serial: string|null, reason: string|null, audit_event_id: int|null}>  $manifest
     */
    public function sealCompletedEnvelope(
        SignatureEnvelope $envelope,
        Document $document,
        DocumentVersion $workingVersion,
        User $sealingActor,
        array $manifest,
    ): DocumentVersion {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($document->tenant_id);
        $rootCa = $this->ca->authorityByRole($tenant->id, 'root');
        $interCa = $this->ca->authorityByRole($tenant->id, 'intermediate');
        $tsaCa = $this->ca->authorityByRole($tenant->id, 'tsa');
        $sealCa = $this->ca->authorityByRole($tenant->id, 'seal');

        $sealKeyPem = $this->hsm->unsealKey($tenant->id, $sealCa->pkcs11_key_label);
        $tsaKeyPem = $this->hsm->unsealKey($tenant->id, $tsaCa->pkcs11_key_label);
        $crlDer = $this->ca->latestCrlDer($tenant);

        $inPath = $this->decryptVersionToTempFile($workingVersion);
        $sealedOut = tempnam(sys_get_temp_dir(), 'sentinel_sealed_');
        $certOut = tempnam(sys_get_temp_dir(), 'sentinel_cert_');
        if ($sealedOut === false || $certOut === false) {
            sodium_memzero($sealKeyPem);
            sodium_memzero($tsaKeyPem);
            @unlink($inPath);
            throw new RuntimeException('Unable to allocate temporary envelope sealing paths.');
        }

        try {
            $verifyUrl = rtrim(config()->string('app.url', 'https://sentinel.lan'), '/')."/verify/{$envelope->verification_token}";

            $this->bridge->execute('seal_envelope', [
                'input_pdf_path' => $inPath,
                'sealed_pdf_path' => $sealedOut,
                'certificate_pdf_path' => $certOut,
                'seal_cert_pem' => $sealCa->certificate_pem,
                'seal_key_pem' => $sealKeyPem,
                'ca_chain_pems' => [$interCa->certificate_pem, $rootCa->certificate_pem],
                'tsa_cert_pem' => $tsaCa->certificate_pem,
                'tsa_key_pem' => $tsaKeyPem,
                'crl_der_b64' => base64_encode($crlDer),
                'verify_url' => $verifyUrl,
                'envelope_title' => $envelope->title,
                'doc_number' => $document->doc_number,
                'signers_manifest' => $manifest,
            ]);

            $sealedVersion = $this->storeSignedVersion(
                document: $document,
                sourceVersion: $workingVersion,
                signedPdfPath: $sealedOut,
                signer: $sealingActor,
                note: "Sealed Multi-Party Envelope ({$envelope->title}) — PAdES-B-LTA + QR",
                isMajorSeal: true,
            );

            $certStorageKey = "tenants/{$tenant->id}/envelopes/{$envelope->id}/certificate.enc";
            $certEnc = $this->crypto->encryptFileToDisk($certOut, 'sentinel_vault', $certStorageKey);

            $envelope->update([
                'sealed_version_id' => $sealedVersion->id,
                'working_version_id' => $sealedVersion->id,
                'certificate_storage_key' => $certStorageKey,
                'certificate_wrapped_dek' => $certEnc['wrapped_dek'],
                'certificate_kek_key_id' => $certEnc['kek_key_id'],
            ]);

            $this->verifyDocumentVersion($sealedVersion);

            return $sealedVersion;
        } finally {
            sodium_memzero($sealKeyPem);
            sodium_memzero($tsaKeyPem);
            @unlink($inPath);
            @unlink($sealedOut);
            @unlink($certOut);
        }
    }

    /**
     * Cryptographically verify every signature on a DocumentVersion against local Root CA & live CRL/OCSP.
     */
    public function verifyDocumentVersion(DocumentVersion $version): SignatureVerification
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($version->tenant_id);

        $existingPki = DB::table('certificate_authorities')
            ->where('tenant_id', $tenant->id)
            ->where('role', 'root')
            ->exists();

        if (! $existingPki || $version->mime_type !== 'application/pdf') {
            return $this->upsertVerification($version, SignatureVerificationStatus::Unsigned, 0, [
                'signatures' => [],
                'note' => $version->mime_type === 'application/pdf' ? 'Tenant PKI not initialized.' : 'Non-PDF asset.',
            ]);
        }

        $rootCa = $this->ca->authorityByRole($tenant->id, 'root');
        $interCa = $this->ca->authorityByRole($tenant->id, 'intermediate');
        $tsaCa = $this->ca->authorityByRole($tenant->id, 'tsa');
        $crlDer = $this->ca->latestCrlDer($tenant);

        $inPath = $this->decryptVersionToTempFile($version);
        try {
            $res = $this->bridge->execute('verify_pdf', [
                'input_pdf_path' => $inPath,
                'trust_root_pem' => $rootCa->certificate_pem,
                'inter_pem' => $interCa->certificate_pem,
                'tsa_pem' => $tsaCa->certificate_pem,
                'crl_der_b64' => base64_encode($crlDer),
                'ocsps_der_b64' => [],
            ]);

            $statusStr = is_string($res['overall_status'] ?? null) ? $res['overall_status'] : 'unsigned';
            $statusEnum = SignatureVerificationStatus::tryFrom($statusStr) ?? SignatureVerificationStatus::Altered;
            $sigCount = is_int($res['signatures_count'] ?? null) ? $res['signatures_count'] : 0;

            return $this->upsertVerification($version, $statusEnum, $sigCount, $res);
        } finally {
            @unlink($inPath);
        }
    }

    /**
     * Decrypt a sealed envelope's standalone Certificate of Completion PDF stream.
     *
     * @return resource
     */
    public function openCertificatePdfStream(SignatureEnvelope $envelope)
    {
        if (
            $envelope->certificate_storage_key === null
            || $envelope->certificate_wrapped_dek === null
            || $envelope->certificate_kek_key_id === null
        ) {
            throw new RuntimeException('Certificate of completion has not been generated for this envelope yet.');
        }

        $tempVersion = new DocumentVersion([
            'storage_disk' => 'sentinel_vault',
            'storage_key' => $envelope->certificate_storage_key,
            'wrapped_dek' => $envelope->certificate_wrapped_dek,
            'kek_key_id' => $envelope->certificate_kek_key_id,
        ]);

        return $this->crypto->decryptVersionToTempHandle($tempVersion);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function upsertVerification(
        DocumentVersion $version,
        SignatureVerificationStatus $status,
        int $count,
        array $details,
    ): SignatureVerification {
        /** @var SignatureVerification $record */
        $record = SignatureVerification::query()->updateOrCreate(
            ['document_version_id' => $version->id],
            [
                'tenant_id' => $version->tenant_id,
                'document_id' => $version->document_id,
                'overall_status' => $status,
                'signatures_count' => $count,
                'details_json' => $details,
                'verified_at' => now(),
            ]
        );

        return $record;
    }

    private function storeSignedVersion(
        Document $document,
        DocumentVersion $sourceVersion,
        string $signedPdfPath,
        User $signer,
        string $note,
        bool $isMajorSeal,
    ): DocumentVersion {
        $bytes = file_get_contents($signedPdfPath);
        if ($bytes === false) {
            throw new RuntimeException('Unable to read signed PDF bytes.');
        }

        $sha256 = hash('sha256', $bytes);
        $size = strlen($bytes);
        $verId = (string) Str::uuid7();

        return DB::transaction(function () use ($document, $sourceVersion, $signedPdfPath, $signer, $note, $isMajorSeal, $sha256, $size, $verId): DocumentVersion {
            /** @var DocumentVersion|null $latest */
            $latest = DocumentVersion::query()
                ->where('document_id', $document->id)
                ->orderByDesc('version_major')
                ->orderByDesc('version_minor')
                ->lockForUpdate()
                ->first();

            $major = $latest !== null
                ? ($isMajorSeal ? $latest->version_major + 1 : $latest->version_major)
                : 1;
            $minor = $latest !== null
                ? ($isMajorSeal ? 0 : $latest->version_minor + 1)
                : 0;

            $storageKey = "tenants/{$document->tenant_id}/docs/{$document->id}/v{$major}_{$minor}_{$verId}.enc";
            $enc = $this->crypto->encryptFileToDisk($signedPdfPath, 'sentinel_vault', $storageKey);

            DocumentVersion::query()
                ->where('document_id', $document->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            /** @var DocumentVersion $version */
            $version = DocumentVersion::query()->create([
                'id' => $verId,
                'tenant_id' => $document->tenant_id,
                'document_id' => $document->id,
                'version_major' => $major,
                'version_minor' => $minor,
                'is_current' => true,
                'storage_disk' => 'sentinel_vault',
                'storage_key' => $storageKey,
                'original_filename' => $sourceVersion->original_filename,
                'mime_type' => 'application/pdf',
                'file_size_bytes' => $size,
                'sha256_hash' => $sha256,
                'wrapped_dek' => $enc['wrapped_dek'],
                'kek_key_id' => $enc['kek_key_id'],
                'av_status' => AvStatus::Clean,
                'cdr_status' => CdrStatus::Sanitized,
                'ocr_status' => OcrStatus::Completed,
                'pdfa_compliant' => true,
                'page_count' => $sourceVersion->page_count,
                'extracted_text' => $sourceVersion->extracted_text,
                'version_note' => $note,
                'created_by' => $signer->id,
            ]);

            $document->update(['current_version_id' => $version->id]);

            return $version;
        });
    }

    private function decryptVersionToTempFile(DocumentVersion $version): string
    {
        $handle = $this->crypto->decryptVersionToTempHandle($version);
        $tmpPath = tempnam(sys_get_temp_dir(), 'sentinel_pdf_in_');
        if ($tmpPath === false) {
            fclose($handle);
            throw new RuntimeException('Unable to allocate temp PDF input file.');
        }

        $dest = fopen($tmpPath, 'wb');
        if ($dest === false) {
            fclose($handle);
            @unlink($tmpPath);
            throw new RuntimeException('Unable to write temp PDF input file.');
        }

        stream_copy_to_stream($handle, $dest);
        fclose($dest);
        fclose($handle);

        return $tmpPath;
    }
}
