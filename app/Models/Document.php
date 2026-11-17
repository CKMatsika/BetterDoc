<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $cabinet_id
 * @property string|null $folder_id
 * @property string $org_unit_id
 * @property string $owner_id
 * @property string $classification_level_id
 * @property int $classification_rank
 * @property string|null $current_version_id
 * @property string $doc_number
 * @property string $title
 * @property string|null $summary
 * @property string|null $ocr_text
 * @property DocumentStatus $status
 * @property bool $unit_wide_readable
 * @property bool $legal_hold_active
 * @property bool $is_worm
 * @property bool $client_e2ee
 * @property string|null $hr_subject_user_id
 * @property bool $hr_restricted_from_subject
 * @property array<string, mixed>|null $metadata_json
 */
class Document extends Model
{
    use HasUuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'cabinet_id',
        'folder_id',
        'org_unit_id',
        'owner_id',
        'classification_level_id',
        'classification_rank',
        'current_version_id',
        'doc_number',
        'title',
        'summary',
        'ocr_text',
        'status',
        'unit_wide_readable',
        'legal_hold_active',
        'is_worm',
        'client_e2ee',
        'hr_subject_user_id',
        'hr_restricted_from_subject',
        'metadata_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'classification_rank' => 'integer',
            'status' => DocumentStatus::class,
            'unit_wide_readable' => 'boolean',
            'legal_hold_active' => 'boolean',
            'is_worm' => 'boolean',
            'client_e2ee' => 'boolean',
            'hr_restricted_from_subject' => 'boolean',
            'metadata_json' => 'array',
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
     * @return BelongsTo<Cabinet, $this>
     */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /**
     * @return BelongsTo<OrgUnit, $this>
     */
    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<ClearanceLevel, $this>
     */
    public function classificationLevel(): BelongsTo
    {
        return $this->belongsTo(ClearanceLevel::class, 'classification_level_id');
    }

    /**
     * @return BelongsToMany<Compartment, $this>
     */
    public function compartments(): BelongsToMany
    {
        return $this->belongsToMany(Compartment::class, 'document_compartments');
    }

    /**
     * @return HasMany<DocumentAclGrant, $this>
     */
    public function aclGrants(): HasMany
    {
        return $this->hasMany(DocumentAclGrant::class);
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)
            ->orderByDesc('version_major')
            ->orderByDesc('version_minor');
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /**
     * @return HasOne<DocumentLock, $this>
     */
    public function lock(): HasOne
    {
        return $this->hasOne(DocumentLock::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'document_tags');
    }

    /**
     * @return HasOne<CorrespondenceRegistry, $this>
     */
    public function correspondenceEntry(): HasOne
    {
        return $this->hasOne(CorrespondenceRegistry::class);
    }

    /**
     * @return HasMany<SignatureEnvelope, $this>
     */
    public function signatureEnvelopes(): HasMany
    {
        return $this->hasMany(SignatureEnvelope::class)->latest();
    }
}
