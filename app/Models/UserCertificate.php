<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CertificateCustodyMode;
use App\Enums\CertificateStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $ca_id
 * @property string $serial_number
 * @property string $subject_dn
 * @property string $issuer_dn
 * @property string $certificate_pem
 * @property string $fingerprint_sha256
 * @property CertificateCustodyMode $custody_mode
 * @property string $pkcs11_key_label
 * @property string|null $pkcs11_slot_id
 * @property CertificateStatus $status
 * @property Carbon $valid_from
 * @property Carbon $valid_to
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_reason
 * @property string|null $revoked_by
 * @property Carbon|null $crl_published_at
 */
class UserCertificate extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'user_id',
        'ca_id',
        'serial_number',
        'subject_dn',
        'issuer_dn',
        'certificate_pem',
        'fingerprint_sha256',
        'custody_mode',
        'pkcs11_key_label',
        'pkcs11_slot_id',
        'status',
        'valid_from',
        'valid_to',
        'revoked_at',
        'revocation_reason',
        'revoked_by',
        'crl_published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'custody_mode' => CertificateCustodyMode::class,
            'status' => CertificateStatus::class,
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'revoked_at' => 'datetime',
            'crl_published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CertificateAuthority, $this>
     */
    public function certificateAuthority(): BelongsTo
    {
        return $this->belongsTo(CertificateAuthority::class, 'ca_id');
    }

    public function isCurrentlyValid(): bool
    {
        return $this->status === CertificateStatus::Active
            && $this->revoked_at === null
            && $this->valid_from->isPast()
            && $this->valid_to->isFuture();
    }
}
