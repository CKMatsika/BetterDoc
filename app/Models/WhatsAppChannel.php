<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $created_by
 * @property string $display_name
 * @property string $phone_number_id
 * @property string|null $business_account_id
 * @property string $verify_token_hash
 * @property string|null $app_secret_encrypted
 * @property string|null $access_token_encrypted
 * @property string $default_cabinet_id
 * @property string $default_classification_level_id
 * @property bool $auto_idp_invoices
 * @property bool $is_active
 * @property Carbon|null $last_inbound_at
 */
class WhatsAppChannel extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_channels';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'created_by',
        'display_name',
        'phone_number_id',
        'business_account_id',
        'verify_token_hash',
        'app_secret_encrypted',
        'access_token_encrypted',
        'default_cabinet_id',
        'default_classification_level_id',
        'auto_idp_invoices',
        'is_active',
        'last_inbound_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'app_secret_encrypted' => 'encrypted',
            'access_token_encrypted' => 'encrypted',
            'auto_idp_invoices' => 'boolean',
            'is_active' => 'boolean',
            'last_inbound_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Cabinet, $this>
     */
    public function defaultCabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class, 'default_cabinet_id');
    }

    /**
     * @return BelongsTo<ClearanceLevel, $this>
     */
    public function defaultClassificationLevel(): BelongsTo
    {
        return $this->belongsTo(ClearanceLevel::class, 'default_classification_level_id');
    }

    /**
     * @return HasMany<WhatsAppMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'whatsapp_channel_id')->latest();
    }
}
