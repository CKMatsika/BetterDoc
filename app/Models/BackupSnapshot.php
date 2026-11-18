<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackupTier;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $created_by
 * @property string $snapshot_label
 * @property BackupTier $backup_tier
 * @property string $storage_disk
 * @property string $storage_key
 * @property string $manifest_sha256
 * @property string $signature_hmac
 * @property string $wrapped_dek
 * @property string $kek_key_id
 * @property int $size_bytes
 * @property bool $includes_wal
 * @property string $status
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_restored_at
 * @property int|null $restore_duration_ms
 * @property Carbon|null $created_at
 */
class BackupSnapshot extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'created_by',
        'snapshot_label',
        'backup_tier',
        'storage_disk',
        'storage_key',
        'manifest_sha256',
        'signature_hmac',
        'wrapped_dek',
        'kek_key_id',
        'size_bytes',
        'includes_wal',
        'status',
        'verified_at',
        'last_restored_at',
        'restore_duration_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'backup_tier' => BackupTier::class,
            'size_bytes' => 'integer',
            'includes_wal' => 'boolean',
            'verified_at' => 'datetime',
            'last_restored_at' => 'datetime',
            'restore_duration_ms' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
