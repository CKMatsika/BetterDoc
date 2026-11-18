<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScanBatchStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $scan_source_id
 * @property string|null $submitted_by
 * @property string $cabinet_id
 * @property string $source_channel
 * @property string $original_filename
 * @property int $page_count
 * @property int $split_documents_count
 * @property ScanBatchStatus $status
 * @property array<string, mixed>|null $report_json
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 */
class ScanBatch extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'scan_source_id',
        'submitted_by',
        'cabinet_id',
        'source_channel',
        'original_filename',
        'page_count',
        'split_documents_count',
        'status',
        'report_json',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ScanBatchStatus::class,
            'page_count' => 'integer',
            'split_documents_count' => 'integer',
            'report_json' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ScanSource, $this>
     */
    public function scanSource(): BelongsTo
    {
        return $this->belongsTo(ScanSource::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<Cabinet, $this>
     */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }
}
