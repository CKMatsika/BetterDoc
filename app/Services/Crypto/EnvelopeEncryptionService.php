<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Streaming AEAD envelope encryption using libsodium XChaCha20-Poly1305 secretstream.
 * Processes 200MB+ files in constant 64KB memory blocks while wrapping per-document DEKs with Master KEK.
 */
class EnvelopeEncryptionService
{
    private const CHUNK_SIZE = 65536;

    /**
     * Encrypt a local plaintext file directly onto the target Storage disk.
     *
     * @return array{wrapped_dek: string, kek_key_id: string, ciphertext_size: int}
     */
    public function encryptFileToDisk(string $sourcePath, string $diskName, string $storageKey): array
    {
        $dek = random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
        $kekKeyId = config()->string('betterdoc.security.hsm_master_key_id', 'betterdoc-kek-v1');
        $wrappedDek = $this->wrapDek($dek, $kekKeyId);

        $in = fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new RuntimeException("Cannot open source file for encryption: {$sourcePath}");
        }

        $tempEnc = tmpfile();
        if ($tempEnc === false) {
            fclose($in);
            throw new RuntimeException('Cannot allocate temporary stream for encrypted envelope.');
        }

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($dek);
            if (! is_string($state) || ! is_string($header)) {
                throw new RuntimeException('Failed to initialize XChaCha20-Poly1305 stream cipher.');
            }
            fwrite($tempEnc, $header);

            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new RuntimeException('Failed reading source file during encryption.');
                }

                $isFinal = feof($in);
                $tag = $isFinal
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

                $sealed = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                fwrite($tempEnc, pack('N', strlen($sealed)).$sealed);

                if ($isFinal) {
                    break;
                }
            }

            $meta = stream_get_meta_data($tempEnc);
            $ciphertextSize = (int) ftell($tempEnc);
            rewind($tempEnc);

            Storage::disk($diskName)->put($storageKey, $tempEnc);

            unset($meta);

            return [
                'wrapped_dek' => $wrappedDek,
                'kek_key_id' => $kekKeyId,
                'ciphertext_size' => $ciphertextSize,
            ];
        } finally {
            sodium_memzero($dek);
            fclose($in);
            fclose($tempEnc);
        }
    }

    /**
     * Decrypt a DocumentVersion into an auto-cleaning temporary file handle.
     *
     * @return resource
     */
    public function decryptVersionToTempHandle(DocumentVersion $version)
    {
        return $this->decryptKeyToTempHandle(
            $version->storage_disk,
            $version->storage_key,
            $version->wrapped_dek,
            $version->kek_key_id,
        );
    }

    /**
     * Decrypt any envelope-encrypted storage object into an auto-cleaning temporary file handle.
     *
     * @return resource
     */
    public function decryptKeyToTempHandle(string $diskName, string $storageKey, string $wrappedDek, string $kekKeyId)
    {
        $dek = $this->unwrapDek($wrappedDek, $kekKeyId);
        $disk = Storage::disk($diskName);
        $encStream = $disk->readStream($storageKey);

        if ($encStream === null) {
            sodium_memzero($dek);
            throw new RuntimeException("Encrypted storage object missing: {$storageKey}");
        }

        $out = tmpfile();
        if ($out === false) {
            sodium_memzero($dek);
            fclose($encStream);
            throw new RuntimeException('Cannot allocate temporary stream for decryption.');
        }

        try {
            $header = $this->readExact($encStream, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if ($header === null) {
                throw new RuntimeException('Corrupted encrypted envelope header.');
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $dek);
            $sawFinal = false;

            while (! feof($encStream)) {
                $lenBytes = $this->readExact($encStream, 4);
                if ($lenBytes === null) {
                    break;
                }

                /** @var array{1: int} $unpacked */
                $unpacked = unpack('N', $lenBytes);
                $frameLen = $unpacked[1];
                if ($frameLen <= 0 || $frameLen > (self::CHUNK_SIZE + 1024)) {
                    throw new RuntimeException('Invalid encrypted frame length.');
                }

                $frame = $this->readExact($encStream, $frameLen);
                if ($frame === null) {
                    throw new RuntimeException('Truncated encrypted envelope frame.');
                }

                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $frame);
                if ($pulled === false) {
                    throw new RuntimeException('Authenticated decryption failed (tampered or wrong key).');
                }

                [$plaintext, $tag] = $pulled;
                if (! is_string($plaintext)) {
                    throw new RuntimeException('Unexpected non-string plaintext frame from envelope stream.');
                }
                fwrite($out, $plaintext);

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $sawFinal = true;
                    break;
                }
            }

            if (! $sawFinal) {
                throw new RuntimeException('Encrypted envelope truncated prior to FINAL tag.');
            }

            rewind($out);

            return $out;
        } catch (\Throwable $e) {
            fclose($out);
            throw $e;
        } finally {
            sodium_memzero($dek);
            fclose($encStream);
        }
    }

    private function wrapDek(string $dek, string $kekKeyId): string
    {
        $kek = $this->deriveMasterKek($kekKeyId);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dek, $kekKeyId, $nonce, $kek);
        sodium_memzero($kek);

        return base64_encode($nonce.$sealed);
    }

    private function unwrapDek(string $wrappedB64, string $kekKeyId): string
    {
        $raw = base64_decode($wrappedB64, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new RuntimeException('Malformed wrapped DEK.');
        }

        $nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $nonce = substr($raw, 0, $nonceLen);
        $ciphertext = substr($raw, $nonceLen);

        $kek = $this->deriveMasterKek($kekKeyId);
        $dek = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $kekKeyId, $nonce, $kek);
        sodium_memzero($kek);

        if ($dek === false) {
            throw new RuntimeException('Failed to unwrap document DEK with Master KEK.');
        }

        return $dek;
    }

    private function deriveMasterKek(string $kekKeyId): string
    {
        $explicitB64 = config()->string('betterdoc.security.master_kek_b64', '');
        if ($explicitB64 !== '') {
            $decoded = base64_decode($explicitB64, true);
            if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                return $decoded;
            }
        }

        $appKey = config()->string('app.key', '');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7), true) ?: $appKey;
        }

        return hash_hkdf('sha256', $appKey, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 'betterdoc-kek|'.$kekKeyId);
    }

    /**
     * @param  resource  $stream
     */
    private function readExact($stream, int $length): ?string
    {
        $buf = '';
        while (strlen($buf) < $length && ! feof($stream)) {
            $remaining = $length - strlen($buf);
            if ($remaining < 1) {
                break;
            }
            $chunk = fread($stream, $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
        }

        return strlen($buf) === $length ? $buf : null;
    }
}
