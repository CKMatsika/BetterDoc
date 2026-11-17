<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $session_id
 * @property string $ip_address
 * @property string $user_agent_hash
 * @property string|null $device_label
 * @property Carbon|null $mfa_verified_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $revoked_at
 */
class UserSession extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'session_id',
        'ip_address',
        'user_agent_hash',
        'device_label',
        'mfa_verified_at',
        'last_seen_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mfa_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
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

    public function isMfaFresh(int $ttlMinutes): bool
    {
        if ($this->mfa_verified_at === null) {
            return false;
        }

        return $this->mfa_verified_at->greaterThan(now()->subMinutes($ttlMinutes));
    }
}
