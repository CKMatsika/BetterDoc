<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DispositionAction;
use App\Enums\RetentionTrigger;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property RetentionTrigger $trigger_event
 * @property int $retention_days
 * @property DispositionAction $disposition_action
 * @property string|null $description
 * @property bool $is_active
 */
class RetentionSchedule extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'trigger_event',
        'retention_days',
        'disposition_action',
        'description',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_event' => RetentionTrigger::class,
            'retention_days' => 'integer',
            'disposition_action' => DispositionAction::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'retention_schedule_id');
    }
}
