<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnomalySeverity;
use App\Enums\AnomalyStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $user_id
 * @property string|null $document_id
 * @property string $alert_type
 * @property AnomalySeverity $severity
 * @property AnomalyStatus $status
 * @property string $title
 * @property array<string, mixed> $details_json
 * @property string|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 */
class AiAnomalyAlert extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'document_id',
        'alert_type',
        'severity',
        'status',
        'title',
        'details_json',
        'resolved_by',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => AnomalySeverity::class,
            'status' => AnomalyStatus::class,
            'details_json' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
