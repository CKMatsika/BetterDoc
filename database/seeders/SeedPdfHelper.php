<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AvStatus;
use App\Enums\CdrStatus;
use App\Enums\OcrStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Crypto\EnvelopeEncryptionService;
use Illuminate\Support\Str;

class SeedPdfHelper
{
    /**
     * Generate a minimal valid single-page PDF 1.4 record, encrypt it into the vault,
     * and attach it as v1.0 when the seeded document does not yet have a current version.
     */
    public static function attachInitialPdfIfMissing(
        Tenant $tenant,
        Document $doc,
        string $docNumber,
        string $title,
        string $summary,
        int $rank,
        User $owner,
    ): void {
        if ($doc->current_version_id !== null) {
            return;
        }

        $pdfText = "{$docNumber} — {$title}\n\n{$summary}\nClassification Rank: {$rank}";
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $pdfText);
        $lines = explode("\n", $safe);
        $stream = "BT\n/F1 11 Tf\n50 760 Td\n14 TL\n";
        foreach ($lines as $line) {
            $stream .= "({$line}) Tj T*\n";
        }
        $stream .= "ET\n";

        $objs = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
            "4 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n{$stream}endstream\nendobj\n",
            "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];

        $pdfBytes = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objs as $o) {
            $offsets[] = strlen($pdfBytes);
            $pdfBytes .= $o;
        }
        $xref = strlen($pdfBytes);
        $pdfBytes .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdfBytes .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdfBytes .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        $tmpPdf = tempnam(sys_get_temp_dir(), 'sentinel_seed_');
        if ($tmpPdf === false) {
            return;
        }

        file_put_contents($tmpPdf, $pdfBytes);
        $verId = (string) Str::uuid7();
        $storageKey = "tenants/{$tenant->id}/docs/{$doc->id}/v1_0_{$verId}.enc";
        $enc = app(EnvelopeEncryptionService::class)->encryptFileToDisk($tmpPdf, 'sentinel_vault', $storageKey);
        @unlink($tmpPdf);

        $version = new DocumentVersion([
            'tenant_id' => $tenant->id,
            'document_id' => $doc->id,
            'version_major' => 1,
            'version_minor' => 0,
            'is_current' => true,
            'storage_disk' => 'sentinel_vault',
            'storage_key' => $storageKey,
            'original_filename' => str_replace('/', '_', $docNumber).'.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => strlen($pdfBytes),
            'sha256_hash' => hash('sha256', $pdfBytes),
            'wrapped_dek' => $enc['wrapped_dek'],
            'kek_key_id' => $enc['kek_key_id'],
            'av_status' => AvStatus::Clean,
            'cdr_status' => CdrStatus::Sanitized,
            'ocr_status' => OcrStatus::Completed,
            'pdfa_compliant' => true,
            'page_count' => 1,
            'extracted_text' => $pdfText,
            'version_note' => 'Initial seeded official PDF/A record',
            'created_by' => $owner->id,
        ]);
        $version->id = $verId;
        $version->save();

        $doc->update([
            'current_version_id' => $version->id,
            'ocr_text' => $pdfText,
        ]);
    }
}
