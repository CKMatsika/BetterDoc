<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\OcrStatus;
use Illuminate\Support\Facades\Http;
use Smalot\PdfParser\Parser;

class OcrAndConversionService
{
    /**
     * Normalize non-PDF text/office uploads to PDF and extract searchable OCR text + page count.
     *
     * @return array{pdf_path: string, mime_type: string, status: OcrStatus, page_count: int, pdfa_compliant: bool, extracted_text: string}
     */
    public function processToSearchablePdf(string $sourcePath, string $mimeType, string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $workingPdf = $sourcePath;
        $resultingMime = $mimeType;

        if ($ext !== 'pdf' && $mimeType !== 'application/pdf') {
            if (config()->boolean('betterdoc.gotenberg.enabled', false)) {
                $converted = $this->convertViaGotenberg($sourcePath, $filename);
                if ($converted !== null) {
                    $workingPdf = $converted;
                    $resultingMime = 'application/pdf';
                }
            } elseif (in_array($ext, ['txt', 'md', 'csv', 'log'], true)) {
                $textContent = (string) (file_get_contents($sourcePath) ?: '');
                $workingPdf = $this->buildMinimalPdfFromText($textContent);
                $resultingMime = 'application/pdf';

                return [
                    'pdf_path' => $workingPdf,
                    'mime_type' => $resultingMime,
                    'status' => OcrStatus::Completed,
                    'page_count' => 1,
                    'pdfa_compliant' => false,
                    'extracted_text' => mb_substr($textContent, 0, 500000),
                ];
            }
        }

        if ($resultingMime === 'application/pdf' && config()->boolean('betterdoc.ocr.enabled', false)) {
            $ocrResult = $this->runRemoteOcr($workingPdf);
            if ($ocrResult !== null) {
                return [
                    'pdf_path' => $workingPdf,
                    'mime_type' => 'application/pdf',
                    'status' => OcrStatus::Completed,
                    'page_count' => $ocrResult['page_count'],
                    'pdfa_compliant' => $ocrResult['pdfa_compliant'],
                    'extracted_text' => $ocrResult['extracted_text'],
                ];
            }
        }

        if ($resultingMime === 'application/pdf' || str_starts_with((string) file_get_contents($workingPdf, false, null, 0, 5), '%PDF-')) {
            [$text, $pages] = $this->extractPdfLocally($workingPdf);

            return [
                'pdf_path' => $workingPdf,
                'mime_type' => 'application/pdf',
                'status' => OcrStatus::Completed,
                'page_count' => max(1, $pages),
                'pdfa_compliant' => false,
                'extracted_text' => $text,
            ];
        }

        return [
            'pdf_path' => $workingPdf,
            'mime_type' => $resultingMime,
            'status' => OcrStatus::Completed,
            'page_count' => 1,
            'pdfa_compliant' => false,
            'extracted_text' => '',
        ];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function extractPdfLocally(string $pdfPath): array
    {
        try {
            $parser = new Parser;
            $pdf = $parser->parseFile($pdfPath);
            $text = trim($pdf->getText());
            $pages = count($pdf->getPages());

            return [$text, max(1, $pages)];
        } catch (\Throwable) {
            // Fallback raw PDF text literal extraction for minimal hand-crafted PDFs
            $raw = (string) (file_get_contents($pdfPath) ?: '');
            preg_match_all('/\(([^()]{2,})\)\s*Tj/s', $raw, $matches);
            $joined = implode(' ', $matches[1]);
            $pageCount = max(1, substr_count($raw, '/Type /Page'));

            return [trim($joined), $pageCount];
        }
    }

    private function convertViaGotenberg(string $sourcePath, string $filename): ?string
    {
        $url = rtrim(config()->string('betterdoc.gotenberg.url', 'http://gotenberg:3000'), '/').'/forms/libreoffice/convert';
        $bytes = file_get_contents($sourcePath);
        if ($bytes === false) {
            return null;
        }

        $response = Http::timeout(60)
            ->attach('files', $bytes, $filename)
            ->post($url, ['pdfa' => 'PDF/A-2b']);

        if (! $response->successful()) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'sent_got_');
        if ($tmp === false) {
            return null;
        }
        file_put_contents($tmp, $response->body());

        return $tmp;
    }

    /**
     * @return array{page_count: int, pdfa_compliant: bool, extracted_text: string}|null
     */
    private function runRemoteOcr(string $pdfPath): ?array
    {
        $url = rtrim(config()->string('betterdoc.ocr.url', 'http://betterdoc-ocr:8002'), '/').'/ocr-pdfa';
        $bytes = file_get_contents($pdfPath);
        if ($bytes === false) {
            return null;
        }

        $response = Http::timeout(120)
            ->attach('file', $bytes, 'document.pdf')
            ->post($url, [
                'languages' => config()->string('betterdoc.ocr.languages', 'eng'),
            ]);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data)) {
            return null;
        }

        return [
            'page_count' => is_numeric($data['page_count'] ?? null) ? (int) $data['page_count'] : 1,
            'pdfa_compliant' => (bool) ($data['pdfa_compliant'] ?? false),
            'extracted_text' => is_string($data['extracted_text'] ?? null) ? $data['extracted_text'] : '',
        ];
    }

    private function buildMinimalPdfFromText(string $text): string
    {
        $safeText = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], substr($text, 0, 2000));
        $lines = explode("\n", str_replace("\r\n", "\n", $safeText));
        $stream = "BT\n/F1 11 Tf\n50 760 Td\n14 TL\n";
        foreach (array_slice($lines, 0, 45) as $line) {
            $stream .= "({$line}) Tj T*\n";
        }
        $stream .= "ET\n";

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
            "4 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n{$stream}endstream\nendobj\n",
            "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj;
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF\n";

        $tmp = tempnam(sys_get_temp_dir(), 'sent_pdf_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to allocate temp PDF file.');
        }
        file_put_contents($tmp, $pdf);

        return $tmp;
    }
}
