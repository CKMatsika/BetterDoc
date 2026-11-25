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
 * @property string $integration_webhook_id
 * @property string $event_type
 * @property array<string, mixed> $payload_json
 * @property string $status
 * @property int $attempts
 * @property int|null $response_code
 * @property string|null $response_excerpt
 * @property Carbon|null $dispatched_at
 */
class IntegrationDelivery extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'integration_webhook_id',
        'event_type',
        'payload_json',
        'status',
        'attempts',
        'response_code',
        'response_excerpt',
        'dispatched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'attempts' => 'integer',
            'response_code' => 'integer',
            'dispatched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<IntegrationWebhook, $this>
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(IntegrationWebhook::class, 'integration_webhook_id');
    }
}
