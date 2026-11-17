<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkflowTaskStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $instance_id
 * @property string $node_id
 * @property string $node_label
 * @property string $node_type
 * @property string $assignee_id
 * @property string|null $delegated_to_id
 * @property WorkflowTaskStatus $status
 * @property string|null $decision
 * @property string|null $decision_comment
 * @property array<string, mixed>|null $form_data_json
 * @property string|null $approval_hash
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 */
class WorkflowTask extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'instance_id',
        'node_id',
        'node_label',
        'node_type',
        'assignee_id',
        'delegated_to_id',
        'status',
        'decision',
        'decision_comment',
        'form_data_json',
        'approval_hash',
        'due_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WorkflowTaskStatus::class,
            'form_data_json' => 'array',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegatedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_to_id');
    }
}
