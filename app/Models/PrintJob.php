<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrintJobStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property string|null $document_version_id
 * @property string $user_id
 * @property string $printer_id
 * @property string|null $cost_centre_id
 * @property PrintJobStatus $status
 * @property int $copies
 * @property int $page_count
 * @property string|null $release_pin_hash
 * @property string|null $rfid_badge_uid
 * @property int $pin_attempts
 * @property string|null $watermarked_storage_disk
 * @property string|null $watermarked_storage_key
 * @property string|null $watermarked_wrapped_dek
 * @property string|null $watermarked_kek_key_id
 * @property string|null $watermark_stamp_text
 * @property int $classification_rank
 * @property string|null $cups_job_id
 * @property Carbon|null $held_at
 * @property Carbon|null $released_at
 * @property Carbon|null $expires_at
 */
class PrintJob extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'document_id',
        'document_version_id',
        'user_id',
        'printer_id',
        'cost_centre_id',
        'status',
        'copies',
        'page_count',
        'release_pin_hash',
        'rfid_badge_uid',
        'pin_attempts',
        'watermarked_storage_disk',
        'watermarked_storage_key',
        'watermarked_wrapped_dek',
        'watermarked_kek_key_id',
        'watermark_stamp_text',
        'classification_rank',
        'cups_job_id',
        'held_at',
        'released_at',
        'expires_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'release_pin_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PrintJobStatus::class,
            'copies' => 'integer',
            'page_count' => 'integer',
            'pin_attempts' => 'integer',
            'classification_rank' => 'integer',
            'held_at' => 'datetime',
            'released_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Printer, $this>
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    /**
     * @return BelongsTo<CostCentre, $this>
     */
    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }
}
