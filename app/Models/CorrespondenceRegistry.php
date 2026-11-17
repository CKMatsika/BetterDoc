<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CorrespondenceDirection;
use App\Enums\RegistryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property CorrespondenceDirection $direction
 * @property string $registry_ref_no
 * @property string|null $external_party
 * @property string|null $security_folio_no
 * @property string|null $physical_file_barcode
 * @property string|null $dispatch_method
 * @property Carbon|null $bring_up_date
 * @property string $registered_by
 * @property RegistryStatus $status
 */
class CorrespondenceRegistry extends Model
{
    use HasUuids;

    protected $table = 'correspondence_registry';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'document_id',
        'direction',
        'registry_ref_no',
        'external_party',
        'security_folio_no',
        'physical_file_barcode',
        'dispatch_method',
        'bring_up_date',
        'registered_by',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => CorrespondenceDirection::class,
            'status' => RegistryStatus::class,
            'bring_up_date' => 'date',
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
     * @return BelongsTo<User, $this>
     */
    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
