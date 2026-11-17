<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $document_id
 * @property string $tenant_id
 * @property string $locked_by
 * @property string $lock_type
 * @property string|null $checkout_note
 * @property Carbon $locked_at
 * @property Carbon $expires_at
 */
class DocumentLock extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'document_id';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'document_id',
        'tenant_id',
        'locked_by',
        'lock_type',
        'checkout_note',
        'locked_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function isActive(): bool
    {
        return $this->expires_at->isFuture();
    }
}
