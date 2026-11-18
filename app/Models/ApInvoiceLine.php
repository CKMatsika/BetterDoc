<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $ap_invoice_id
 * @property int $line_number
 * @property string $sku
 * @property string $description
 * @property float $qty_invoiced
 * @property int $unit_price_cents
 * @property int $line_total_cents
 */
class ApInvoiceLine extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'ap_invoice_id',
        'line_number',
        'sku',
        'description',
        'qty_invoiced',
        'unit_price_cents',
        'line_total_cents',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'qty_invoiced' => 'float',
            'unit_price_cents' => 'integer',
            'line_total_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ApInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class, 'ap_invoice_id');
    }
}
