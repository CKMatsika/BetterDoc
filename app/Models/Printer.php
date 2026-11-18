<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrinterDriver;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $location
 * @property string $cups_queue_name
 * @property string $ipps_uri
 * @property PrinterDriver $driver
 * @property int $max_classification_rank
 * @property bool $supports_secure_release
 * @property bool $supports_duplex
 * @property bool $is_online
 * @property array<string, mixed>|null $capabilities_json
 */
class Printer extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'name',
        'location',
        'cups_queue_name',
        'ipps_uri',
        'driver',
        'max_classification_rank',
        'supports_secure_release',
        'supports_duplex',
        'is_online',
        'capabilities_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => PrinterDriver::class,
            'max_classification_rank' => 'integer',
            'supports_secure_release' => 'boolean',
            'supports_duplex' => 'boolean',
            'is_online' => 'boolean',
            'capabilities_json' => 'array',
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
     * @return HasMany<PrintJob, $this>
     */
    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }
}
