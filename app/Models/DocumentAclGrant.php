<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrincipalType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property PrincipalType $principal_type
 * @property string $principal_id
 * @property bool $perm_view
 * @property bool $perm_download
 * @property bool $perm_print
 * @property bool $perm_sign
 * @property bool $perm_edit
 * @property bool $perm_share
 * @property string $granted_by
 * @property Carbon|null $expires_at
 */
class DocumentAclGrant extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'document_id',
        'principal_type',
        'principal_id',
        'perm_view',
        'perm_download',
        'perm_print',
        'perm_sign',
        'perm_edit',
        'perm_share',
        'granted_by',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'principal_type' => PrincipalType::class,
            'perm_view' => 'boolean',
            'perm_download' => 'boolean',
            'perm_print' => 'boolean',
            'perm_sign' => 'boolean',
            'perm_edit' => 'boolean',
            'perm_share' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
