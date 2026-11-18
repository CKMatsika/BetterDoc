<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiMessageRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $session_id
 * @property AiMessageRole $role
 * @property string $content
 * @property list<string>|null $cited_doc_ids
 * @property list<array<string, mixed>>|null $cited_chunks_json
 * @property int $high_water_rank
 * @property list<string>|null $high_water_compartments
 * @property string $model_used
 * @property int $latency_ms
 * @property Carbon|null $created_at
 */
class AiChatMessage extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'session_id',
        'role',
        'content',
        'cited_doc_ids',
        'cited_chunks_json',
        'high_water_rank',
        'high_water_compartments',
        'model_used',
        'latency_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AiMessageRole::class,
            'cited_doc_ids' => 'array',
            'cited_chunks_json' => 'array',
            'high_water_rank' => 'integer',
            'high_water_compartments' => 'array',
            'latency_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AiChatSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AiChatSession::class, 'session_id');
    }
}
