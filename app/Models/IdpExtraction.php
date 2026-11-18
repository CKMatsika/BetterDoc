<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IdpDocType;
use App\Enums\IdpReviewStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property string|null $document_version_id
 * @property string $model_name
 * @property IdpDocType $detected_doc_type
 * @property array<string, array{label: string, value: string, confidence: float}> $extracted_fields_json
 * @property float $overall_confidence
 * @property IdpReviewStatus $review_status
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $reviewer_notes
 * @property Carbon|null $created_at
 */
class IdpExtraction extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'document_id',
        'document_version_id',
        'model_name',
        'detected_doc_type',
        'extracted_fields_json',
        'overall_confidence',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'reviewer_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detected_doc_type' => IdpDocType::class,
            'extracted_fields_json' => 'array',
            'overall_confidence' => 'float',
            'review_status' => IdpReviewStatus::class,
            'reviewed_at' => 'datetime',
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
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
