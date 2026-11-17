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
 * @property string $user_id
 * @property string $document_id
 * @property string|null $first_approver_id
 * @property string|null $second_approver_id
 * @property string $justification
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
class BreakGlassSession extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'document_id',
        'first_approver_id',
        'second_approver_id',
        'justification',
        'status',
        'started_at',
        'expires_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_approver_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_approver_id');
    }

    public function isCurrentlyActive(): bool
    {
        return $this->status === 'active'
            && $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
