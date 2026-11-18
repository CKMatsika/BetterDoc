<?php

declare(strict_types=1);

namespace App\Services\PrintScan;

use App\Services\Pki\SignerBridgeService;
use App\Services\Security\BetterDocHttpClientGuard;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class PrintScanBridgeService
{
    public function __construct(
        private readonly SignerBridgeService $signerBridge,
        private readonly BetterDocHttpClientGuard $guard,
    ) {}

    public function watermarkPdf(string $pdfBytes, string $watermarkText, string $classificationBanner): string
    {
        if (config()->string('betterdoc.pki.signer_driver', 'local_pyhanko') === 'http') {
            $baseUrl = rtrim(config()->string('betterdoc.pki.signer_url', 'http://betterdoc-signer:8003'), '/');
            $resp = Http::withRequestMiddleware(
                fn (RequestInterface $req): RequestInterface => $this->guard->assertOutboundAllowed($req)
            )->timeout(30)->post("{$baseUrl}/print/watermark-pdf", [
                'pdf_b64' => base64_encode($pdfBytes),
                'watermark_text' => $watermarkText,
                'classification_banner' => $classificationBanner,
            ]);

            if (! $resp->successful()) {
                throw new RuntimeException('Watermark HTTP engine failed: '.$resp->body());
            }

            $b64 = $resp->json('watermarked_pdf_b64');
            if (! is_string($b64) || $b64 === '') {
                throw new RuntimeException('Watermark HTTP engine returned empty PDF.');
            }
            $decoded = base64_decode($b64, true);

            return is_string($decoded) ? $decoded : throw new RuntimeException('Invalid watermarked PDF base64.');
        }

        $inPath = tempnam(sys_get_temp_dir(), 'sent_wm_in_');
        $outPath = tempnam(sys_get_temp_dir(), 'sent_wm_out_');
        if ($inPath === false || $outPath === false) {
            throw new RuntimeException('Unable to allocate temporary PDF watermark files.');
        }

        try {
            file_put_contents($inPath, $pdfBytes);
            $this->signerBridge->execute('watermark_pdf', [
                'input_pdf_path' => $inPath,
                'output_pdf_path' => $outPath,
                'watermark_text' => $watermarkText,
                'classification_banner' => $classificationBanner,
            ]);
            $result = file_get_contents($outPath);

            return is_string($result) && $result !== '' ? $result : throw new RuntimeException('Watermark output missing.');
        } finally {
            @unlink($inPath);
            @unlink($outPath);
        }
    }

    public function generateSeparatorPdf(
        string $docType,
        string $title,
        string $cabinetCode,
        string $classificationName,
    ): string {
        if (config()->string('betterdoc.pki.signer_driver', 'local_pyhanko') === 'http') {
            $baseUrl = rtrim(config()->string('betterdoc.pki.signer_url', 'http://betterdoc-signer:8003'), '/');
            $resp = Http::withRequestMiddleware(
                fn (RequestInterface $req): RequestInterface => $this->guard->assertOutboundAllowed($req)
            )->timeout(30)->post("{$baseUrl}/scan/separator-pdf", [
                'doc_type' => $docType,
                'title' => $title,
                'cabinet_code' => $cabinetCode,
                'classification_name' => $classificationName,
            ]);

            if ($resp->successful()) {
                $b64 = $resp->json('pdf_b64');
                if (is_string($b64) && $b64 !== '') {
                    $decoded = base64_decode($b64, true);
                    if (is_string($decoded)) {
                        return $decoded;
                    }
                }
            }
        }

        $outPath = tempnam(sys_get_temp_dir(), 'sent_sep_');
        if ($outPath === false) {
            throw new RuntimeException('Unable to allocate separator PDF file.');
        }

        try {
            $this->signerBridge->execute('generate_separator_pdf', [
                'output_pdf_path' => $outPath,
                'doc_type' => $docType,
                'title' => $title,
                'cabinet_code' => $cabinetCode,
                'classification_name' => $classificationName,
            ]);
            $bytes = file_get_contents($outPath);

            return is_string($bytes) && $bytes !== '' ? $bytes : throw new RuntimeException('Separator PDF generation failed.');
        } finally {
            @unlink($outPath);
        }
    }

    /**
     * @return array{total_input_pages: int, separators_found: int, segments: list<array{pdf_bytes: string, page_count: int, meta: array<string, string>}>}
     */
    public function splitPdfBySeparators(string $pdfBytes): array
    {
        $inPath = tempnam(sys_get_temp_dir(), 'sent_split_');
        if ($inPath === false) {
            throw new RuntimeException('Unable to allocate batch split temp file.');
        }

        try {
            file_put_contents($inPath, $pdfBytes);
            $raw = $this->signerBridge->execute('split_by_separators', [
                'input_pdf_path' => $inPath,
            ]);

            $segments = [];
            $rawSegs = $raw['segments'] ?? [];
            if (is_array($rawSegs)) {
                foreach ($rawSegs as $seg) {
                    if (! is_array($seg)) {
                        continue;
                    }
                    $b64 = is_string($seg['pdf_b64'] ?? null) ? $seg['pdf_b64'] : '';
                    $decoded = $b64 !== '' ? base64_decode($b64, true) : false;
                    if (! is_string($decoded) || $decoded === '') {
                        continue;
                    }

                    /** @var array<string, string> $meta */
                    $meta = [];
                    if (is_array($seg['meta'] ?? null)) {
                        foreach ($seg['meta'] as $k => $v) {
                            if (is_string($k) && is_scalar($v)) {
                                $meta[$k] = (string) $v;
                            }
                        }
                    }

                    $segments[] = [
                        'pdf_bytes' => $decoded,
                        'page_count' => is_numeric($seg['page_count'] ?? null) ? (int) $seg['page_count'] : 1,
                        'meta' => $meta,
                    ];
                }
            }

            return [
                'total_input_pages' => is_numeric($raw['total_input_pages'] ?? null) ? (int) $raw['total_input_pages'] : 1,
                'separators_found' => is_numeric($raw['separators_found'] ?? null) ? (int) $raw['separators_found'] : 0,
                'segments' => $segments,
            ];
        } finally {
            @unlink($inPath);
        }
    }

    /**
     * @param  list<string>  $imagePaths
     * @return array{pdf_bytes: string, page_count: int, deskew_applied: bool}
     */
    public function deskewImagesToPdf(array $imagePaths): array
    {
        $raw = $this->signerBridge->execute('deskew_images_pdf', [
            'image_paths' => $imagePaths,
        ]);

        $b64 = is_string($raw['pdf_b64'] ?? null) ? $raw['pdf_b64'] : '';
        $decoded = $b64 !== '' ? base64_decode($b64, true) : false;
        if (! is_string($decoded) || $decoded === '') {
            throw new RuntimeException('Image deskew & crop conversion failed.');
        }

        return [
            'pdf_bytes' => $decoded,
            'page_count' => is_numeric($raw['page_count'] ?? null) ? (int) $raw['page_count'] : count($imagePaths),
            'deskew_applied' => (bool) ($raw['deskew_applied'] ?? false),
        ];
    }
}
