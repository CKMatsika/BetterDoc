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
 * @property string $code
 * @property string $name
 * @property string|null $tax_id_tin
 * @property string|null $vat_number
 * @property string $currency
 * @property string|null $peppol_endpoint_id
 * @property string|null $email
 * @property bool $is_active
 */
class Vendor extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'tax_id_tin',
        'vat_number',
        'currency',
        'peppol_endpoint_id',
        'email',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class)->latest();
    }

    /**
     * @return HasMany<ApInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(ApInvoice::class)->latest();
    }
}
