<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property int $rank
 * @property string $code
 * @property string $name
 * @property string $color_hex
 * @property string $watermark_mode
 * @property bool $two_person_required
 * @property bool $secure_print_required
 * @property bool $cloud_sync_allowed
 * @property bool $e2ee_required
 */
class ClearanceLevel extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'rank',
        'code',
        'name',
        'color_hex',
        'watermark_mode',
        'two_person_required',
        'secure_print_required',
        'cloud_sync_allowed',
        'e2ee_required',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'two_person_required' => 'boolean',
            'secure_print_required' => 'boolean',
            'cloud_sync_allowed' => 'boolean',
            'e2ee_required' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
