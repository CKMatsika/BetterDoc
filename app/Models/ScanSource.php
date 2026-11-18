<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScanSourceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property ScanSourceType $source_type
 * @property string $endpoint_or_path
 * @property string|null $target_cabinet_id
 * @property string|null $default_classification_level_id
 * @property bool $auto_ocr
 * @property bool $auto_split_barcode
 * @property bool $auto_deskew
 * @property bool $is_active
 * @property Carbon|null $last_polled_at
 */
class ScanSource extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'name',
        'source_type',
        'endpoint_or_path',
        'target_cabinet_id',
        'default_classification_level_id',
        'auto_ocr',
        'auto_split_barcode',
        'auto_deskew',
        'is_active',
        'last_polled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => ScanSourceType::class,
            'auto_ocr' => 'boolean',
            'auto_split_barcode' => 'boolean',
            'auto_deskew' => 'boolean',
            'is_active' => 'boolean',
            'last_polled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Cabinet, $this>
     */
    public function targetCabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class, 'target_cabinet_id');
    }

    /**
     * @return BelongsTo<ClearanceLevel, $this>
     */
    public function defaultClassificationLevel(): BelongsTo
    {
        return $this->belongsTo(ClearanceLevel::class, 'default_classification_level_id');
    }

    /**
     * @return HasMany<ScanBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(ScanBatch::class);
    }
}
