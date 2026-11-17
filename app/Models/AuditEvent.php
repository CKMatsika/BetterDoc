<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $seq_id
 * @property string $tenant_id
 * @property Carbon $occurred_at
 * @property string|null $actor_user_id
 * @property string|null $actor_ip
 * @property string|null $actor_device_id
 * @property string $event_type
 * @property string $resource_type
 * @property string|null $resource_id
 * @property int $classification_rank
 * @property array<string, mixed>|null $before_json
 * @property array<string, mixed>|null $after_json
 * @property string $prev_hash
 * @property string $entry_hash
 */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'seq_id';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'occurred_at',
        'actor_user_id',
        'actor_ip',
        'actor_device_id',
        'event_type',
        'resource_type',
        'resource_id',
        'classification_rank',
        'before_json',
        'after_json',
        'prev_hash',
        'entry_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'classification_rank' => 'integer',
            'before_json' => 'array',
            'after_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
