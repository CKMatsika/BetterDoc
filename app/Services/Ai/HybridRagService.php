<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiMessageRole;
use App\Enums\DocumentStatus;
use App\Exceptions\WriteDownViolationException;
use App\Models\AiAuditLog;
use App\Models\AiChatMessage;
use App\Models\AiChatSession;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Compartment;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Search\DocumentSearchService;
use App\Services\Security\ClearancePolicyEngine;
use App\Services\Security\DbSecurityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class HybridRagService
{
    public function __construct(
        private readonly LocalAiGatewayService $ai,
        private readonly ChunkIndexingService $chunker,
        private readonly DocumentSearchService $search,
        private readonly ClearancePolicyEngine $policyEngine,
        private readonly DbSecurityContext $dbContext,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * Execute pre-filtered permission-safe hybrid RAG over ONLY documents cleared for the asking user,
     * applying PostgreSQL FORCE RLS + HNSW strict_order before retrieval and ratcheting High-Water-Mark rank.
     *
     * @return array{session: AiChatSession, message: AiChatMessage, citations: list<array<string, mixed>>}
     */
    public function ask(
        User $user,
        string $query,
        ?AiChatSession $existingSession = null,
        ?string $focusDocumentId = null,
        ?Request $request = null,
    ): array {
        $started = microtime(true);
        $this->ai->assertLocalCompliance();

        $trimmedQuery = trim($query);
        if ($trimmedQuery === '') {
            throw new RuntimeException('AI query prompt cannot be empty.');
        }

        return $this->dbContext->runAs($user, function () use ($user, $trimmedQuery, $existingSession, $focusDocumentId, $started, $request): array {
            if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasColumn('document_chunks', 'embedding')) {
                DB::statement('SET LOCAL hnsw.iterative_scan = strict_order');
            }

            // Ensure any newly created/seeded documents visible to user have semantic chunks indexed
            $this->chunker->syncMissingForTenant($user->tenant_id);

            if ($focusDocumentId !== null && $focusDocumentId !== '') {
                $focusDoc = Document::query()->where('id', $focusDocumentId)->firstOrFail();
                $focusDecision = $this->policyEngine->authorize($user, 'view', $focusDoc, ['bypass_abac' => true]);
                if (! $focusDecision->allowed) {
                    abort(404);
                }
            }

            $queryEmbed = $this->ai->embed($trimmedQuery);
            $queryVector = $queryEmbed['vector'];

            // 1. Pre-filtered Semantic Retrieval (PostgreSQL FORCE ROW LEVEL SECURITY guarantees zero hidden chunks returned)
            $chunkQuery = DocumentChunk::query()
                ->with(['document.compartments', 'document.classificationLevel'])
                ->where('tenant_id', $user->tenant_id)
                ->limit(150);

            if ($focusDocumentId !== null && $focusDocumentId !== '') {
                $chunkQuery->where('document_id', $focusDocumentId);
            }

            $candidateChunks = $chunkQuery->get();

            // 2. Pre-filtered Lexical Retrieval (Meilisearch server-injected filter / Postgres FTS + RLS)
            $lexicalRes = $this->search->search($user, $trimmedQuery, ['limit' => 15]);
            /** @var array<string, float> $lexicalBoostByDoc */
            $lexicalBoostByDoc = [];
            foreach ($lexicalRes['hits'] as $rankIdx => $hit) {
                if (is_string($hit['id'] ?? null)) {
                    $lexicalBoostByDoc[$hit['id']] = max(0.2, 1.0 - ($rankIdx * 0.08));
                }
            }

            /** @var list<array{chunk: DocumentChunk, document: Document, score: float}> $scored */
            $scored = [];
            foreach ($candidateChunks as $chunk) {
                $doc = $chunk->document;
                if (! $doc instanceof Document) {
                    continue;
                }

                // Defense-in-depth verify active clearance & compartments per chunk
                if (! $this->policyEngine->authorize($user, 'view', $doc, ['bypass_abac' => true])->allowed) {
                    continue;
                }

                $cos = $this->ai->cosineSimilarity($queryVector, $chunk->embedding_json);
                $kw = $this->keywordOverlapScore($trimmedQuery, $chunk->content_text);
                $lexBoost = $lexicalBoostByDoc[$doc->id] ?? 0.0;

                $combined = ($cos * 0.45) + ($kw * 0.40) + ($lexBoost * 0.15);

                if ($focusDocumentId !== null || $combined >= 0.25 || $kw >= 0.28 || $lexBoost > 0.0) {
                    $scored[] = [
                        'chunk' => $chunk,
                        'document' => $doc,
                        'score' => round($combined, 4),
                    ];
                }
            }

            usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $topHits = array_slice($scored, 0, 5);

            // Compute High-Water-Mark across cited sources (ADR-009)
            $maxRank = 0;
            /** @var list<string> $unionCompartments */
            $unionCompartments = [];
            /** @var list<string> $citedDocIds */
            $citedDocIds = [];
            /** @var list<array<string, mixed>> $citations */
            $citations = [];

            foreach ($topHits as $item) {
                $doc = $item['document'];
                $maxRank = max($maxRank, $doc->classification_rank);
                if (! in_array($doc->id, $citedDocIds, true)) {
                    $citedDocIds[] = $doc->id;
                }
                foreach ($doc->compartments as $comp) {
                    if (! in_array($comp->code, $unionCompartments, true)) {
                        $unionCompartments[] = $comp->code;
                    }
                }
                $citations[] = [
                    'document_id' => $doc->id,
                    'doc_number' => $doc->doc_number,
                    'title' => $doc->title,
                    'classification_rank' => $doc->classification_rank,
                    'classification_name' => $doc->classificationLevel->name ?? 'Level '.$doc->classification_rank,
                    'chunk_index' => $item['chunk']->chunk_index,
                    'score' => $item['score'],
                    'excerpt' => mb_substr($item['chunk']->content_text, 0, 220),
                ];
            }

            $session = $existingSession ?? AiChatSession::query()->create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'title' => mb_substr($trimmedQuery, 0, 120),
                'high_water_rank' => $maxRank,
                'high_water_compartments' => $unionCompartments,
                'model_name' => config()->string('sentinel.ai.chat_model', 'qwen2.5:7b-instruct'),
            ]);

            // Ratchet session high-water-mark strictly upward (never downgrade)
            $newSessionRank = max($session->high_water_rank, $maxRank);
            $mergedComps = array_values(array_unique(array_merge($session->high_water_compartments, $unionCompartments)));
            $session->update([
                'high_water_rank' => $newSessionRank,
                'high_water_compartments' => $mergedComps,
            ]);

            AiChatMessage::query()->create([
                'tenant_id' => $user->tenant_id,
                'session_id' => $session->id,
                'role' => AiMessageRole::User,
                'content' => $trimmedQuery,
                'high_water_rank' => $user->clearance_rank,
                'model_used' => 'user-prompt',
                'latency_ms' => 0,
            ]);

            $answerText = $this->synthesizeGroundedAnswer($trimmedQuery, $topHits);
            $usedModel = $queryEmbed['model'];

            if ($topHits !== [] && config()->string('sentinel.ai.driver', 'local_hybrid') === 'ollama') {
                $contextBlock = '';
                foreach ($topHits as $h) {
                    $contextBlock .= "[{$h['document']->doc_number} — {$h['document']->title}]\n{$h['chunk']->content_text}\n\n";
                }
                $llmRes = $this->ai->generateText(
                    'You are SENTINEL DMS Offline Assistant. Answer ONLY using the provided cleared document excerpts and cite exact doc_numbers. If context lacks an answer, state so.',
                    "Question: {$trimmedQuery}\n\nCleared Context:\n{$contextBlock}"
                );
                if ($llmRes['text'] !== null) {
                    $answerText = $llmRes['text'];
                    $usedModel = $llmRes['model'];
                }
            }

            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            /** @var AiChatMessage $assistantMessage */
            $assistantMessage = AiChatMessage::query()->create([
                'tenant_id' => $user->tenant_id,
                'session_id' => $session->id,
                'role' => AiMessageRole::Assistant,
                'content' => $answerText,
                'cited_doc_ids' => $citedDocIds,
                'cited_chunks_json' => $citations,
                'high_water_rank' => $maxRank,
                'high_water_compartments' => $unionCompartments,
                'model_used' => $usedModel,
                'latency_ms' => $latencyMs,
            ]);

            AiAuditLog::query()->create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'session_id' => $session->id,
                'action' => 'rag_chat',
                'model_used' => $usedModel,
                'prompt_sha256' => hash('sha256', $trimmedQuery),
                'output_sha256' => hash('sha256', $answerText),
                'retrieved_doc_ids' => $citedDocIds,
                'high_water_rank' => $newSessionRank,
                'latency_ms' => $latencyMs,
            ]);

            $this->audit->log(
                tenantId: $user->tenant_id,
                eventType: 'ai.rag_queried',
                resourceType: 'ai_chat_session',
                resourceId: $session->id,
                actor: $user,
                classificationRank: $newSessionRank,
                after: [
                    'cited_doc_count' => count($citedDocIds),
                    'high_water_rank' => $newSessionRank,
                    'latency_ms' => $latencyMs,
                ],
                request: $request,
            );

            return [
                'session' => $session->refresh(),
                'message' => $assistantMessage,
                'citations' => $citations,
            ];
        });
    }

    /**
     * Save an AI session response as a vault Document, enforcing Bell-LaPadula High-Water-Mark write-down protection.
     */
    public function exportSessionAsDocument(
        User $user,
        AiChatSession $session,
        Cabinet $cabinet,
        ClearanceLevel $targetLevel,
        string $title,
        ?Request $request = null,
    ): Document {
        if ($targetLevel->rank < $session->high_water_rank) {
            throw new WriteDownViolationException(
                "High-Water-Mark violation: chat session retrieved sources classified at Rank {$session->high_water_rank}. Cannot save draft below Rank {$session->high_water_rank}."
            );
        }

        if ($targetLevel->rank > $user->clearance_rank) {
            throw new WriteDownViolationException('Cannot classify exported AI draft above your own clearance rank.');
        }

        /** @var list<string> $compIds */
        $compIds = [];
        if ($session->high_water_compartments !== []) {
            $rawCompIds = Compartment::query()
                ->where('tenant_id', $user->tenant_id)
                ->whereIn('code', $session->high_water_compartments)
                ->pluck('id')
                ->all();
            foreach ($rawCompIds as $cid) {
                if (is_string($cid)) {
                    $compIds[] = $cid;
                }
            }
            if (array_diff($compIds, $user->activeCompartmentIds()) !== []) {
                throw new WriteDownViolationException('Export denied: session inherited compartments you no longer hold.');
            }
        }

        $compiledText = $session->messages()
            ->get()
            ->map(static fn (AiChatMessage $m): string => '['.strtoupper($m->role->value)."] {$m->content}")
            ->implode("\n\n");

        $docNumber = 'AI-BRIEF/'.now('UTC')->format('Ymd-His');

        /** @var Document $doc */
        $doc = Document::query()->create([
            'tenant_id' => $user->tenant_id,
            'cabinet_id' => $cabinet->id,
            'org_unit_id' => $user->org_unit_id,
            'owner_id' => $user->id,
            'classification_level_id' => $targetLevel->id,
            'classification_rank' => $targetLevel->rank,
            'doc_number' => $docNumber,
            'title' => trim($title),
            'summary' => "AI-assisted synthesis (High-Water Rank L{$session->high_water_rank}).",
            'ocr_text' => $compiledText,
            'status' => DocumentStatus::Draft,
            'unit_wide_readable' => false,
            'metadata_json' => [
                'ai_session_id' => $session->id,
                'high_water_rank' => $session->high_water_rank,
                'high_water_compartments' => $session->high_water_compartments,
            ],
        ]);

        if ($compIds !== []) {
            $doc->compartments()->sync($compIds);
        }

        $this->search->indexDocument($doc->refresh());

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: 'ai.session_exported_document',
            resourceType: 'document',
            resourceId: $doc->id,
            actor: $user,
            classificationRank: $doc->classification_rank,
            after: ['session_id' => $session->id, 'high_water_rank' => $session->high_water_rank],
            request: $request,
        );

        return $doc;
    }

    /**
     * @param  list<array{chunk: DocumentChunk, document: Document, score: float}>  $topHits
     */
    private function synthesizeGroundedAnswer(string $query, array $topHits): string
    {
        if ($topHits === []) {
            return 'No documents within your active clearance level and need-to-know compartments matched this query.';
        }

        $lines = [
            "Grounded Offline Synthesis ({$query}):",
            '',
        ];
        foreach ($topHits as $idx => $hit) {
            $num = $idx + 1;
            $doc = $hit['document'];
            $rankLabel = $doc->classificationLevel->name ?? "Rank {$doc->classification_rank}";
            $snippet = mb_substr($hit['chunk']->content_text, 0, 240);
            $lines[] = "{$num}. [{$doc->doc_number} | {$rankLabel}] {$doc->title}: \"{$snippet}\"";
        }

        return implode("\n", $lines);
    }

    private function keywordOverlapScore(string $query, string $text): float
    {
        $stop = ['the', 'and', 'for', 'with', 'that', 'this', 'what', 'all', 'are', 'you', 'can', 'how', 'tell', 'ignore', 'previous', 'instructions', 'about', 'from', 'reveal', 'list', 'details'];
        preg_match_all('/[a-z0-9]{3,}/u', mb_strtolower($query), $qMatch);
        $qTokens = array_values(array_diff(array_unique($qMatch[0]), $stop));

        if ($qTokens === []) {
            return 0.0;
        }

        $lowerText = mb_strtolower($text);
        $hits = 0;
        foreach ($qTokens as $tok) {
            if (str_contains($lowerText, $tok)) {
                $hits++;
            }
        }

        return $hits / count($qTokens);
    }
}
