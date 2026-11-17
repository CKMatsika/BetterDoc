<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AvStatus;
use App\Enums\CdrStatus;
use App\Enums\OcrStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property int $version_major
 * @property int $version_minor
 * @property bool $is_current
 * @property string $storage_disk
 * @property string $storage_key
 * @property string $original_filename
 * @property string $mime_type
 * @property int $file_size_bytes
 * @property string $sha256_hash
 * @property string $wrapped_dek
 * @property string $kek_key_id
 * @property AvStatus $av_status
 * @property CdrStatus $cdr_status
 * @property OcrStatus $ocr_status
 * @property bool $pdfa_compliant
 * @property int $page_count
 * @property string|null $extracted_text
 * @property string|null $version_note
 * @property array<string, mixed>|null $cdr_report_json
 * @property string $created_by
 */
class DocumentVersion extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'document_id',
        'version_major',
        'version_minor',
        'is_current',
        'storage_disk',
        'storage_key',
        'original_filename',
        'mime_type',
        'file_size_bytes',
        'sha256_hash',
        'wrapped_dek',
        'kek_key_id',
        'av_status',
        'cdr_status',
        'ocr_status',
        'pdfa_compliant',
        'page_count',
        'extracted_text',
        'version_note',
        'cdr_report_json',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_major' => 'integer',
            'version_minor' => 'integer',
            'is_current' => 'boolean',
            'file_size_bytes' => 'integer',
            'av_status' => AvStatus::class,
            'cdr_status' => CdrStatus::class,
            'ocr_status' => OcrStatus::class,
            'pdfa_compliant' => 'boolean',
            'page_count' => 'integer',
            'cdr_report_json' => 'array',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function label(): string
    {
        return "v{$this->version_major}.{$this->version_minor}";
    }
}
