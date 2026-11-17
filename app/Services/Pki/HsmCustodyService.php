<?php

declare(strict_types=1);

namespace App\Services\Pki;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * HSM / SoftHSM Key Custody Vault.
 *
 * Ensures private signing keys never exist in PostgreSQL or in plaintext on disk.
 * When `hsm_driver=sealed_file` (dev/standalone LAN), keys are authenticated-encrypted
 * using XChaCha20-Poly1305 with a dedicated HKDF subkey derived from SENTINEL's Master KEK.
 */
class HsmCustodyService
{
    public function storeSealedKey(string $tenantId, string $keyLabel, string $privateKeyPem): void
    {
        $path = $this->keyPath($tenantId, $keyLabel);
        File::ensureDirectoryExists(dirname($path));

        $kek = $this->hsmWrappingKey($tenantId);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $aad = "{$tenantId}|{$keyLabel}";

        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($privateKeyPem, $aad, $nonce, $kek);
        sodium_memzero($kek);

        $blob = base64_encode($nonce.$cipher);
        File::put($path, $blob);
        @chmod($path, 0600);
    }

    public function unsealKey(string $tenantId, string $keyLabel): string
    {
        $path = $this->keyPath($tenantId, $keyLabel);
        if (! File::exists($path)) {
            throw new RuntimeException("HSM key handle [{$keyLabel}] not found in sealed custody slot.");
        }

        $raw = base64_decode(File::get($path), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new RuntimeException("Corrupted HSM key slot [{$keyLabel}].");
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $kek = $this->hsmWrappingKey($tenantId);

        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, "{$tenantId}|{$keyLabel}", $nonce, $kek);
        } finally {
            sodium_memzero($kek);
        }

        if ($plain === false) {
            throw new RuntimeException("Failed unsealing HSM key [{$keyLabel}] — authentication tag mismatch.");
        }

        return $plain;
    }

    public function hasKey(string $tenantId, string $keyLabel): bool
    {
        return File::exists($this->keyPath($tenantId, $keyLabel));
    }

    public function crlPath(string $tenantId): string
    {
        $base = rtrim(config()->string('sentinel.pki.hsm_path', storage_path('app/hsm')), DIRECTORY_SEPARATOR);
        $safeTenant = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantId) ?? 'default';

        return $base.DIRECTORY_SEPARATOR.$safeTenant.DIRECTORY_SEPARATOR.'crl.der';
    }

    private function keyPath(string $tenantId, string $keyLabel): string
    {
        $base = rtrim(config()->string('sentinel.pki.hsm_path', storage_path('app/hsm')), DIRECTORY_SEPARATOR);
        $safeTenant = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantId) ?? 'default';
        $safeLabel = preg_replace('/[^a-zA-Z0-9._-]/', '_', $keyLabel) ?? 'key';

        return $base.DIRECTORY_SEPARATOR.$safeTenant.DIRECTORY_SEPARATOR."{$safeLabel}.key.enc";
    }

    private function hsmWrappingKey(string $tenantId): string
    {
        $masterB64 = config()->string('sentinel.security.master_kek_b64', '');
        if ($masterB64 !== '') {
            $decoded = base64_decode($masterB64, true);
            if ($decoded !== false && strlen($decoded) >= 32) {
                return hash_hkdf('sha256', $decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, "sentinel-hsm|{$tenantId}");
            }
        }

        $appKey = config()->string('app.key', '');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7), true) ?: $appKey;
        }

        return hash_hkdf('sha256', $appKey, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, "sentinel-hsm|{$tenantId}");
    }
}
