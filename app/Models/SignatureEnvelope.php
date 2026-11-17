<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnvelopeMode;
use App\Enums\EnvelopeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property string $source_version_id
 * @property string|null $working_version_id
 * @property string|null $sealed_version_id
 * @property string|null $certificate_storage_key
 * @property string|null $certificate_wrapped_dek
 * @property string|null $certificate_kek_key_id
 * @property string $created_by
 * @property EnvelopeMode $mode
 * @property EnvelopeStatus $status
 * @property string $title
 * @property string|null $message
 * @property string $verification_token
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 */
class SignatureEnvelope extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'document_id',
        'source_version_id',
        'working_version_id',
        'sealed_version_id',
        'certificate_storage_key',
        'certificate_wrapped_dek',
        'certificate_kek_key_id',
        'created_by',
        'mode',
        'status',
        'title',
        'message',
        'verification_token',
        'expires_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => EnvelopeMode::class,
            'status' => EnvelopeStatus::class,
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
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
    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'source_version_id');
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function workingVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'working_version_id');
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function sealedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'sealed_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<SignatureRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(SignatureRequest::class, 'envelope_id')
            ->orderBy('sign_order');
    }
}
