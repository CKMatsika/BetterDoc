<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property string|null $version_id
 * @property int $classification_rank
 * @property string|null $org_unit_id
 * @property int $chunk_index
 * @property string $content_text
 * @property list<float> $embedding_json
 * @property int $embedding_dims
 * @property int $token_count
 * @property Carbon|null $created_at
 */
class DocumentChunk extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'document_id',
        'version_id',
        'classification_rank',
        'org_unit_id',
        'chunk_index',
        'content_text',
        'embedding_json',
        'embedding_dims',
        'token_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'classification_rank' => 'integer',
            'chunk_index' => 'integer',
            'embedding_json' => 'array',
            'embedding_dims' => 'integer',
            'token_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }
}
