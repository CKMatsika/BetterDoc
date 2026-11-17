<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HostingMode;
use App\Enums\ProfileCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property HostingMode $hosting_mode
 * @property bool $air_gapped
 * @property ProfileCode $active_profile_code
 */
class Tenant extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'name',
        'hosting_mode',
        'air_gapped',
        'active_profile_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hosting_mode' => HostingMode::class,
            'air_gapped' => 'boolean',
            'active_profile_code' => ProfileCode::class,
        ];
    }

    /**
     * @return HasMany<OrganizationProfile, $this>
     */
    public function profiles(): HasMany
    {
        return $this->hasMany(OrganizationProfile::class);
    }

    /**
     * @return HasOne<OrganizationProfile, $this>
     */
    public function activeProfile(): HasOne
    {
        return $this->hasOne(OrganizationProfile::class)->where('is_active', true);
    }

    /**
     * @return HasMany<OrgUnit, $this>
     */
    public function orgUnits(): HasMany
    {
        return $this->hasMany(OrgUnit::class);
    }

    /**
     * @return HasMany<ClearanceLevel, $this>
     */
    public function clearanceLevels(): HasMany
    {
        return $this->hasMany(ClearanceLevel::class)->orderBy('rank');
    }

    /**
     * @return HasMany<Compartment, $this>
     */
    public function compartments(): HasMany
    {
        return $this->hasMany(Compartment::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
