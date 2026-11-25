<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\ZimraFiscalStatus;
use App\Models\ApInvoice;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Audit\ImmutableAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class ZimbabweComplianceService
{
    public const int ZIMRA_WHT_RATE_BPS = 3000; // 30.00% statutory withholding tax without valid ITF263

    public function __construct(
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * Evaluate a Zimbabwean vendor's statutory compliance pack:
     * - ZIMRA ITF263 Tax Clearance Certificate (controls mandatory 30% WHT)
     * - PRAZ Supplier Registration (required for parastatals, ministries, and regulated tenders)
     * - NSSA Clearance Certificate
     * - ZIMRA TIN & CR6/CR14 Corporate Documents
     *
     * @return array{
     *     itf263_status: string,
     *     itf263_valid: bool,
     *     wht_required: bool,
     *     wht_rate_bps: int,
     *     wht_rate_percent: float,
     *     days_to_itf263_expiry: int|null,
     *     completeness_percent: int,
     *     checklist: list<array{key: string, label: string, required_for: string, status: string, reference: string|null, expires_at: string|null, document_id: string|null}>
     * }
     */
    public function evaluateVendorCompliance(Vendor $vendor, ?Carbon $asOf = null): array
    {
        $today = ($asOf ?? now())->startOfDay();
        $docs = is_array($vendor->compliance_docs_json) ? $vendor->compliance_docs_json : [];

        $itfExpiry = $vendor->itf263_expires_at;
        $hasItfRef = trim((string) $vendor->itf263_certificate_no) !== '';
        $daysToItf = $itfExpiry !== null ? (int) $today->diffInDays($itfExpiry->copy()->startOfDay(), false) : null;

        if (! $hasItfRef || $itfExpiry === null) {
            $itfStatus = 'missing';
            $itfValid = false;
        } elseif ($daysToItf < 0) {
            $itfStatus = 'expired';
            $itfValid = false;
        } elseif ($daysToItf <= 30) {
            $itfStatus = 'expiring_soon';
            $itfValid = true;
        } else {
            $itfStatus = 'valid';
            $itfValid = true;
        }

        $prazStatus = $this->datedItemStatus($vendor->praz_registration_no, $vendor->praz_expires_at, $today);
        $nssaStatus = $this->datedItemStatus($vendor->nssa_clearance_no, $vendor->nssa_expires_at, $today);
        $tinStatus = trim((string) $vendor->tax_id_tin) !== '' ? 'valid' : 'missing';

        $cr6DocId = is_array($docs['cr6'] ?? null) && is_string($docs['cr6']['document_id'] ?? null)
            ? $docs['cr6']['document_id']
            : null;
        $cr14DocId = is_array($docs['cr14'] ?? null) && is_string($docs['cr14']['document_id'] ?? null)
            ? $docs['cr14']['document_id']
            : null;

        $checklist = [
            [
                'key' => 'itf263',
                'label' => 'ZIMRA ITF263 Tax Clearance',
                'required_for' => '30% WHT Exemption',
                'status' => $itfStatus,
                'reference' => $vendor->itf263_certificate_no,
                'expires_at' => $vendor->itf263_expires_at?->toDateString(),
                'document_id' => $vendor->itf263_document_id,
            ],
            [
                'key' => 'tin',
                'label' => 'ZIMRA TIN / VAT Registration',
                'required_for' => 'Fiscal Invoicing',
                'status' => $tinStatus,
                'reference' => $vendor->tax_id_tin,
                'expires_at' => null,
                'document_id' => null,
            ],
            [
                'key' => 'praz',
                'label' => 'PRAZ Supplier Registration',
                'required_for' => 'Public / Tender Procurement',
                'status' => $prazStatus,
                'reference' => $vendor->praz_registration_no,
                'expires_at' => $vendor->praz_expires_at?->toDateString(),
                'document_id' => is_array($docs['praz'] ?? null) && is_string($docs['praz']['document_id'] ?? null) ? $docs['praz']['document_id'] : null,
            ],
            [
                'key' => 'nssa',
                'label' => 'NSSA Clearance Certificate',
                'required_for' => 'Statutory Labour Compliance',
                'status' => $nssaStatus,
                'reference' => $vendor->nssa_clearance_no,
                'expires_at' => $vendor->nssa_expires_at?->toDateString(),
                'document_id' => is_array($docs['nssa'] ?? null) && is_string($docs['nssa']['document_id'] ?? null) ? $docs['nssa']['document_id'] : null,
            ],
            [
                'key' => 'cr6',
                'label' => 'Company CR6 / Directors Form',
                'required_for' => 'KYC & Vendor Onboarding',
                'status' => $cr6DocId !== null ? 'valid' : 'missing',
                'reference' => is_array($docs['cr6'] ?? null) && is_string($docs['cr6']['reference'] ?? null) ? $docs['cr6']['reference'] : null,
                'expires_at' => null,
                'document_id' => $cr6DocId,
            ],
            [
                'key' => 'cr14',
                'label' => 'Certificate of Incorporation',
                'required_for' => 'KYC & Vendor Onboarding',
                'status' => $cr14DocId !== null ? 'valid' : 'missing',
                'reference' => is_array($docs['cr14'] ?? null) && is_string($docs['cr14']['reference'] ?? null) ? $docs['cr14']['reference'] : null,
                'expires_at' => null,
                'document_id' => $cr14DocId,
            ],
        ];

        $passedCount = 0;
        foreach ($checklist as $item) {
            if (in_array($item['status'], ['valid', 'expiring_soon'], true)) {
                $passedCount++;
            }
        }

        $whtRequired = ! $itfValid;

        return [
            'itf263_status' => $itfStatus,
            'itf263_valid' => $itfValid,
            'wht_required' => $whtRequired,
            'wht_rate_bps' => $whtRequired ? self::ZIMRA_WHT_RATE_BPS : 0,
            'wht_rate_percent' => $whtRequired ? 30.0 : 0.0,
            'days_to_itf263_expiry' => $daysToItf,
            'completeness_percent' => (int) round(($passedCount / count($checklist)) * 100),
            'checklist' => $checklist,
        ];
    }

    /**
     * Update a vendor's ZIMRA ITF263, PRAZ, NSSA, and KYC compliance records.
     *
     * @param  array{
     *     itf263_certificate_no?: string|null,
     *     itf263_expires_at?: string|null,
     *     itf263_document_id?: string|null,
     *     praz_registration_no?: string|null,
     *     praz_expires_at?: string|null,
     *     nssa_clearance_no?: string|null,
     *     nssa_expires_at?: string|null,
     *     cr6_document_id?: string|null,
     *     cr14_document_id?: string|null
     * }  $data
     */
    public function updateVendorCompliance(
        Vendor $vendor,
        User $actor,
        array $data,
        ?Request $request = null,
    ): Vendor {
        if ($vendor->tenant_id !== $actor->tenant_id) {
            throw new RuntimeException('Cross-tenant vendor compliance update forbidden.');
        }

        $docs = is_array($vendor->compliance_docs_json) ? $vendor->compliance_docs_json : [];
        if (array_key_exists('cr6_document_id', $data)) {
            $docs['cr6'] = ['document_id' => $data['cr6_document_id'] ?: null];
        }
        if (array_key_exists('cr14_document_id', $data)) {
            $docs['cr14'] = ['document_id' => $data['cr14_document_id'] ?: null];
        }

        $vendor->update([
            'itf263_certificate_no' => $data['itf263_certificate_no'] ?? $vendor->itf263_certificate_no,
            'itf263_expires_at' => $data['itf263_expires_at'] ?? $vendor->itf263_expires_at,
            'itf263_document_id' => $data['itf263_document_id'] ?? $vendor->itf263_document_id,
            'praz_registration_no' => $data['praz_registration_no'] ?? $vendor->praz_registration_no,
            'praz_expires_at' => $data['praz_expires_at'] ?? $vendor->praz_expires_at,
            'nssa_clearance_no' => $data['nssa_clearance_no'] ?? $vendor->nssa_clearance_no,
            'nssa_expires_at' => $data['nssa_expires_at'] ?? $vendor->nssa_expires_at,
            'compliance_docs_json' => $docs,
        ]);

        $summary = $this->evaluateVendorCompliance($vendor->refresh());

        $this->audit->log(
            tenantId: $vendor->tenant_id,
            eventType: 'finance.vendor_compliance_updated',
            resourceType: 'vendor',
            resourceId: $vendor->id,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            after: [
                'vendor_code' => $vendor->code,
                'itf263_status' => $summary['itf263_status'],
                'wht_required' => $summary['wht_required'],
                'completeness_percent' => $summary['completeness_percent'],
            ],
            request: $request,
        );

        return $vendor;
    }

    /**
     * Record a daily RBZ Interbank / official exchange rate (units of quote currency per 1 USD).
     */
    public function recordExchangeRate(
        User $actor,
        string $quoteCurrency,
        float $rate,
        string $effectiveDate,
        string $source = 'RBZ Interbank',
        ?Request $request = null,
    ): ExchangeRate {
        $currency = strtoupper(trim($quoteCurrency));
        if ($rate <= 0.0) {
            throw new RuntimeException('Exchange rate must be greater than zero.');
        }

        /** @var ExchangeRate $record */
        $record = ExchangeRate::query()->updateOrCreate(
            [
                'tenant_id' => $actor->tenant_id,
                'base_currency' => 'USD',
                'quote_currency' => $currency,
                'effective_date' => $effectiveDate,
            ],
            [
                'rate' => $rate,
                'source' => trim($source) !== '' ? trim($source) : 'RBZ Interbank',
                'recorded_by' => $actor->id,
            ],
        );

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'finance.exchange_rate_recorded',
            resourceType: 'exchange_rate',
            resourceId: $record->id,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            after: [
                'pair' => "USD/{$currency}",
                'rate' => $rate,
                'effective_date' => $effectiveDate,
                'source' => $record->source,
            ],
            request: $request,
        );

        return $record;
    }

    /**
     * Resolve units of $currency per 1 USD on or before $asOfDate (e.g. USD => 1.0, ZWG => 26.50).
     */
    public function resolveExchangeRateToUsd(string $tenantId, string $currency, string $asOfDate): float
    {
        $cur = strtoupper(trim($currency));
        if ($cur === '' || $cur === 'USD') {
            return 1.0;
        }

        /** @var ExchangeRate|null $latest */
        $latest = ExchangeRate::query()
            ->where('tenant_id', $tenantId)
            ->where('base_currency', 'USD')
            ->where('quote_currency', $cur)
            ->whereDate('effective_date', '<=', $asOfDate)
            ->orderByDesc('effective_date')
            ->first();

        if ($latest !== null && $latest->rate > 0.0) {
            return $latest->rate;
        }

        return match ($cur) {
            'ZWG' => 26.50,
            'ZWL' => 26.50,
            'ZAR' => 18.50,
            'EUR' => 0.92,
            'GBP' => 0.79,
            default => 1.0,
        };
    }

    /**
     * Compute ZIMRA 30% WHT and USD equivalent for an invoice header.
     *
     * @return array{
     *     wht_applicable: bool,
     *     wht_rate_bps: int,
     *     wht_cents: int,
     *     net_payable_cents: int,
     *     exchange_rate_to_usd: float,
     *     usd_equivalent_cents: int,
     *     itf263_status: string
     * }
     */
    public function computeInvoiceTaxAndFx(
        Vendor $vendor,
        string $currency,
        string $invoiceDate,
        int $subtotalCents,
        int $totalCents,
    ): array {
        $compliance = $this->evaluateVendorCompliance($vendor, Carbon::parse($invoiceDate));
        $whtApplicable = $compliance['wht_required'];
        $whtRateBps = $compliance['wht_rate_bps'];
        $whtCents = $whtApplicable ? (int) round(($subtotalCents * $whtRateBps) / 10000) : 0;
        $netPayableCents = max(0, $totalCents - $whtCents);

        $rate = $this->resolveExchangeRateToUsd($vendor->tenant_id, $currency, $invoiceDate);
        $usdEquivalentCents = $rate > 0.0 ? (int) round($totalCents / $rate) : $totalCents;

        return [
            'wht_applicable' => $whtApplicable,
            'wht_rate_bps' => $whtRateBps,
            'wht_cents' => $whtCents,
            'net_payable_cents' => $netPayableCents,
            'exchange_rate_to_usd' => $rate,
            'usd_equivalent_cents' => $usdEquivalentCents,
            'itf263_status' => $compliance['itf263_status'],
        ];
    }

    /**
     * Parse and verify a ZIMRA FDMS fiscal receipt QR URL or structured payload against an AP Invoice.
     *
     * Supports standard ZIMRA FDMS QR URLs:
     *   https://fdms.zimra.co.zw/{deviceId10}{ddMMyyyy}{receiptGlobalNo10}{qrData16}
     *   https://fdmstest.zimra.co.zw/...
     * or structured key=value payloads (device_id=...|receipt_no=...|verification_code=...).
     */
    public function verifyFdmsQrPayload(
        ApInvoice $invoice,
        string $qrPayload,
        User $actor,
        ?Request $request = null,
    ): ApInvoice {
        if ($invoice->tenant_id !== $actor->tenant_id) {
            throw new RuntimeException('Cross-tenant FDMS QR verification forbidden.');
        }

        $trimmed = trim($qrPayload);
        if ($trimmed === '') {
            throw new RuntimeException('FDMS QR payload cannot be empty.');
        }

        $parsed = $this->parseFdmsQr($trimmed);

        // Check for duplicate ZIMRA receipt global number across tenant (fraud / double-claiming guard)
        $duplicate = ApInvoice::query()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('id', '!=', $invoice->id)
            ->where('zimra_receipt_no', $parsed['receipt_number'])
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException("Duplicate ZIMRA FDMS receipt '{$parsed['receipt_number']}' already linked to invoice {$duplicate->invoice_number}.");
        }

        $verification = [
            'verified' => $parsed['valid'],
            'mode' => $parsed['mode'],
            'device_id' => $parsed['device_id'],
            'receipt_date' => $parsed['receipt_date'],
            'receipt_global_no' => $parsed['receipt_global_no'],
            'verification_code' => $parsed['verification_code'],
            'raw_payload' => mb_substr($trimmed, 0, 500),
            'verified_at' => now('UTC')->toIso8601String(),
            'verified_by' => $actor->id,
        ];

        $invoice->update([
            'zimra_qr_payload' => mb_substr($trimmed, 0, 500),
            'zimra_receipt_no' => $parsed['receipt_number'],
            'zimra_fiscal_status' => $parsed['valid'] ? ZimraFiscalStatus::Fiscalized : ZimraFiscalStatus::Failed,
            'fdms_qr_verified' => $parsed['valid'],
            'fdms_verification_json' => $verification,
        ]);

        $this->audit->log(
            tenantId: $invoice->tenant_id,
            eventType: $parsed['valid'] ? 'finance.zimra_fdms_qr_verified' : 'finance.zimra_fdms_qr_invalid',
            resourceType: 'ap_invoice',
            resourceId: $invoice->id,
            actor: $actor,
            classificationRank: $invoice->document->classification_rank ?? 1,
            after: [
                'invoice_number' => $invoice->invoice_number,
                'zimra_receipt_no' => $parsed['receipt_number'],
                'device_id' => $parsed['device_id'],
                'valid' => $parsed['valid'],
            ],
            request: $request,
        );

        return $invoice->refresh();
    }

    private function datedItemStatus(?string $reference, ?Carbon $expiresAt, Carbon $today): string
    {
        if (trim((string) $reference) === '' || $expiresAt === null) {
            return 'missing';
        }

        $days = (int) $today->diffInDays($expiresAt->copy()->startOfDay(), false);
        if ($days < 0) {
            return 'expired';
        }
        if ($days <= 30) {
            return 'expiring_soon';
        }

        return 'valid';
    }

    /**
     * @return array{valid: bool, mode: string, device_id: string, receipt_date: string|null, receipt_global_no: string, verification_code: string, receipt_number: string}
     */
    private function parseFdmsQr(string $payload): array
    {
        // Format 1: Standard ZIMRA FDMS URL: https://fdms(test).zimra.co.zw/{10-digit deviceId}{8-digit ddMMyyyy}{10-digit globalNo}{16-char code}
        if (preg_match('#^https?://(?:fdms|fdmstest)\.zimra\.co\.zw/([0-9]{10})([0-9]{8})([0-9]{10})([A-Za-z0-9]{16})#i', $payload, $m) === 1) {
            $deviceId = ltrim($m[1], '0') ?: '0';
            $dd = substr($m[2], 0, 2);
            $mm = substr($m[2], 2, 2);
            $yyyy = substr($m[2], 4, 4);
            $receiptDate = checkdate((int) $mm, (int) $dd, (int) $yyyy) ? "{$yyyy}-{$mm}-{$dd}" : null;
            $globalNo = ltrim($m[3], '0') ?: '0';
            $vCode = strtoupper($m[4]);

            return [
                'valid' => $receiptDate !== null,
                'mode' => 'zimra_fdms_url',
                'device_id' => $deviceId,
                'receipt_date' => $receiptDate,
                'receipt_global_no' => $globalNo,
                'verification_code' => $vCode,
                'receipt_number' => "FDMS-{$deviceId}-{$globalNo}",
            ];
        }

        // Format 2: Structured BetterDoc / Offline QR string: ZIMRA-FDMS|DEV=...|RGN=...|CODE=...
        if (preg_match('/DEV=([0-9]+)\|RGN=([0-9]+)\|CODE=([A-Za-z0-9\-]{8,32})/i', $payload, $m) === 1) {
            return [
                'valid' => true,
                'mode' => 'structured_qr',
                'device_id' => $m[1],
                'receipt_date' => now('UTC')->toDateString(),
                'receipt_global_no' => $m[2],
                'verification_code' => strtoupper($m[3]),
                'receipt_number' => "FDMS-{$m[1]}-{$m[2]}",
            ];
        }

        return [
            'valid' => false,
            'mode' => 'unrecognized',
            'device_id' => 'UNKNOWN',
            'receipt_date' => null,
            'receipt_global_no' => '0',
            'verification_code' => '',
            'receipt_number' => 'INVALID-'.strtoupper(substr(hash('sha256', $payload), 0, 8)),
        ];
    }
}
