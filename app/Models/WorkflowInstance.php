<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkflowInstanceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $definition_id
 * @property string $document_id
 * @property string $started_by
 * @property list<string> $current_nodes_json
 * @property array<string, mixed>|null $variables_json
 * @property WorkflowInstanceStatus $status
 * @property Carbon|null $sla_due_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 */
class WorkflowInstance extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'definition_id',
        'document_id',
        'started_by',
        'current_nodes_json',
        'variables_json',
        'status',
        'sla_due_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_nodes_json' => 'array',
            'variables_json' => 'array',
            'status' => WorkflowInstanceStatus::class,
            'sla_due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkflowDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'definition_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * @return HasMany<WorkflowTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class, 'instance_id')->orderBy('created_at');
    }
}
