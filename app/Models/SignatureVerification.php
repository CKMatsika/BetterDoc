<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SignatureVerificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property string $document_version_id
 * @property SignatureVerificationStatus $overall_status
 * @property int $signatures_count
 * @property array<string, mixed> $details_json
 * @property Carbon $verified_at
 */
class SignatureVerification extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'document_id',
        'document_version_id',
        'overall_status',
        'signatures_count',
        'details_json',
        'verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'overall_status' => SignatureVerificationStatus::class,
            'signatures_count' => 'integer',
            'details_json' => 'array',
            'verified_at' => 'datetime',
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
}
