<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $name
 * @property array<string, mixed> $filter_json
 * @property bool $is_shared
 */
class SavedSmartView extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'filter_json',
        'is_shared',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filter_json' => 'array',
            'is_shared' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
