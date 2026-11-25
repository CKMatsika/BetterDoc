<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ErpSystemType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $created_by
 * @property string $name
 * @property ErpSystemType $system_type
 * @property string $transport
 * @property string|null $base_url
 * @property string|null $credentials_encrypted
 * @property array<string, mixed>|null $field_mapping_json
 * @property bool $is_active
 * @property Carbon|null $last_sync_at
 */
class IntegrationConnection extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'created_by',
        'name',
        'system_type',
        'transport',
        'base_url',
        'credentials_encrypted',
        'field_mapping_json',
        'is_active',
        'last_sync_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'credentials_encrypted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'system_type' => ErpSystemType::class,
            'credentials_encrypted' => 'encrypted',
            'field_mapping_json' => 'array',
            'is_active' => 'boolean',
            'last_sync_at' => 'datetime',
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
     * @return HasMany<IntegrationWebhook, $this>
     */
    public function webhooks(): HasMany
    {
        return $this->hasMany(IntegrationWebhook::class);
    }
}
