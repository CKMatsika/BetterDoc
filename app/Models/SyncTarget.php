<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SyncTargetDriver;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property SyncTargetDriver $driver
 * @property string|null $endpoint_url
 * @property string $bucket_or_site
 * @property int $max_classification_rank
 * @property string $conflict_strategy
 * @property bool $enabled
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 */
class SyncTarget extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'name',
        'driver',
        'endpoint_url',
        'bucket_or_site',
        'max_classification_rank',
        'conflict_strategy',
        'enabled',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => SyncTargetDriver::class,
            'max_classification_rank' => 'integer',
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
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
     * @return HasMany<SyncOutboxEvent, $this>
     */
    public function outboxEvents(): HasMany
    {
        return $this->hasMany(SyncOutboxEvent::class);
    }
}
