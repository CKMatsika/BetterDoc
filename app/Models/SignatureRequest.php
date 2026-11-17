<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SignatureRequestStatus;
use App\Enums\SignatureTier;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $envelope_id
 * @property string $signer_id
 * @property string|null $delegated_from_id
 * @property int $sign_order
 * @property SignatureTier $tier
 * @property SignatureRequestStatus $status
 * @property string $field_name
 * @property array{page: int, x: float|int, y: float|int, width: float|int, height: float|int} $field_coords_json
 * @property string|null $signature_reason
 * @property string|null $signature_location
 * @property string|null $drawn_stamp_svg
 * @property string|null $typed_name
 * @property string|null $user_certificate_id
 * @property string|null $cert_serial
 * @property string|null $tsa_token_serial
 * @property Carbon|null $tsa_timestamp
 * @property string|null $document_sha256_at_sign
 * @property string|null $signed_version_id
 * @property int|null $audit_event_id
 * @property string|null $decline_reason
 * @property Carbon|null $reminded_at
 * @property int $reminder_count
 * @property Carbon|null $signed_at
 */
class SignatureRequest extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'envelope_id',
        'signer_id',
        'delegated_from_id',
        'sign_order',
        'tier',
        'status',
        'field_name',
        'field_coords_json',
        'signature_reason',
        'signature_location',
        'drawn_stamp_svg',
        'typed_name',
        'user_certificate_id',
        'cert_serial',
        'tsa_token_serial',
        'tsa_timestamp',
        'document_sha256_at_sign',
        'signed_version_id',
        'audit_event_id',
        'decline_reason',
        'reminded_at',
        'reminder_count',
        'signed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sign_order' => 'integer',
            'tier' => SignatureTier::class,
            'status' => SignatureRequestStatus::class,
            'field_coords_json' => 'array',
            'tsa_timestamp' => 'datetime',
            'audit_event_id' => 'integer',
            'reminded_at' => 'datetime',
            'reminder_count' => 'integer',
            'signed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SignatureEnvelope, $this>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(SignatureEnvelope::class, 'envelope_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegatedFrom(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_from_id');
    }

    /**
     * @return BelongsTo<UserCertificate, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(UserCertificate::class, 'user_certificate_id');
    }
}
