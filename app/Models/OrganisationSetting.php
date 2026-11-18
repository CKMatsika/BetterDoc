<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property bool $is_public_default
 * @property string $profile_key
 * @property string $name
 * @property string $short_name
 * @property string $tagline
 * @property string|null $logo_path
 * @property string|null $logo_dark_path
 * @property string|null $favicon_path
 * @property string $primary_color
 * @property string $accent_color
 * @property string $welcome_title
 * @property string|null $welcome_text
 * @property string|null $notice_text
 * @property bool $notice_enabled
 * @property string|null $support_email
 * @property string|null $support_phone
 * @property string|null $support_hours
 * @property string|null $address
 * @property string|null $website
 * @property bool $show_powered_by
 * @property string|null $landing_mode
 * @property Carbon|null $updated_at
 */
class OrganisationSetting extends Model
{
    use HasUuids;

    protected $table = 'organisation_settings';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'is_public_default',
        'profile_key',
        'name',
        'short_name',
        'tagline',
        'logo_path',
        'logo_dark_path',
        'favicon_path',
        'primary_color',
        'accent_color',
        'welcome_title',
        'welcome_text',
        'notice_text',
        'notice_enabled',
        'support_email',
        'support_phone',
        'support_hours',
        'address',
        'website',
        'show_powered_by',
        'landing_mode',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public_default' => 'boolean',
            'notice_enabled' => 'boolean',
            'show_powered_by' => 'boolean',
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
