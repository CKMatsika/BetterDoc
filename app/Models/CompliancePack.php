<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $generated_by
 * @property string $pack_name
 * @property string $regulation_standard
 * @property int $event_count
 * @property string $head_hash
 * @property string|null $checkpoint_id
 * @property string $storage_disk
 * @property string $storage_key
 * @property string $wrapped_dek
 * @property string $kek_key_id
 * @property string $manifest_sha256
 * @property string $signature_hmac
 * @property Carbon|null $created_at
 */
class CompliancePack extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'generated_by',
        'pack_name',
        'regulation_standard',
        'event_count',
        'head_hash',
        'checkpoint_id',
        'storage_disk',
        'storage_key',
        'wrapped_dek',
        'kek_key_id',
        'manifest_sha256',
        'signature_hmac',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @return BelongsTo<AuditCheckpoint, $this>
     */
    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(AuditCheckpoint::class, 'checkpoint_id');
    }
}
