<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\IdpDocType;
use App\Enums\IdpReviewStatus;
use App\Models\AiAuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\IdpExtraction;
use App\Models\Tag;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Search\DocumentSearchService;
use Illuminate\Http\Request;

class IdpExtractionService
{
    public function __construct(
        private readonly LocalAiGatewayService $ai,
        private readonly ImmutableAuditLogger $audit,
        private readonly DocumentSearchService $search,
    ) {}

    /**
     * Classify document type zero-shot and extract structured key-value fields with confidence scores.
     */
    public function extractFromDocument(
        Document $document,
        ?User $actor = null,
        ?DocumentVersion $version = null,
        ?Request $request = null,
    ): IdpExtraction {
        $started = microtime(true);
        $this->ai->assertLocalCompliance();

        $document->loadMissing(['currentVersion']);
        $targetVersion = $version ?? $document->currentVersion;

        $versionText = $targetVersion !== null ? $targetVersion->extracted_text : null;

        $text = trim(implode("\n", array_filter([
            $document->doc_number,
            $document->title,
            $document->summary,
            $versionText ?? $document->ocr_text,
        ])));

        $docType = $this->classifyDocType($text, $document->title);
        $fields = $this->extractFieldsForType($docType, $text);
        $modelUsed = 'betterdoc-idp-zeroshot-v1';

        // If Ollama LLM runtime is enabled and responsive, allow enrichment without blocking deterministic extraction
        if (config()->string('betterdoc.ai.driver', 'local_hybrid') === 'ollama') {
            $gen = $this->ai->generateText(
                'Extract concise JSON key-value metadata fields for doc_type='.$docType->value.' with keys: vendor_name, invoice_number, total_amount, currency, vat_amount, po_number, date, counterparty, subject. Respond JSON only.',
                mb_substr($text, 0, 2500),
            );
            if ($gen['text'] !== null) {
                $modelUsed = $gen['model'];
                /** @var mixed $parsed */
                $parsed = json_decode($gen['text'], true);
                if (is_array($parsed)) {
                    foreach ($parsed as $k => $v) {
                        if (is_string($k) && is_scalar($v) && trim((string) $v) !== '' && ! isset($fields[$k])) {
                            $fields[$k] = [
                                'label' => ucwords(str_replace('_', ' ', $k)),
                                'value' => trim((string) $v),
                                'confidence' => 0.86,
                            ];
                        }
                    }
                }
            }
        }

        $confSum = 0.0;
        $confCount = 0;
        foreach ($fields as $item) {
            $confSum += $item['confidence'];
            $confCount++;
        }
        $overallConfidence = $confCount > 0 ? round($confSum / $confCount, 3) : 0.72;

        /** @var IdpExtraction $extraction */
        $extraction = IdpExtraction::query()->create([
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
            'document_version_id' => $targetVersion?->id,
            'model_name' => $modelUsed,
            'detected_doc_type' => $docType,
            'extracted_fields_json' => $fields,
            'overall_confidence' => $overallConfidence,
            'review_status' => IdpReviewStatus::PendingReview,
        ]);

        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        if ($actor !== null) {
            AiAuditLog::query()->create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $actor->id,
                'action' => 'idp_extract',
                'model_used' => $modelUsed,
                'prompt_sha256' => hash('sha256', $text),
                'output_sha256' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)),
                'retrieved_doc_ids' => [$document->id],
                'high_water_rank' => $document->classification_rank,
                'latency_ms' => $latencyMs,
            ]);

            $this->audit->log(
                tenantId: $document->tenant_id,
                eventType: 'ai.idp_extracted',
                resourceType: 'idp_extraction',
                resourceId: $extraction->id,
                actor: $actor,
                classificationRank: $document->classification_rank,
                after: [
                    'document_id' => $document->id,
                    'detected_doc_type' => $docType->value,
                    'field_count' => count($fields),
                    'overall_confidence' => $overallConfidence,
                ],
                request: $request,
            );
        }

        return $extraction;
    }

    /**
     * Complete human-in-the-loop review on an IDP extraction, promoting approved fields to document metadata.
     *
     * @param  array<string, string>  $correctedValues
     */
    public function reviewExtraction(
        IdpExtraction $extraction,
        User $reviewer,
        IdpReviewStatus $status,
        array $correctedValues = [],
        ?string $notes = null,
        ?Request $request = null,
    ): IdpExtraction {
        $document = $extraction->document;
        $fields = $extraction->extracted_fields_json;

        foreach ($correctedValues as $key => $val) {
            if (trim($val) !== '') {
                $fields[$key] = [
                    'label' => $fields[$key]['label'] ?? ucwords(str_replace('_', ' ', $key)),
                    'value' => trim($val),
                    'confidence' => 1.0,
                ];
            }
        }

        $extraction->update([
            'extracted_fields_json' => $fields,
            'review_status' => $status,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'reviewer_notes' => $notes,
        ]);

        if ($document !== null && in_array($status, [IdpReviewStatus::Approved, IdpReviewStatus::Corrected], true)) {
            $meta = is_array($document->metadata_json) ? $document->metadata_json : [];
            $flatVerified = [];
            foreach ($fields as $k => $entry) {
                $flatVerified[$k] = $entry['value'];
            }
            $meta['idp_doc_type'] = $extraction->detected_doc_type->value;
            $meta['idp_verified'] = $flatVerified;
            $document->update(['metadata_json' => $meta]);

            $tag = Tag::query()->firstOrCreate(
                ['tenant_id' => $document->tenant_id, 'name' => 'idp-'.$extraction->detected_doc_type->value],
                ['color_hex' => '#0D9488']
            );
            $document->tags()->syncWithoutDetaching([$tag->id]);
            $this->search->indexDocument($document->refresh());
        }

        $docRank = $document instanceof Document ? $document->classification_rank : 0;

        $this->audit->log(
            tenantId: $extraction->tenant_id,
            eventType: 'ai.idp_reviewed',
            resourceType: 'idp_extraction',
            resourceId: $extraction->id,
            actor: $reviewer,
            classificationRank: $docRank,
            after: [
                'review_status' => $status->value,
                'document_id' => $extraction->document_id,
            ],
            request: $request,
        );

        return $extraction->refresh();
    }

    private function classifyDocType(string $text, string $title): IdpDocType
    {
        $haystack = mb_strtolower($title."\n".$text);

        $scores = [
            IdpDocType::Invoice->value => $this->keywordHits($haystack, ['invoice', 'vat', 'tin', 'subtotal', 'total due', 'po number', 'tax invoice', 'zimra', 'bill to', 'payable']),
            IdpDocType::Contract->value => $this->keywordHits($haystack, ['agreement', 'contract', 'indemnity', 'termination', 'governing law', 'counterparty', 'confidentiality', 'effective date', 'clause']),
            IdpDocType::IdDocument->value => $this->keywordHits($haystack, ['national id', 'passport', 'date of birth', 'id number', 'surname', 'citizen', 'identity card']),
            IdpDocType::Form->value => $this->keywordHits($haystack, ['application form', 'applicant', 'declaration', 'checklist', 'form no', 'signature of applicant']),
            IdpDocType::Memo->value => $this->keywordHits($haystack, ['memorandum', 'memo', 'to:', 'from:', 'subject:', 'briefing', 'directive']),
            IdpDocType::Letter->value => $this->keywordHits($haystack, ['dear ', 'sincerely', 'attention:', 'yours faithfully', 'correspondence', 'letter']),
        ];

        arsort($scores);
        $topKey = array_key_first($scores);

        return $scores[$topKey] > 0
            ? IdpDocType::from($topKey)
            : IdpDocType::Letter;
    }

    /**
     * Extract structured high-confidence key-value fields tailored to document classification.
     *
     * @return array<string, array{label: string, value: string, confidence: float}>
     */
    private function extractFieldsForType(IdpDocType $docType, string $text): array
    {
        $fields = [];

        $patterns = match ($docType) {
            IdpDocType::Invoice => [
                'vendor_name' => ['Vendor / Supplier', '/(?:Vendor|Supplier|From|Billed[ \t]+By|Company)[ \t]*[:\-][ \t]*([^\r\n]+)/i', 0.94],
                'invoice_number' => ['Invoice Number', '/(?:Invoice[ \t]*(?:No|Number|#)|Tax[ \t]*Inv(?:\.|oice)?)[ \t]*[:\-#]*[ \t]*([A-Z0-9\-\/]*\d[A-Z0-9\-\/]{1,39})/i', 0.96],
                'invoice_date' => ['Invoice Date', '/(?:Invoice[ \t]*Date|Date[ \t]*Issued|Date)[ \t]*[:\-][ \t]*([0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{1,2}[ \t]+[A-Za-z]{3,9}[ \t]+[0-9]{4}|[0-9]{2}\/[0-9]{2}\/[0-9]{4})/i', 0.93],
                'po_number' => ['Purchase Order (PO)', '/(?:PO[ \t]*(?:No|Number|#)|Purchase[ \t]*Order)[ \t]*[:\-#]*[ \t]*([A-Z0-9\-\/]*\d[A-Z0-9\-\/]{1,31})/i', 0.92],
                'currency' => ['Currency', '/\b(USD|ZWG|ZWL|ZAR|EUR|GBP)\b/i', 0.95],
                'subtotal' => ['Subtotal', '/(?:Subtotal|Net[ \t]*Amount)[ \t]*[:\-]?[ \t]*(?:USD|ZWG|ZAR|\$)?[ \t]*([0-9,]+\.[0-9]{2})/i', 0.91],
                'vat_amount' => ['VAT / Tax Amount', '/(?:VAT(?:[ \t]*\(?15%?\)?)?|Tax[ \t]*Amount)[ \t]*[:\-]?[ \t]*(?:USD|ZWG|ZAR|\$)?[ \t]*([0-9,]+\.[0-9]{2})/i', 0.91],
                'total_amount' => ['Total Amount', '/\b(?:Total[ \t]*(?:Due|Amount|Payable)?|Grand[ \t]*Total)[ \t]*[:\-]?[ \t]*(?:USD|ZWG|ZAR|\$)?[ \t]*([0-9,]+\.[0-9]{2})/i', 0.95],
                'zimra_tin' => ['ZIMRA TIN / VAT Reg', '/(?:ZIMRA[ \t]*(?:TIN|BPN)|TIN|VAT[ \t]*Reg)[ \t]*[:\-#]*[ \t]*([0-9A-Z\-]{6,24})/i', 0.90],
            ],
            IdpDocType::Contract => [
                'counterparty' => ['Counterparty / Vendor', '/(?:Between|Counterparty|Contractor|Vendor|Client)[ \t]*[:\-]?[ \t]*([A-Z][A-Za-z0-9 \t&.,\-]{3,60})/m', 0.91],
                'effective_date' => ['Effective / Start Date', '/(?:Effective[ \t]*Date|Commencement[ \t]*Date|Start[ \t]*Date)[ \t]*[:\-][ \t]*([^\r\n,;]{4,32})/i', 0.93],
                'expiry_date' => ['Expiry / End Date', '/(?:Expiry[ \t]*Date|End[ \t]*Date|Termination[ \t]*Date)[ \t]*[:\-][ \t]*([^\r\n,;]{4,32})/i', 0.92],
                'contract_value' => ['Contract Value', '/(?:Contract[ \t]*Value|Fee|Consideration)[ \t]*[:\-]?[ \t]*(?:USD|ZWG|ZAR|\$)?[ \t]*([0-9,]+(?:\.[0-9]{2})?)/i', 0.89],
                'governing_law' => ['Governing Law', '/(?:Governing[ \t]*Law|Laws[ \t]*of)[ \t]*[:\-]?[ \t]*(?:the[ \t]*)?([A-Za-z \t]{4,40})/i', 0.90],
            ],
            IdpDocType::IdDocument => [
                'full_name' => ['Full Name', '/(?:Full[ \t]*Name|Name|Surname[ \t]*&[ \t]*Forenames)[ \t]*[:\-][ \t]*([^\r\n]+)/i', 0.94],
                'national_id_number' => ['National ID / Passport', '/(?:National[ \t]*ID|ID[ \t]*(?:No|Number)|Passport[ \t]*No)[ \t]*[:\-][ \t]*([0-9A-Z\-]{6,24})/i', 0.96],
                'date_of_birth' => ['Date of Birth', '/(?:Date[ \t]*of[ \t]*Birth|DOB)[ \t]*[:\-][ \t]*([^\r\n]+)/i', 0.93],
            ],
            default => [
                'reference_no' => ['Reference Number', '/(?:Reference|Ref(?:[ \t]*No)?|Doc(?:ument)?[ \t]*No)[ \t]*[:\-][ \t]*([A-Z0-9\-\/]{3,40})/i', 0.93],
                'sender' => ['From / Originator', '/(?:From|Sender|Originator|Prepared[ \t]*By)[ \t]*[:\-][ \t]*([^\r\n]+)/i', 0.90],
                'recipient' => ['To / Recipient', '/(?:To|Attention|Recipient|Addressed[ \t]*To)[ \t]*[:\-][ \t]*([^\r\n]+)/i', 0.90],
                'date' => ['Document Date', '/(?:Date)[ \t]*[:\-][ \t]*([^\r\n]+)/i', 0.91],
                'subject' => ['Subject', '/(?:Subject|RE|Title)[ \t]*[:\-][ \t]*([^\r\n]+)/i', 0.92],
            ],
        };

        foreach ($patterns as $key => [$label, $regex, $conf]) {
            if (preg_match($regex, $text, $m) === 1) {
                $val = trim($m[1]);
                if ($val !== '') {
                    $fields[$key] = [
                        'label' => $label,
                        'value' => $val,
                        'confidence' => $conf,
                    ];
                }
            }
        }

        return $fields;
    }

    /**
     * @param  list<string>  $keywords
     */
    private function keywordHits(string $haystack, array $keywords): int
    {
        $hits = 0;
        foreach ($keywords as $kw) {
            if (str_contains($haystack, $kw)) {
                $hits++;
            }
        }

        return $hits;
    }
}
