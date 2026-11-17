<?php

declare(strict_types=1);

use App\Enums\UploadStatus;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\Crypto\EnvelopeEncryptionService;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('resumes interrupted chunked upload, verifies SHA-256, and stores envelope-encrypted ciphertext', function (): void {
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    $dbContext = app(DbSecurityContext::class);

    $cabinet = $dbContext->runAs($wgCdr, static fn (): Cabinet => Cabinet::query()->where('name', 'Operational Signals Registry')->firstOrFail());
    $level = ClearanceLevel::query()->where('tenant_id', $wgCdr->tenant_id)->where('rank', 2)->firstOrFail();

    $stream = "BT\n/F1 12 Tf\n72 720 Td\n(Classified Satellite Telemetry Report Alpha-99) Tj\nET\n";
    $objs = [
        "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
        "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
        "4 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n{$stream}endstream\nendobj\n",
        "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objs as $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= $obj;
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($i = 1; $i <= 5; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    $pdf .= str_repeat('%PADDING_BLOCK_', 12000); // ~180KB multi-chunk PDF

    $chunkSize = 65536; // 64KB chunks -> 3 chunks
    $chunks = str_split($pdf, $chunkSize);
    $expectedSha = hash('sha256', $pdf);

    $this->signIn($wgCdr);

    // 1. Initiate session
    $initRes = $this->postJson('/uploads/init', [
        'filename' => 'telemetry_alpha.pdf',
        'mime_type' => 'application/pdf',
        'total_size' => strlen($pdf),
        'sha256_expected' => $expectedSha,
        'chunk_size' => $chunkSize,
    ])->assertCreated();

    $sessionId = (string) $initRes->json('session_id');
    expect($initRes->json('total_chunks'))->toBe(count($chunks));

    // 2. Upload first chunk ONLY, simulating power outage / connection drop
    $this->putChunk("/uploads/{$sessionId}/chunks/0", $chunks[0], hash('sha256', $chunks[0]))->assertOk();

    // 3. Resume check returns exact missing parts [1, 2]
    $statusRes = $this->getJson("/uploads/{$sessionId}/status")->assertOk();
    expect($statusRes->json('missing_parts'))->toBe([1, 2])
        ->and($statusRes->json('status'))->toBe(UploadStatus::Uploading->value);

    // 4. Resume remaining chunks
    for ($i = 1; $i < count($chunks); $i++) {
        $this->putChunk("/uploads/{$sessionId}/chunks/{$i}", $chunks[$i], hash('sha256', $chunks[$i]))->assertOk();
    }

    // 5. Complete upload -> Scan + CDR + OCR + Envelope Encryption
    $completeRes = $this->postJson("/uploads/{$sessionId}/complete", [
        'cabinet_id' => $cabinet->id,
        'classification_level_id' => $level->id,
        'doc_number' => 'AFZ/SAT/2026/777',
        'title' => 'Satellite Telemetry Alpha-99',
    ])->assertCreated();

    $docId = (string) $completeRes->json('document_id');
    $verId = (string) $completeRes->json('version_id');

    $dbContext->runAs($wgCdr, function () use ($docId, $verId, $expectedSha): void {
        /** @var DocumentVersion $ver */
        $ver = DocumentVersion::query()->findOrFail($verId);
        $doc = Document::query()->findOrFail($docId);

        expect($ver->sha256_hash)->toBe($expectedSha)
            ->and($doc->ocr_text)->toContain('Telemetry')
            ->and(Storage::disk('sentinel_vault')->exists($ver->storage_key))->toBeTrue();

        // Storage disk contains encrypted ciphertext, NEVER plaintext PDF bytes
        $rawCipher = Storage::disk('sentinel_vault')->get($ver->storage_key);
        expect($rawCipher)->not->toContain('%PDF-')
            ->and($rawCipher)->not->toContain('Telemetry');
    });

    // 6. Verify 200MB multi-part session arithmetic (40 x 5MB parts)
    $session200Mb = $dbContext->runAs($wgCdr, fn () => UploadSession::query()->create([
        'tenant_id' => $wgCdr->tenant_id,
        'user_id' => $wgCdr->id,
        'filename' => 'large_200mb_imagery.pdf',
        'mime_type' => 'application/pdf',
        'total_size' => 209715200,
        'chunk_size' => 5242880,
        'total_chunks' => 40,
        'uploaded_parts_json' => ['0' => ['size' => 5242880, 'sha256' => str_repeat('a', 64)]],
        'storage_temp_prefix' => 'temp/200mb',
        'status' => UploadStatus::Uploading,
        'expires_at' => now()->addHours(24),
    ]));

    $res200 = $this->getJson("/uploads/{$session200Mb->id}/status")->assertOk();
    expect($res200->json('total_chunks'))->toBe(40)
        ->and(count($res200->json('missing_parts')))->toBe(39);
});

it('streams multi-megabyte files via EnvelopeEncryptionService with zero corruption', function (): void {
    $crypto = app(EnvelopeEncryptionService::class);
    $src = tempnam(sys_get_temp_dir(), 'enc_test_');
    $payload = random_bytes(256 * 1024); // 256KB random binary across 4 secretstream blocks
    file_put_contents($src, $payload);

    $key = 'test/stream_roundtrip.enc';
    $meta = $crypto->encryptFileToDisk($src, 'sentinel_vault', $key);
    @unlink($src);

    $version = new DocumentVersion([
        'storage_disk' => 'sentinel_vault',
        'storage_key' => $key,
        'wrapped_dek' => $meta['wrapped_dek'],
        'kek_key_id' => $meta['kek_key_id'],
    ]);

    $handle = $crypto->decryptVersionToTempHandle($version);
    $decrypted = stream_get_contents($handle);
    fclose($handle);
    Storage::disk('sentinel_vault')->delete($key);

    expect($decrypted)->toBe($payload);
});
