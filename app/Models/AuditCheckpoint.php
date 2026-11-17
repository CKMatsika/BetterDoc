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
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $first_seq_id
 * @property int $last_seq_id
 * @property int $event_count
 * @property string $head_hash
 * @property string $signature_hmac
 */
class AuditCheckpoint extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'period_start',
        'period_end',
        'first_seq_id',
        'last_seq_id',
        'event_count',
        'head_hash',
        'signature_hmac',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'first_seq_id' => 'integer',
            'last_seq_id' => 'integer',
            'event_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
