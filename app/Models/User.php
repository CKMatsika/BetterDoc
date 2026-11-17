<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $org_unit_id
 * @property string $clearance_level_id
 * @property int $clearance_rank
 * @property string|null $service_number
 * @property string|null $rank_title
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $mfa_enforced
 * @property string|null $smartcard_subject_dn
 * @property UserStatus $status
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_until
 * @property list<string>|null $allowed_cidrs
 * @property array{start?: string, end?: string}|null $allowed_hours
 * @property string $auth_source
 * @property Carbon|null $two_factor_confirmed_at
 */
class User extends Authenticatable implements PasskeyUser
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUuids;
    use Notifiable;
    use PasskeyAuthenticatable;
    use TwoFactorAuthenticatable;

    /** @var string */
    protected $guard_name = 'web';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'org_unit_id',
        'clearance_level_id',
        'clearance_rank',
        'service_number',
        'rank_title',
        'name',
        'email',
        'password',
        'mfa_enforced',
        'smartcard_subject_dn',
        'status',
        'failed_login_attempts',
        'locked_until',
        'allowed_cidrs',
        'allowed_hours',
        'auth_source',
        'password_changed_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'clearance_rank' => 'integer',
            'mfa_enforced' => 'boolean',
            'status' => UserStatus::class,
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'allowed_cidrs' => 'array',
            'allowed_hours' => 'array',
            'password_changed_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
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
     * @return BelongsTo<OrgUnit, $this>
     */
    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    /**
     * @return BelongsTo<ClearanceLevel, $this>
     */
    public function clearanceLevel(): BelongsTo
    {
        return $this->belongsTo(ClearanceLevel::class);
    }

    /**
     * @return BelongsToMany<Compartment, $this>
     */
    public function compartments(): BelongsToMany
    {
        return $this->belongsToMany(Compartment::class, 'user_compartments')
            ->withPivot(['granted_by', 'expires_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<UserSession, $this>
     */
    public function userSessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * Active non-expired compartment IDs for MAC/RLS evaluation.
     *
     * @return list<string>
     */
    public function activeCompartmentIds(): array
    {
        $ids = $this->compartments()
            ->where(function ($query): void {
                $query->whereNull('user_compartments.expires_at')
                    ->orWhere('user_compartments.expires_at', '>', now());
            })
            ->pluck('compartments.id')
            ->all();

        $result = [];
        foreach ($ids as $id) {
            if (is_string($id) || is_int($id)) {
                $result[] = (string) $id;
            }
        }

        return $result;
    }

    /**
     * @return HasMany<UserCertificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(UserCertificate::class)->latest();
    }

    public function hasMfaConfigured(): bool
    {
        return $this->two_factor_confirmed_at !== null || $this->hasPasskeysEnabled();
    }
}
