<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DispositionAction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property string $retention_schedule_id
 * @property string $initiated_by
 * @property string|null $two_person_request_id
 * @property DispositionAction $proposed_action
 * @property string $status
 * @property Carbon|null $decided_at
 * @property string|null $notes
 */
class DispositionReview extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'document_id',
        'retention_schedule_id',
        'initiated_by',
        'two_person_request_id',
        'proposed_action',
        'status',
        'decided_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proposed_action' => DispositionAction::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<RetentionSchedule, $this>
     */
    public function retentionSchedule(): BelongsTo
    {
        return $this->belongsTo(RetentionSchedule::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /**
     * @return BelongsTo<TwoPersonRequest, $this>
     */
    public function twoPersonRequest(): BelongsTo
    {
        return $this->belongsTo(TwoPersonRequest::class);
    }
}
