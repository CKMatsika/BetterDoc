<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProfileCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property ProfileCode $profile_code
 * @property int $version
 * @property array<string, mixed> $config_json
 * @property bool $is_active
 */
class OrganizationProfile extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'profile_code',
        'version',
        'config_json',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile_code' => ProfileCode::class,
            'version' => 'integer',
            'config_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
