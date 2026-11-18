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
use Illuminate\Support\Carbon;

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
 * @property string|null $retention_schedule_id
 * @property Carbon|null $retention_expires_at
 * @property string $doc_number
 * @property string $title
 * @property string|null $summary
 * @property string|null $ocr_text
 * @property DocumentStatus $status
 * @property bool $unit_wide_readable
 * @property bool $legal_hold_active
 * @property string|null $legal_hold_reason
 * @property bool $is_worm
 * @property Carbon|null $worm_locked_at
 * @property Carbon|null $worm_retention_until
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
        'retention_schedule_id',
        'retention_expires_at',
        'doc_number',
        'title',
        'summary',
        'ocr_text',
        'status',
        'unit_wide_readable',
        'legal_hold_active',
        'legal_hold_reason',
        'is_worm',
        'worm_locked_at',
        'worm_retention_until',
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
            'retention_expires_at' => 'datetime',
            'worm_locked_at' => 'datetime',
            'worm_retention_until' => 'datetime',
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

    /**
     * @return HasMany<DocumentComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(DocumentComment::class)->whereNull('parent_id')->latest();
    }

    /**
     * @return HasMany<DocumentAnnotation, $this>
     */
    public function annotations(): HasMany
    {
        return $this->hasMany(DocumentAnnotation::class)->orderBy('page_number')->orderBy('created_at');
    }

    /**
     * @return HasMany<WorkflowInstance, $this>
     */
    public function workflowInstances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class)->latest();
    }

    /**
     * @return BelongsTo<RetentionSchedule, $this>
     */
    public function retentionSchedule(): BelongsTo
    {
        return $this->belongsTo(RetentionSchedule::class);
    }

    /**
     * @return HasMany<DispositionReview, $this>
     */
    public function dispositionReviews(): HasMany
    {
        return $this->hasMany(DispositionReview::class)->latest();
    }

    /**
     * @return HasMany<DocumentChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class)->orderBy('chunk_index');
    }

    /**
     * @return HasMany<IdpExtraction, $this>
     */
    public function idpExtractions(): HasMany
    {
        return $this->hasMany(IdpExtraction::class)->latest();
    }
}
