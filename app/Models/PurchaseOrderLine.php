<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $purchase_order_id
 * @property int $line_number
 * @property string $sku
 * @property string $description
 * @property float $qty_ordered
 * @property int $unit_price_cents
 * @property int $tax_rate_bps
 */
class PurchaseOrderLine extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'purchase_order_id',
        'line_number',
        'sku',
        'description',
        'qty_ordered',
        'unit_price_cents',
        'tax_rate_bps',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'qty_ordered' => 'float',
            'unit_price_cents' => 'integer',
            'tax_rate_bps' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return HasMany<GoodsReceiptLine, $this>
     */
    public function receiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }
}
