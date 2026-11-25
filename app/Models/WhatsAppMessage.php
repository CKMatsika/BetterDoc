<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $whatsapp_channel_id
 * @property string $direction
 * @property string|null $wa_message_id
 * @property string $from_phone
 * @property string|null $to_phone
 * @property string|null $sender_name
 * @property string $message_type
 * @property string|null $body_text
 * @property string|null $document_id
 * @property string $status
 * @property array<string, mixed>|null $metadata_json
 */
class WhatsAppMessage extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_messages';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'whatsapp_channel_id',
        'direction',
        'wa_message_id',
        'from_phone',
        'to_phone',
        'sender_name',
        'message_type',
        'body_text',
        'document_id',
        'status',
        'metadata_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WhatsAppChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(WhatsAppChannel::class, 'whatsapp_channel_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
