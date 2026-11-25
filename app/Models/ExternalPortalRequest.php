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
 * @property string $created_by
 * @property string $cabinet_id
 * @property string $classification_level_id
 * @property string|null $document_id
 * @property string|null $vendor_id
 * @property string $request_type
 * @property string $title
 * @property string|null $instructions
 * @property string $recipient_name
 * @property string|null $recipient_email
 * @property string|null $recipient_phone
 * @property string|null $policy_number
 * @property string|null $claim_number
 * @property string $token_hash
 * @property string|null $otp_hash
 * @property string $status
 * @property int $max_uploads
 * @property int $uploads_count
 * @property Carbon $expires_at
 * @property Carbon|null $signed_at
 * @property string|null $signed_by_name
 * @property string|null $signer_id_number
 * @property string|null $signature_hash
 * @property string|null $signer_ip
 * @property list<string>|null $uploaded_doc_ids_json
 */
class ExternalPortalRequest extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'created_by',
        'cabinet_id',
        'classification_level_id',
        'document_id',
        'vendor_id',
        'request_type',
        'title',
        'instructions',
        'recipient_name',
        'recipient_email',
        'recipient_phone',
        'policy_number',
        'claim_number',
        'token_hash',
        'otp_hash',
        'status',
        'max_uploads',
        'uploads_count',
        'expires_at',
        'signed_at',
        'signed_by_name',
        'signer_id_number',
        'signature_hash',
        'signer_ip',
        'uploaded_doc_ids_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_uploads' => 'integer',
            'uploads_count' => 'integer',
            'expires_at' => 'datetime',
            'signed_at' => 'datetime',
            'uploaded_doc_ids_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Cabinet, $this>
     */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    /**
     * @return BelongsTo<ClearanceLevel, $this>
     */
    public function classificationLevel(): BelongsTo
    {
        return $this->belongsTo(ClearanceLevel::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
