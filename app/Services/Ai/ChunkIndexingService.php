<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChunkIndexingService
{
    public function __construct(
        private readonly LocalAiGatewayService $ai,
    ) {}

    /**
     * Split a document's OCR/extracted text into searchable overlapping chunks with normalized vectors.
     */
    public function indexDocument(Document $document): int
    {
        $document->loadMissing(['currentVersion', 'tags', 'compartments']);

        $tagText = $document->tags->pluck('name')->implode(', ');
        $parts = array_filter([
            "Document {$document->doc_number}: {$document->title}",
            $document->summary,
            $tagText !== '' ? "Tags: {$tagText}" : null,
            $document->ocr_text ?? $document->currentVersion?->extracted_text,
        ], static fn (?string $p): bool => $p !== null && trim($p) !== '');

        $fullText = trim(implode("\n\n", $parts));
        if ($fullText === '') {
            return 0;
        }

        $chunks = $this->splitChunks($fullText, 650, 100);
        $versionId = $document->current_version_id;

        DocumentChunk::query()->where('document_id', $document->id)->delete();

        $hasVectorCol = DB::connection()->getDriverName() === 'pgsql'
            && Schema::hasColumn('document_chunks', 'embedding');

        foreach ($chunks as $idx => $chunkText) {
            $embedRes = $this->ai->embed($chunkText);
            $vector = $embedRes['vector'];
            $tokens = str_word_count($chunkText);

            /** @var DocumentChunk $row */
            $row = DocumentChunk::query()->create([
                'tenant_id' => $document->tenant_id,
                'document_id' => $document->id,
                'version_id' => $versionId,
                'classification_rank' => $document->classification_rank,
                'org_unit_id' => $document->org_unit_id,
                'chunk_index' => $idx,
                'content_text' => $chunkText,
                'embedding_json' => $vector,
                'embedding_dims' => count($vector),
                'token_count' => $tokens,
            ]);

            if ($hasVectorCol && count($vector) === 1024) {
                $vecLiteral = '['.implode(',', $vector).']';
                DB::update('UPDATE document_chunks SET embedding = ?::vector WHERE id = ?', [$vecLiteral, $row->id]);
            }
        }

        return count($chunks);
    }

    /**
     * Index any cleared tenant documents that do not yet have chunks on their current version.
     */
    public function syncMissingForTenant(string $tenantId): int
    {
        $unindexed = Document::query()
            ->where('tenant_id', $tenantId)
            ->whereNotExists(static function (Builder $q): void {
                $q->select(DB::raw(1))
                    ->from('document_chunks')
                    ->whereColumn('document_chunks.document_id', 'documents.id');
            })
            ->limit(25)
            ->get();

        $count = 0;
        foreach ($unindexed as $doc) {
            $count += $this->indexDocument($doc);
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function splitChunks(string $text, int $maxChars, int $overlap): array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        $len = mb_strlen($normalized);

        if ($len <= $maxChars) {
            return [$normalized];
        }

        $chunks = [];
        $offset = 0;
        $step = max(100, $maxChars - $overlap);

        while ($offset < $len) {
            $slice = trim(mb_substr($normalized, $offset, $maxChars));
            if ($slice !== '') {
                $chunks[] = $slice;
            }
            if ($offset + $maxChars >= $len) {
                break;
            }
            $offset += $step;
        }

        return $chunks;
    }
}
