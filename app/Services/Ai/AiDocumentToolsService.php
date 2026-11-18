<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiAuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Tag;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Search\DocumentSearchService;
use App\Services\Security\ClearancePolicyEngine;
use Illuminate\Http\Request;

class AiDocumentToolsService
{
    /** @var array<string, array{sn: string, nd: string}> */
    private const array GLOSSARY_EN = [
        'classified' => ['sn' => 'chakavanzika', 'nd' => 'okuyimfihlo'],
        'confidential' => ['sn' => 'chakavanzika zvakanyanya', 'nd' => 'okuyimfihlo kakhulu'],
        'secret' => ['sn' => 'chakavanzika chepamusoro', 'nd' => 'imfihlo ephezulu'],
        'document' => ['sn' => 'gwaro', 'nd' => 'incwadi'],
        'approval' => ['sn' => 'mvumo', 'nd' => 'ukuvunyelwa'],
        'approved' => ['sn' => 'zvabvumidzwa', 'nd' => 'kuvunyelwe'],
        'invoice' => ['sn' => 'fatura yemutengo', 'nd' => 'i-invoyisi'],
        'contract' => ['sn' => 'chibvumirano', 'nd' => 'isivumelwano'],
        'payment' => ['sn' => 'kubhadhara', 'nd' => 'ukubhadala'],
        'deadline' => ['sn' => 'zuva rekupedzisira', 'nd' => 'usuku lokucina'],
        'compliance' => ['sn' => 'kutevedzera mitemo', 'nd' => 'ukulandela imithetho'],
        'urgent' => ['sn' => 'chimbichimbi', 'nd' => 'okuphuthumayo'],
        'security' => ['sn' => 'chengetedzo', 'nd' => 'ukuphepha'],
        'signature' => ['sn' => 'siginicha', 'nd' => 'isiginitsha'],
        'notice' => ['sn' => 'chiziviso', 'nd' => 'isaziso'],
        'report' => ['sn' => 'mushumo', 'nd' => 'umbiko'],
        'please' => ['sn' => 'ndapota', 'nd' => 'ngicela'],
        'verify' => ['sn' => 'simbisa', 'nd' => 'qinisekisa'],
        'complete' => ['sn' => 'pedzisa', 'nd' => 'qeda'],
        'facility' => ['sn' => 'chikwereti / nzvimbo', 'nd' => 'isikwelede / indawo'],
    ];

    public function __construct(
        private readonly LocalAiGatewayService $ai,
        private readonly ClearancePolicyEngine $policyEngine,
        private readonly ImmutableAuditLogger $audit,
        private readonly DocumentSearchService $search,
    ) {}

    /**
     * Summarize a cleared document offline and record AI provenance.
     *
     * @return array{summary: string, model: string}
     */
    public function summarize(User $user, Document $document, ?Request $request = null): array
    {
        $started = microtime(true);
        $this->ai->assertLocalCompliance();

        $text = $this->documentText($document);
        $gen = $this->ai->generateText(
            'Provide a concise 3-bullet executive summary of this document preserving all reference numbers.',
            mb_substr($text, 0, 3500)
        );

        $summary = $gen['text'] ?? $this->extractiveSummary($document, $text);
        $model = $gen['model'];

        $this->recordAudit($user, $document, 'summarize', $text, $summary, $model, $started, $request);

        return ['summary' => $summary, 'model' => $model];
    }

