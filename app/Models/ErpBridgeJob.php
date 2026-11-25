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
 * @property string $erp_bridge_agent_id
 * @property string|null $triggered_by
 * @property string $job_type
 * @property string $status
 * @property array<string, mixed>|null $payload_json
 * @property array<string, mixed>|null $result_json
 * @property string|null $error_message
 * @property Carbon|null $claimed_at
 * @property Carbon|null $completed_at
 */
class ErpBridgeJob extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'erp_bridge_agent_id',
        'triggered_by',
        'job_type',
        'status',
        'payload_json',
        'result_json',
        'error_message',
        'claimed_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'result_json' => 'array',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ErpBridgeAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(ErpBridgeAgent::class, 'erp_bridge_agent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
