<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UploadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string|null $document_id
 * @property string $filename
 * @property string $mime_type
 * @property int $total_size
 * @property int $chunk_size
 * @property int $total_chunks
 * @property array<array-key, array{size: int, sha256: string}> $uploaded_parts_json
 * @property string|null $sha256_expected
 * @property string $storage_temp_prefix
 * @property UploadStatus $status
 * @property Carbon $expires_at
 */
class UploadSession extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'document_id',
        'filename',
        'mime_type',
        'total_size',
        'chunk_size',
        'total_chunks',
        'uploaded_parts_json',
        'sha256_expected',
        'storage_temp_prefix',
        'status',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'uploaded_parts_json' => 'array',
            'status' => UploadStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return list<int>
     */
    public function missingParts(): array
    {
        $uploaded = $this->uploaded_parts_json;
        $missing = [];
        for ($i = 0; $i < $this->total_chunks; $i++) {
            if (! array_key_exists((string) $i, $uploaded)) {
                $missing[] = $i;
            }
        }

        return $missing;
    }

    public function bytesReceived(): int
    {
        $sum = 0;
        foreach ($this->uploaded_parts_json as $part) {
            $sum += (int) $part['size'];
        }

        return $sum;
    }
}
