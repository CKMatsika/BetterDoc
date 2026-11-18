<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LocalAgentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $workstation_name
 * @property string $agent_version
 * @property string|null $pairing_code_hash
 * @property string $token_hash
 * @property array<string, mixed>|null $capabilities_json
 * @property LocalAgentStatus $status
 * @property Carbon|null $last_seen_at
 * @property string|null $paired_ip
 */
class LocalAgent extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'workstation_name',
        'agent_version',
        'pairing_code_hash',
        'token_hash',
        'capabilities_json',
        'status',
        'last_seen_at',
        'paired_ip',
    ];

    /** @var list<string> */
    protected $hidden = [
        'pairing_code_hash',
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capabilities_json' => 'array',
            'status' => LocalAgentStatus::class,
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
