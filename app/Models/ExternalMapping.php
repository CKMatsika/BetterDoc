<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $integration_connection_id
 * @property string $external_system
 * @property string $entity_type
 * @property string $external_id
 * @property string $internal_id
 * @property array<string, mixed>|null $metadata_json
 */
class ExternalMapping extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'integration_connection_id',
        'external_system',
        'entity_type',
        'external_id',
        'internal_id',
        'metadata_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<IntegrationConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
