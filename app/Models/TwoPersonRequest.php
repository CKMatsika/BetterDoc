<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TwoPersonActionType;
use App\Enums\TwoPersonStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property TwoPersonActionType $action_type
 * @property string $target_type
 * @property string $target_id
 * @property int $target_classification_rank
 * @property array<string, mixed> $payload_json
 * @property string $reason
 * @property string $requested_by
 * @property string|null $approved_by
 * @property TwoPersonStatus $status
 * @property string|null $decision_note
 * @property Carbon $expires_at
 * @property Carbon|null $decided_at
 */
class TwoPersonRequest extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'action_type',
        'target_type',
        'target_id',
        'target_classification_rank',
        'payload_json',
        'reason',
        'requested_by',
        'approved_by',
        'status',
        'decision_note',
        'expires_at',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action_type' => TwoPersonActionType::class,
            'status' => TwoPersonStatus::class,
            'target_classification_rank' => 'integer',
            'payload_json' => 'array',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
