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
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int $version
 * @property array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $bpmn_graph_json
 * @property list<array<string, mixed>>|null $form_schema_json
 * @property int $sla_hours
 * @property bool $is_active
 * @property string $created_by
 * @property Carbon|null $created_at
 */
class WorkflowDefinition extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'key',
        'name',
        'description',
        'version',
        'bpmn_graph_json',
        'form_schema_json',
        'sla_hours',
        'is_active',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'bpmn_graph_json' => 'array',
            'form_schema_json' => 'array',
            'sla_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<WorkflowInstance, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class, 'definition_id');
    }
}
