<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SyncOutboxStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $sync_target_id
 * @property string|null $document_id
 * @property string $event_type
 * @property int $classification_rank
 * @property string $payload_sha256
 * @property string|null $storage_key
 * @property string|null $wrapped_dek
 * @property string|null $kek_key_id
 * @property SyncOutboxStatus $status
 * @property string|null $conflict_branch_label
 * @property string|null $conflict_details
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 */
class SyncOutboxEvent extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'sync_target_id',
        'document_id',
        'event_type',
        'classification_rank',
        'payload_sha256',
        'storage_key',
        'wrapped_dek',
        'kek_key_id',
        'status',
        'conflict_branch_label',
        'conflict_details',
        'attempts',
        'last_error',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'classification_rank' => 'integer',
            'status' => SyncOutboxStatus::class,
            'attempts' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SyncTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(SyncTarget::class, 'sync_target_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
