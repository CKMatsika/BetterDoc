<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $tax_id_tin
 * @property string|null $vat_number
 * @property string|null $itf263_certificate_no
 * @property Carbon|null $itf263_expires_at
 * @property string|null $itf263_document_id
 * @property string|null $praz_registration_no
 * @property Carbon|null $praz_expires_at
 * @property string|null $nssa_clearance_no
 * @property Carbon|null $nssa_expires_at
 * @property array<string, mixed>|null $compliance_docs_json
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
        'itf263_certificate_no',
        'itf263_expires_at',
        'itf263_document_id',
        'praz_registration_no',
        'praz_expires_at',
        'nssa_clearance_no',
        'nssa_expires_at',
        'compliance_docs_json',
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
            'itf263_expires_at' => 'date',
            'praz_expires_at' => 'date',
            'nssa_expires_at' => 'date',
            'compliance_docs_json' => 'array',
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
     * @return BelongsTo<Document, $this>
     */
    public function itf263Document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'itf263_document_id');
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
