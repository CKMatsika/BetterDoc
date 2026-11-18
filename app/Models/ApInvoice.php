<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApInvoiceStatus;
use App\Enums\ZimraFiscalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $vendor_id
 * @property string|null $purchase_order_id
 * @property string $document_id
 * @property string|null $idp_extraction_id
 * @property string $invoice_number
 * @property Carbon $invoice_date
 * @property Carbon|null $due_date
 * @property string $currency
 * @property int $subtotal_cents
 * @property int $vat_cents
 * @property int $total_cents
 * @property ApInvoiceStatus $status
 * @property string $match_mode
 * @property array<string, mixed>|null $match_report_json
 * @property ZimraFiscalStatus $zimra_fiscal_status
 * @property string|null $zimra_receipt_no
 * @property string|null $zimra_qr_payload
 * @property int $zimra_attempts
 * @property string|null $ubl_xml_hash
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 */
class ApInvoice extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'vendor_id',
        'purchase_order_id',
        'document_id',
        'idp_extraction_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'currency',
        'subtotal_cents',
        'vat_cents',
        'total_cents',
        'status',
        'match_mode',
        'match_report_json',
        'zimra_fiscal_status',
        'zimra_receipt_no',
        'zimra_qr_payload',
        'zimra_attempts',
        'ubl_xml_hash',
        'approved_by',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal_cents' => 'integer',
            'vat_cents' => 'integer',
            'total_cents' => 'integer',
            'status' => ApInvoiceStatus::class,
            'match_report_json' => 'array',
            'zimra_fiscal_status' => ZimraFiscalStatus::class,
            'zimra_attempts' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<ApInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ApInvoiceLine::class)->orderBy('line_number');
    }
}
