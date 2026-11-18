<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Compartment;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\ChunkIndexingService;
use Illuminate\Support\Facades\DB;
use Meilisearch\Client;

class DocumentSearchService
{
    public function __construct(
        private readonly ?ChunkIndexingService $chunks = null,
    ) {}

    /**
     * Permission-safe full-text search across cleared documents only (PostgreSQL GIN FTS + RLS or Meilisearch).
     *
     * @param  array{cabinet_id?: string|null, tag_id?: string|null, limit?: int}  $filters
     * @return array{
     *   hits: list<array<string, mixed>>,
     *   facets: array<int, int>,
     *   total: int,
     *   elapsed_ms: float,
     *   engine: string
     * }
     */
    public function search(User $user, string $query, array $filters = []): array
    {
        $started = microtime(true);
        $driver = config()->string('betterdoc.search.driver', 'postgres');
        $trimmed = trim($query);

        if ($driver === 'meilisearch' && $trimmed !== '' && $this->meilisearchClient() !== null) {
            return $this->searchMeilisearch($user, $trimmed, $filters, $started);
        }

        return $this->searchPostgres($user, $trimmed, $filters, $started);
    }

    /**
     * Permission-safe autocomplete returning only cleared document titles/numbers.
     *
     * @return list<array{id: string, doc_number: string, title: string, classification_rank: int}>
     */
    public function autocomplete(User $user, string $prefix, int $limit = 8): array
    {
        $trimmed = trim($prefix);
        if ($trimmed === '') {
            return [];
        }

        $safe = str_replace(['%', '_'], ['\\%', '\\_'], $trimmed);

        $docs = Document::query()
            ->where(function ($q) use ($safe): void {
                $q->where('title', 'ilike', "{$safe}%")
                    ->orWhere('doc_number', 'ilike', "{$safe}%")
                    ->orWhere('title', 'ilike', "% {$safe}%");
            })
            ->orderByDesc('updated_at')
            ->limit(min(20, max(1, $limit)))
            ->get(['id', 'doc_number', 'title', 'classification_rank']);

        $suggestions = [];
        foreach ($docs as $doc) {
            $suggestions[] = [
                'id' => $doc->id,
                'doc_number' => $doc->doc_number,
                'title' => $doc->title,
                'classification_rank' => $doc->classification_rank,
            ];
        }

        return $suggestions;
    }

    public function indexDocument(Document $document): void
    {
        $this->chunks?->indexDocument($document);

        if (config()->string('betterdoc.search.driver', 'postgres') !== 'meilisearch') {
            return;
        }

        $client = $this->meilisearchClient();
        if ($client === null) {
            return;
        }

        $document->loadMissing(['compartments', 'tags', 'aclGrants']);
        $principals = [
            "user:{$document->owner_id}",
            "unit:{$document->org_unit_id}",
        ];
        foreach ($document->aclGrants as $grant) {
            if ($grant->perm_view && ($grant->expires_at === null || $grant->expires_at->isFuture())) {
                $principals[] = "{$grant->principal_type->value}:{$grant->principal_id}";
            }
        }

        $client->index('betterdoc_documents')->addDocuments([[
            'id' => $document->id,
            'tenant_id' => $document->tenant_id,
            'cabinet_id' => $document->cabinet_id,
            'doc_number' => $document->doc_number,
            'title' => $document->title,
            'summary' => $document->summary ?? '',
            'ocr_text' => $document->ocr_text ?? '',
            'classification_rank' => $document->classification_rank,
            'required_compartments' => $document->compartments->pluck('id')->values()->all(),
            'acl_principals' => array_values(array_unique($principals)),
            'tags' => $document->tags->pluck('name')->values()->all(),
            'updated_at_ts' => $document->updated_at->timestamp ?? time(),
        ]]);
    }

