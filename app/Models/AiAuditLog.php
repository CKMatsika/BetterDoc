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
 * @property string $user_id
 * @property string|null $session_id
 * @property string $action
 * @property string $model_used
 * @property string $prompt_sha256
 * @property string $output_sha256
 * @property list<string>|null $retrieved_doc_ids
 * @property int $high_water_rank
 * @property int $latency_ms
 * @property Carbon|null $created_at
 */
class AiAuditLog extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'session_id',
        'action',
        'model_used',
        'prompt_sha256',
        'output_sha256',
        'retrieved_doc_ids',
        'high_water_rank',
        'latency_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retrieved_doc_ids' => 'array',
            'high_water_rank' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
