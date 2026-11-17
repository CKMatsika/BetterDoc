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
 * @property string $document_version_id
 * @property string $idempotency_key
 * @property string $stage
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $heartbeat_at
 */
class ProcessingJob extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'document_version_id',
        'idempotency_key',
        'stage',
        'status',
        'attempts',
        'last_error',
        'heartbeat_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'heartbeat_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }
}