    /**
     * @param  array{cabinet_id?: string|null, tag_id?: string|null, limit?: int}  $filters
     * @return array{hits: list<array<string, mixed>>, facets: array<int, int>, total: int, elapsed_ms: float, engine: string}
     */
    private function searchPostgres(User $user, string $query, array $filters, float $started): array
    {
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 50)));
        $baseQuery = Document::query()
            ->with(['classificationLevel', 'orgUnit', 'owner', 'compartments', 'cabinet', 'tags', 'currentVersion', 'lock']);

        if (! empty($filters['cabinet_id'])) {
            $baseQuery->where('cabinet_id', $filters['cabinet_id']);
        }

        if (! empty($filters['tag_id'])) {
            $tagId = $filters['tag_id'];
            $baseQuery->whereHas('tags', static fn ($q) => $q->where('tags.id', $tagId));
        }

        $snippets = [];
        if ($query !== '') {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $baseQuery->whereRaw("fts_vector @@ websearch_to_tsquery('english', ?)", [$query])
                    ->select('documents.*')
                    ->selectRaw("ts_rank_cd(fts_vector, websearch_to_tsquery('english', ?)) AS search_rank", [$query])
                    ->selectRaw(
                        "ts_headline('english', coalesce(title, '') || ' — ' || coalesce(summary, '') || ' ' || coalesce(ocr_text, ''), websearch_to_tsquery('english', ?), 'MaxFragments=2, MaxWords=25') AS snippet_html",
                        [$query]
                    )
                    ->orderByDesc('search_rank');
            } else {
                $baseQuery->where(function ($q) use ($query): void {
                    $q->where('title', 'like', "%{$query}%")
                        ->orWhere('doc_number', 'like', "%{$query}%")
                        ->orWhere('ocr_text', 'like', "%{$query}%");
                });
            }
        } else {
            $baseQuery->latest('updated_at');
        }

        $fetched = (clone $baseQuery)->limit($limit + 1)->get();
        $hasMore = $fetched->count() > $limit;
        $rows = $hasMore ? $fetched->take($limit)->values() : $fetched;

        $facets = [];
        $total = 0;

        if ($hasMore) {
            // Compute facets across all cleared documents when results exceed the page limit (RLS enforced).
            $facetRows = (clone $baseQuery)
                ->setEagerLoads([])
                ->reorder()
                ->select(['classification_rank', DB::raw('COUNT(*) AS cnt')])
                ->groupBy('classification_rank')
                ->get();

            foreach ($facetRows as $row) {
                $rawRank = $row->getAttribute('classification_rank');
                $rawCnt = $row->getAttribute('cnt');
                $rank = is_numeric($rawRank) ? (int) $rawRank : 0;
                $cnt = is_numeric($rawCnt) ? (int) $rawCnt : 0;
                $facets[$rank] = $cnt;
                $total += $cnt;
            }
        } else {
            foreach ($rows as $doc) {
                $rank = $doc->classification_rank;
                $facets[$rank] = ($facets[$rank] ?? 0) + 1;
                $total++;
            }
        }

        $hits = [];
        foreach ($rows as $doc) {
            $rawSnippet = $doc->getAttribute('snippet_html');
            $snippetText = is_string($rawSnippet) ? strip_tags($rawSnippet, '<b>') : ($doc->summary ?? '');

            $hits[] = [
                'id' => $doc->id,
                'doc_number' => $doc->doc_number,
                'title' => $doc->title,
                'summary' => $doc->summary,
                'snippet' => $snippetText,
                'status' => $doc->status->value,
                'classification_rank' => $doc->classification_rank,
                'classification' => [
                    'id' => $doc->classificationLevel?->id,
                    'code' => $doc->classificationLevel?->code,
                    'name' => $doc->classificationLevel?->name,
                    'color_hex' => $doc->classificationLevel?->color_hex,
                ],
                'cabinet_name' => $doc->cabinet?->name,
                'org_unit_code' => $doc->orgUnit?->code,
                'owner_name' => $doc->owner?->name,
                'compartments' => $doc->compartments->pluck('code')->values()->all(),
                'tags' => $doc->tags->map(static fn ($t): array => ['id' => $t->id, 'name' => $t->name, 'color_hex' => $t->color_hex])->values()->all(),
                'current_version' => $doc->currentVersion ? [
                    'id' => $doc->currentVersion->id,
                    'label' => $doc->currentVersion->label(),
                    'mime_type' => $doc->currentVersion->mime_type,
                    'page_count' => $doc->currentVersion->page_count,
                ] : null,
                'locked_by_name' => $doc->lock?->holder?->name,
                'legal_hold_active' => $doc->legal_hold_active,
                'is_worm' => $doc->is_worm,
                'updated_at' => $doc->updated_at?->toIso8601String(),
            ];
        }

        unset($snippets, $user);

        return [
            'hits' => $hits,
            'facets' => $facets,
            'total' => $total,
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
            'engine' => 'postgres_fts_rls',
        ];
    }

    /**
     * Pre-filtered Meilisearch query using negated compartment superset check + PostgreSQL RLS hydration.
     *
     * @param  array{cabinet_id?: string|null, tag_id?: string|null, limit?: int}  $filters
     * @return array{hits: list<array<string, mixed>>, facets: array<int, int>, total: int, elapsed_ms: float, engine: string}
     */
    private function searchMeilisearch(User $user, string $query, array $filters, float $started): array
    {
        $client = $this->meilisearchClient();
        if ($client === null) {
            return $this->searchPostgres($user, $query, $filters, $started);
        }

        $userCompartments = $user->activeCompartmentIds();
        $deniedCompartments = Compartment::query()
            ->where('tenant_id', $user->tenant_id)
            ->when($userCompartments !== [], static fn ($q) => $q->whereNotIn('id', $userCompartments))
            ->pluck('id')
            ->map(static fn (mixed $id): string => '"'.(is_string($id) ? $id : '').'"')
            ->all();

        $filterParts = [
            "tenant_id = \"{$user->tenant_id}\"",
            "classification_rank <= {$user->clearance_rank}",
        ];
        if ($deniedCompartments !== []) {
            $filterParts[] = 'required_compartments NOT IN ['.implode(', ', $deniedCompartments).']';
        }
        if (! empty($filters['cabinet_id'])) {
            $filterParts[] = "cabinet_id = \"{$filters['cabinet_id']}\"";
        }

        $res = $client->index('betterdoc_documents')->search($query, [
            'filter' => implode(' AND ', $filterParts),
            'facets' => ['classification_rank'],
            'limit' => min(100, max(1, (int) ($filters['limit'] ?? 50))),
        ]);

        $ids = [];
        foreach ($res->getHits() as $hit) {
            if (is_string($hit['id'] ?? null)) {
                $ids[] = $hit['id'];
            }
        }

        // Defense-in-depth re-hydration through PostgreSQL RLS
        $docs = $ids === []
            ? collect()
            : Document::query()
                ->with(['classificationLevel', 'orgUnit', 'owner', 'compartments', 'cabinet', 'tags', 'currentVersion', 'lock'])
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');

        $hits = [];
        foreach ($ids as $id) {
            $doc = $docs->get($id);
            if ($doc instanceof Document) {
                $hits[] = [
                    'id' => $doc->id,
                    'doc_number' => $doc->doc_number,
                    'title' => $doc->title,
                    'summary' => $doc->summary,
                    'snippet' => $doc->summary ?? '',
                    'status' => $doc->status->value,
                    'classification_rank' => $doc->classification_rank,
                    'classification' => [
                        'id' => $doc->classificationLevel?->id,
                        'code' => $doc->classificationLevel?->code,
                        'name' => $doc->classificationLevel?->name,
                        'color_hex' => $doc->classificationLevel?->color_hex,
                    ],
                    'cabinet_name' => $doc->cabinet?->name,
                    'org_unit_code' => $doc->orgUnit?->code,
                    'owner_name' => $doc->owner?->name,
                    'compartments' => $doc->compartments->pluck('code')->values()->all(),
                    'tags' => [],
                    'current_version' => null,
                    'locked_by_name' => $doc->lock?->holder?->name,
                    'legal_hold_active' => $doc->legal_hold_active,
                    'is_worm' => $doc->is_worm,
                    'updated_at' => $doc->updated_at?->toIso8601String(),
                ];
            }
        }

        return [
            'hits' => $hits,
            'facets' => [],
            'total' => count($hits),
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
            'engine' => 'meilisearch_rls',
        ];
    }

    private function meilisearchClient(): ?Client
    {
        $host = config()->string('betterdoc.search.meilisearch_host', '');
        $key = config()->string('betterdoc.search.meilisearch_key', '');
        if ($host === '' || $key === '') {
            return null;
        }

        return new Client($host, $key);
    }
}
