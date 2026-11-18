<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContractStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property string|null $vendor_id
 * @property string $contract_number
 * @property string $title
 * @property string $counterparty_name
 * @property Carbon $effective_date
 * @property Carbon $expiry_date
 * @property int $renewal_notice_days
 * @property int $value_cents
 * @property string $currency
 * @property ContractStatus $status
 * @property bool $auto_renew
 */
class Contract extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'document_id',
        'vendor_id',
        'contract_number',
        'title',
        'counterparty_name',
        'effective_date',
        'expiry_date',
        'renewal_notice_days',
        'value_cents',
        'currency',
        'status',
        'auto_renew',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'expiry_date' => 'date',
            'renewal_notice_days' => 'integer',
            'value_cents' => 'integer',
            'status' => ContractStatus::class,
            'auto_renew' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<ContractClause, $this>
     */
    public function clauses(): HasMany
    {
        return $this->hasMany(ContractClause::class)->latest();
    }

    /**
     * @return HasMany<ContractObligation, $this>
     */
    public function obligations(): HasMany
    {
        return $this->hasMany(ContractObligation::class)->orderBy('due_date');
    }
}
