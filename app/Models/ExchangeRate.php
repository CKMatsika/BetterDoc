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
 * @property string $base_currency
 * @property string $quote_currency
 * @property float $rate
 * @property Carbon $effective_date
 * @property string $source
 * @property string|null $recorded_by
 */
class ExchangeRate extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'base_currency',
        'quote_currency',
        'rate',
        'effective_date',
        'source',
        'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'effective_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
