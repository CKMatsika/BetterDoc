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
 * @property string|null $created_by
 * @property string $name
 * @property string $erp_target
 * @property string|null $hostname
 * @property string|null $agent_version
 * @property string $status
 * @property string|null $pairing_code_hash
 * @property Carbon|null $pairing_expires_at
 * @property string|null $api_token_hash
 * @property list<string> $capabilities_json
 * @property Carbon|null $last_heartbeat_at
 * @property string|null $last_ip
 */
class ErpBridgeAgent extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'integration_connection_id',
        'created_by',
        'name',
        'erp_target',
        'hostname',
        'agent_version',
        'status',
        'pairing_code_hash',
        'pairing_expires_at',
        'api_token_hash',
        'capabilities_json',
        'last_heartbeat_at',
        'last_ip',
    ];

    /** @var list<string> */
    protected $hidden = [
        'pairing_code_hash',
        'api_token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capabilities_json' => 'array',
            'pairing_expires_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
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
     * @return HasMany<ErpBridgeJob, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(ErpBridgeJob::class);
    }
}