    /**
     * Extract key contractual/regulatory clauses offline.
     *
     * @return array{clauses: list<array{category: string, excerpt: string}>, model: string}
     */
    public function extractClauses(User $user, Document $document, ?Request $request = null): array
    {
        $started = microtime(true);
        $this->ai->assertLocalCompliance();

        $text = $this->documentText($document);
        $sentences = preg_split('/(?<=[.!?])\s+|\R+/u', $text) ?: [];

        $rules = [
            'Payment & Fiscal Terms' => ['payment', 'invoice', 'vat', 'zimra', 'total', 'days', 'fee', 'currency'],
            'Confidentiality & Security' => ['confidential', 'secret', 'classified', 'clearance', 'compartment', 'restricted'],
            'Obligations & SLA' => ['shall', 'must', 'required', 'deliver', 'deadline', 'within', 'sla', 'maintenance'],
            'Termination & Renewal' => ['terminate', 'termination', 'expiry', 'renewal', 'notice', 'cancel'],
            'Governing Law & Indemnity' => ['governing law', 'zimbabwe', 'indemnity', 'liability', 'arbitration'],
        ];

        $clauses = [];
        foreach ($sentences as $sentence) {
            $clean = trim($sentence);
            if (mb_strlen($clean) < 12) {
                continue;
            }
            $lower = mb_strtolower($clean);
            foreach ($rules as $cat => $keywords) {
                foreach ($keywords as $kw) {
                    if (str_contains($lower, $kw)) {
                        $clauses[] = ['category' => $cat, 'excerpt' => $clean];
                        break 2;
                    }
                }
            }
        }

        if ($clauses === []) {
            $clauses[] = ['category' => 'General Provisions', 'excerpt' => mb_substr($text, 0, 260)];
        }

        $model = 'sentinel-clause-extractor-v1';
        $this->recordAudit($user, $document, 'extract_clauses', $text, json_encode($clauses, JSON_THROW_ON_ERROR), $model, $started, $request);

        return ['clauses' => array_slice($clauses, 0, 12), 'model' => $model];
    }

    /**
     * Compare two document versions highlighting semantic additions and removals.
     *
     * @return array{summary: string, added_lines: list<string>, removed_lines: list<string>, model: string}
     */
    public function compareVersions(User $user, Document $document, DocumentVersion $from, DocumentVersion $to, ?Request $request = null): array
    {
        $started = microtime(true);
        $this->ai->assertLocalCompliance();

        $fromText = (string) ($from->extracted_text ?? '');
        $toText = (string) ($to->extracted_text ?? '');

        $fromLines = array_values(array_filter(array_map('trim', explode("\n", $fromText))));
        $toLines = array_values(array_filter(array_map('trim', explode("\n", $toText))));

        $added = array_values(array_diff($toLines, $fromLines));
        $removed = array_values(array_diff($fromLines, $toLines));

        $summary = sprintf(
            'Semantic version comparison (%s -> %s): %d added statement(s), %d removed statement(s).',
            $from->label(),
            $to->label(),
            count($added),
            count($removed),
        );

        $model = 'sentinel-version-diff-v1';
        $this->recordAudit($user, $document, 'compare_versions', $fromText."\n".$toText, $summary, $model, $started, $request);

        return [
            'summary' => $summary,
            'added_lines' => array_slice($added, 0, 20),
            'removed_lines' => array_slice($removed, 0, 20),
            'model' => $model,
        ];
    }

    /**
     * Draft a clearance-tagged formal reply based on the document context.
     *
     * @return array{draft: string, high_water_rank: int, model: string}
     */
    public function draftReply(User $user, Document $document, string $instructions = '', ?Request $request = null): array
    {
        $started = microtime(true);
        $this->ai->assertLocalCompliance();

        $text = $this->documentText($document);
        $rankName = strtoupper($document->classificationLevel->name ?? "RANK {$document->classification_rank}");

        $gen = $this->ai->generateText(
            "Draft a concise formal official reply referencing {$document->doc_number}. Retain classification banner [{$rankName}].",
            "Document Title: {$document->title}\nGuidance: {$instructions}\nContext: ".mb_substr($text, 0, 1500)
        );

        $author = ($user->rank_title ? $user->rank_title.' ' : '').$user->name;
        $draft = $gen['text'] ?? implode("\n\n", [
            "CLASSIFICATION: {$rankName}",
            "REFERENCE: RE: {$document->doc_number} — {$document->title}",
            'Dear Recipient,',
            trim($instructions) !== ''
                ? "With reference to {$document->doc_number}, please note: ".trim($instructions)
                : "We acknowledge receipt and review of {$document->doc_number} ({$document->title}). All action items noted therein are scheduled within current governance controls.",
            "Issued by {$author} on ".now('UTC')->format('Y-m-d').'.',
        ]);

        $model = $gen['model'];
        $this->recordAudit($user, $document, 'draft_reply', $text, $draft, $model, $started, $request);

        return [
            'draft' => $draft,
            'high_water_rank' => $document->classification_rank,
            'model' => $model,
        ];
    }

