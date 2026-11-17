<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $parent_ca_id
 * @property string $name
 * @property string $role
 * @property string $serial_number
 * @property string $subject_dn
 * @property string $issuer_dn
 * @property string $certificate_pem
 * @property string $fingerprint_sha256
 * @property string $pkcs11_key_label
 * @property int $crl_number
 * @property Carbon $valid_from
 * @property Carbon $valid_to
 * @property Carbon|null $revoked_at
 */
class CertificateAuthority extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'parent_ca_id',
        'name',
        'role',
        'serial_number',
        'subject_dn',
        'issuer_dn',
        'certificate_pem',
        'fingerprint_sha256',
        'pkcs11_key_label',
        'crl_number',
        'valid_from',
        'valid_to',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'crl_number' => 'integer',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'revoked_at' => 'datetime',
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
     * @return BelongsTo<self, $this>
     */
    public function parentCa(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_ca_id');
    }

    /**
     * @return HasMany<UserCertificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(UserCertificate::class, 'ca_id');
    }
}
