<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $parent_id
 * @property string $code
 * @property string $name
 * @property string $unit_type
 * @property string $path
 */
class OrgUnit extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'parent_id',
        'code',
        'name',
        'unit_type',
        'path',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<OrgUnit, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<OrgUnit, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Return all ancestor unit IDs including self using PostgreSQL ltree when available.
     *
     * @return list<string>
     */
    public function ancestorAndSelfIds(): array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            /** @var Collection<int, string> $ids */
            $ids = self::query()
                ->where('tenant_id', $this->tenant_id)
                ->whereRaw('?::ltree <@ path', [$this->path])
                ->pluck('id');

            return array_values($ids->map(static fn (mixed $id): string => (string) $id)->all());
        }

        $ids = [(string) $this->id];
        $current = $this;
        while ($current->parent_id !== null) {
            $parent = $current->parent;
            if ($parent === null) {
                break;
            }
            $ids[] = (string) $parent->id;
            $current = $parent;
        }

        return $ids;
    }
}
