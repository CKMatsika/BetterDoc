<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnotationKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property string $version_id
 * @property string $created_by
 * @property int $page_number
 * @property AnnotationKind $kind
 * @property array{x: float, y: float, width: float, height: float} $coords_json
 * @property string|null $content
 * @property string|null $redacted_in_version_id
 * @property Carbon|null $created_at
 */
class DocumentAnnotation extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'document_id',
        'version_id',
        'created_by',
        'page_number',
        'kind',
        'coords_json',
        'content',
        'redacted_in_version_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'kind' => AnnotationKind::class,
            'coords_json' => 'array',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
