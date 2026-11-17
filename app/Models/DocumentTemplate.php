<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TemplateCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property TemplateCategory $category
 * @property string $body_html
 * @property list<array{key: string, label: string, required?: bool, default?: string}> $placeholders_json
 * @property string|null $default_classification_level_id
 * @property string $auto_number_prefix
 * @property int $next_sequence
 * @property bool $is_active
 * @property string $created_by
 */
class DocumentTemplate extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'category',
        'body_html',
        'placeholders_json',
        'default_classification_level_id',
        'auto_number_prefix',
        'next_sequence',
        'is_active',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => TemplateCategory::class,
            'placeholders_json' => 'array',
            'next_sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ClearanceLevel, $this>
     */
    public function defaultClassification(): BelongsTo
    {
        return $this->belongsTo(ClearanceLevel::class, 'default_classification_level_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