    /**
     * Suggest and optionally attach domain tags offline.
     *
     * @return array{tags: list<string>, applied: bool}
     */
    public function suggestTags(User $user, Document $document, bool $apply = false, ?Request $request = null): array
    {
        $started = microtime(true);
        $this->ai->assertLocalCompliance();

        $haystack = mb_strtolower($this->documentText($document));
        $candidates = [
            'zimra-fiscal' => ['zimra', 'tin', 'vat', 'fiscal'],
            'ap-invoice' => ['invoice', 'payable', 'po ', 'purchase order'],
            'aml-kyc' => ['aml', 'kyc', 'fiu', 'suspicious', 'pep', 'remittance'],
            'contract-legal' => ['contract', 'agreement', 'indemnity', 'termination'],
            'operational-ops' => ['patrol', 'airspace', 'radar', 'squadron', 'scramble'],
            'board-executive' => ['board', 'capex', 'acquisition', 'strategic'],
            'hr-confidential' => ['hr ', 'disciplinary', 'payroll', 'appraisal'],
        ];

        $matched = [];
        foreach ($candidates as $tag => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($haystack, $kw)) {
                    $matched[] = $tag;
                    break;
                }
            }
        }
        if ($matched === []) {
            $matched[] = 'general-record';
        }

        $applied = false;
        if ($apply && $this->policyEngine->authorize($user, 'update', $document, ['bypass_abac' => true])->allowed) {
            $tagIds = [];
            foreach ($matched as $tagName) {
                $t = Tag::query()->firstOrCreate(
                    ['tenant_id' => $document->tenant_id, 'name' => $tagName],
                    ['color_hex' => '#0284C7']
                );
                $tagIds[] = $t->id;
            }
            $document->tags()->syncWithoutDetaching($tagIds);
            $this->search->indexDocument($document->refresh());
            $applied = true;
        }

        $this->recordAudit($user, $document, 'auto_tag', $haystack, implode(',', $matched), 'sentinel-autotag-v1', $started, $request);

        return ['tags' => $matched, 'applied' => $applied];
    }

    /**
     * Detect near-duplicate documents strictly across records the requesting user is cleared to see.
     *
     * @return list<array{document_id: string, doc_number: string, title: string, similarity: float}>
     */
    public function detectDuplicates(User $user, Document $document, ?Request $request = null): array
    {
        $started = microtime(true);
        $this->ai->assertLocalCompliance();

        $srcText = $this->documentText($document);
        $srcVec = $this->ai->embed($srcText)['vector'];

        $peers = Document::query()
            ->where('tenant_id', $document->tenant_id)
            ->where('id', '!=', $document->id)
            ->limit(100)
            ->get();

        $duplicates = [];
        foreach ($peers as $peer) {
            if (! $this->policyEngine->authorize($user, 'view', $peer, ['bypass_abac' => true])->allowed) {
                continue;
            }
            $peerVec = $this->ai->embed($this->documentText($peer))['vector'];
            $sim = round($this->ai->cosineSimilarity($srcVec, $peerVec), 3);
            if ($sim >= 0.72) {
                $duplicates[] = [
                    'document_id' => $peer->id,
                    'doc_number' => $peer->doc_number,
                    'title' => $peer->title,
                    'similarity' => $sim,
                ];
            }
        }

        usort($duplicates, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        $this->recordAudit($user, $document, 'duplicate_check', $srcText, json_encode($duplicates, JSON_THROW_ON_ERROR), 'sentinel-dup-v1', $started, $request);

        return array_slice($duplicates, 0, 5);
    }

    /**
     * Translate text between English (en), ChiShona (sn), and IsiNdebele (nd) strictly locally.
     *
     * @return array{translated_text: string, target_lang: string, model: string}
     */
    public function translate(User $user, string $text, string $targetLang, ?Document $document = null, ?Request $request = null): array
    {
        $started = microtime(true);
        $this->ai->assertLocalCompliance();

        $langMap = ['en' => 'English', 'sn' => 'ChiShona', 'nd' => 'IsiNdebele'];
        $targetLabel = $langMap[$targetLang] ?? 'ChiShona';

        if (config()->string('sentinel.ai.driver', 'local_hybrid') === 'ollama') {
            $gen = $this->ai->generateText(
                "Translate the following official document text accurately into {$targetLabel}. Preserve numbers and reference codes.",
                mb_substr($text, 0, 2500)
            );
            if ($gen['text'] !== null) {
                if ($document !== null) {
                    $this->recordAudit($user, $document, "translate_{$targetLang}", $text, $gen['text'], $gen['model'], $started, $request);
                }

                return ['translated_text' => $gen['text'], 'target_lang' => $targetLang, 'model' => $gen['model']];
            }
        }

        $translated = $text;
        $notes = [];
        if (in_array($targetLang, ['sn', 'nd'], true)) {
            foreach (self::GLOSSARY_EN as $enWord => $translations) {
                $replacement = $translations[$targetLang];
                if (preg_match('/\b'.preg_quote($enWord, '/').'\b/i', $translated) === 1) {
                    $translated = (string) preg_replace('/\b'.preg_quote($enWord, '/').'\b/i', "{$enWord} [{$replacement}]", $translated);
                    $notes[] = "{$enWord} -> {$replacement}";
                }
            }
            $header = $targetLang === 'sn' ? '[Dudziro yeChiShona — SENTINEL Offline Translator]' : '[Ukuhunyushwa kwesiNdebele — SENTINEL Offline Translator]';
            $translated = $header."\n".$translated.($notes !== [] ? "\n\nKey Terms: ".implode(' · ', $notes) : '');
        }

        $model = 'sentinel-en-sn-nd-offline-v1';
        if ($document !== null) {
            $this->recordAudit($user, $document, "translate_{$targetLang}", $text, $translated, $model, $started, $request);
        }

        return [
            'translated_text' => $translated,
            'target_lang' => $targetLang,
            'model' => $model,
        ];
    }

    private function documentText(Document $document): string
    {
        $document->loadMissing(['currentVersion', 'classificationLevel']);

        return trim(implode("\n", array_filter([
            "Doc No: {$document->doc_number}",
            "Title: {$document->title}",
            $document->summary,
            $document->ocr_text ?? $document->currentVersion?->extracted_text,
        ])));
    }

    private function extractiveSummary(Document $document, string $text): string
    {
        $sentences = array_values(array_filter(
            array_map('trim', preg_split('/(?<=[.!?])\s+|\R+/u', $text) ?: []),
            static fn (string $s): bool => mb_strlen($s) >= 10
        ));
        $bullets = array_slice($sentences, 0, 3);
        if ($bullets === []) {
            $bullets[] = $document->title;
        }

        return "Offline Summary ({$document->doc_number}):\n• ".implode("\n• ", $bullets);
    }

    private function recordAudit(
        User $user,
        Document $document,
        string $action,
        string $prompt,
        string $output,
        string $model,
        float $started,
        ?Request $request,
    ): void {
        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        AiAuditLog::query()->create([
            'tenant_id' => $document->tenant_id,
            'user_id' => $user->id,
            'action' => $action,
            'model_used' => $model,
            'prompt_sha256' => hash('sha256', $prompt),
            'output_sha256' => hash('sha256', $output),
            'retrieved_doc_ids' => [$document->id],
            'high_water_rank' => $document->classification_rank,
            'latency_ms' => $latencyMs,
        ]);

        $this->audit->log(
            tenantId: $document->tenant_id,
            eventType: "ai.{$action}",
            resourceType: 'document',
            resourceId: $document->id,
            actor: $user,
            classificationRank: $document->classification_rank,
            after: ['model' => $model, 'latency_ms' => $latencyMs],
            request: $request,
        );
    }
}
