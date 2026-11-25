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
 * @property string|null $integration_connection_id
 * @property string $name
 * @property string $target_url
 * @property string $secret_encrypted
 * @property list<string> $events_json
 * @property bool $is_active
 * @property Carbon|null $last_dispatched_at
 * @property int|null $last_status_code
 * @property int $failure_count
 */
class IntegrationWebhook extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'integration_connection_id',
        'name',
        'target_url',
        'secret_encrypted',
        'events_json',
        'is_active',
        'last_dispatched_at',
        'last_status_code',
        'failure_count',
    ];

    /** @var list<string> */
    protected $hidden = [
        'secret_encrypted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret_encrypted' => 'encrypted',
            'events_json' => 'array',
            'is_active' => 'boolean',
            'last_dispatched_at' => 'datetime',
            'last_status_code' => 'integer',
            'failure_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<IntegrationConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    /**
     * @return HasMany<IntegrationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(IntegrationDelivery::class);
    }
}
