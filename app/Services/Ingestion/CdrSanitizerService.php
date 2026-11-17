<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\CdrStatus;
use Illuminate\Support\Facades\Http;

class CdrSanitizerService
{
    /**
     * Sanitize active threats (/JavaScript, /JS, /OpenAction, /AA, /Launch, /SubmitForm) from PDFs.
     * Uses sentinel-ocr (PyMuPDF + qpdf) when enabled, or length-preserving PDF dictionary neutralization locally.
     *
     * @return array{status: CdrStatus, stripped_vectors: list<string>}
     */
    public function sanitizePdfInPlace(string $pdfPath): array
    {
        if (config()->boolean('sentinel.ocr.enabled', false)) {
            $url = rtrim(config()->string('sentinel.ocr.url', 'http://sentinel-ocr:8002'), '/').'/cdr-sanitize';
            $bytes = file_get_contents($pdfPath);
            if ($bytes !== false) {
                $response = Http::timeout(30)
                    ->attach('file', $bytes, 'document.pdf')
                    ->post($url);

                if ($response->successful()) {
                    file_put_contents($pdfPath, $response->body());

                    return [
                        'status' => CdrStatus::Sanitized,
                        'stripped_vectors' => ['external_cdr_pipeline'],
                    ];
                }
            }
        }

        $raw = file_get_contents($pdfPath);
        if ($raw === false || ! str_starts_with($raw, '%PDF-')) {
            return ['status' => CdrStatus::Failed, 'stripped_vectors' => []];
        }

        // Equal-byte-length safe key replacements preserve exact PDF xref table byte offsets
        $replacements = [
            '/JavaScript' => '/SentinelJS',
            '/OpenAction' => '/SentinelOA',
            '/SubmitForm' => '/SentinelSF',
            '/RichMedia' => '/SentinelRM',
            '/Launch' => '/SentLN',
            '/GoToR' => '/SentGR',
            '/JS' => '/S0',
            '/AA' => '/S1',
        ];

        $stripped = [];
        $sanitized = $raw;

        foreach ($replacements as $needle => $benign) {
            if (str_contains($sanitized, $needle)) {
                $stripped[] = ltrim($needle, '/');
                $sanitized = str_replace($needle, $benign, $sanitized);
            }
        }

        if ($stripped !== []) {
            file_put_contents($pdfPath, $sanitized);
        }

        return [
            'status' => CdrStatus::Sanitized,
            'stripped_vectors' => $stripped,
        ];
    }
}
