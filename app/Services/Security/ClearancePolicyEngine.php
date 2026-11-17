<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\PrincipalType;
use App\Enums\SentinelRole;
use App\Enums\UserStatus;
use App\Enums\WriteDownMode;
use App\Exceptions\InsufficientClearanceException;
use App\Exceptions\WriteDownViolationException;
use App\Models\BreakGlassSession;
use App\Models\Document;
use App\Models\DocumentAclGrant;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Profile\ProfileManager;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

class ClearancePolicyEngine
{
    public function __construct(
        private readonly ProfileManager $profiles,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * Evaluate MAC (Bell-LaPadula) + Compartments + HR Shield + ABAC + RBAC + DAC Grants.
     *
     * @param  array{ip?: string|null, session?: UserSession|null, bypass_abac?: bool}  $context
     */
    public function authorize(User $user, string $action, Document $document, array $context = []): PolicyDecision
    {
        if ($user->tenant_id !== $document->tenant_id) {
            return PolicyDecision::denyHidden('cross_tenant_denied');
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        // 1. Emergency Break-Glass Check (view only, active dual-approved session)
        if ($action === 'view') {
            $activeBreakGlass = BreakGlassSession::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->where('document_id', $document->id)
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            if ($activeBreakGlass) {
                return PolicyDecision::allow('break_glass_active', isBreakGlass: true);
            }
        }

        // 2. ABAC Context Check
        if (! ($context['bypass_abac'] ?? false)) {
            $abac = $this->checkAbac($user, $context['ip'] ?? null, $context['session'] ?? null);
            if (! $abac->allowed) {
                return $abac;
            }
        }

        // 3. MAC No-Read-Up (Clearance >= Classification)
        if ($user->clearance_rank < $document->classification_rank) {
            return PolicyDecision::denyHidden('insufficient_clearance');
        }

        // 4. Compartment Superset Check
        $requiredCompartments = [];
        foreach ($document->compartments()->pluck('compartments.id')->all() as $cid) {
            if (is_string($cid) || is_int($cid)) {
                $requiredCompartments[] = (string) $cid;
            }
        }
        if ($requiredCompartments !== []) {
            $userCompartments = $user->activeCompartmentIds();
            if (array_diff($requiredCompartments, $userCompartments) !== []) {
                return PolicyDecision::denyHidden('missing_compartment');
            }
        }

        // 5. HR Subject Shield (subject cannot view their own restricted disciplinary/medical record)
        if ($document->hr_restricted_from_subject && $document->hr_subject_user_id === $user->id) {
            return PolicyDecision::denyHidden('hr_subject_shield');
        }

        // 6. RBAC + Ownership + Unit Subtree + Explicit Grant
        // Note: SystemAdmin does NOT bypass MAC (already enforced in Steps 3-5) and cannot implicitly read documents without role/unit/grant.
        if ($action === 'view') {
            if ($document->owner_id === $user->id) {
                return PolicyDecision::allow('owner');
            }

            if ($this->hasValidGrant($user, $document, 'perm_view')) {
                return PolicyDecision::allow('explicit_acl_grant');
            }

            if ($document->unit_wide_readable && $this->isUserInAncestorUnit($user, $document)) {
                return PolicyDecision::allow('org_unit_hierarchy');
            }

            if ($user->hasRole([SentinelRole::RecordsOfficer->value, SentinelRole::Approver->value])) {
                if ($this->isUserInAncestorUnit($user, $document)) {
                    return PolicyDecision::allow('unit_officer_scope');
                }
            }

            return PolicyDecision::denyForbidden('no_discretionary_access');
        }

        if ($action === 'update' || $action === 'edit') {
            if ($document->is_worm) {
                return PolicyDecision::denyForbidden('document_is_worm_locked');
            }

            if ($user->hasRole(SentinelRole::Reader->value) && ! $user->hasAnyRole([SentinelRole::Author->value, SentinelRole::RecordsOfficer->value, SentinelRole::Approver->value])) {
                return PolicyDecision::denyForbidden('reader_cannot_edit');
            }

            if ($document->owner_id === $user->id || $this->hasValidGrant($user, $document, 'perm_edit')) {
                return PolicyDecision::allow('edit_authorized');
            }

            if ($user->hasRole(SentinelRole::RecordsOfficer->value) && $this->isUserInAncestorUnit($user, $document)) {
                return PolicyDecision::allow('records_officer_edit');
            }

            return PolicyDecision::denyForbidden('edit_not_granted');
        }

        if ($action === 'share') {
            if ($document->owner_id === $user->id || $this->hasValidGrant($user, $document, 'perm_share')) {
                return PolicyDecision::allow('share_authorized');
            }

            if ($user->hasRole(SentinelRole::RecordsOfficer->value) && $this->isUserInAncestorUnit($user, $document)) {
                return PolicyDecision::allow('records_officer_share');
            }

            return PolicyDecision::denyForbidden('share_not_granted');
        }

        if (in_array($action, ['download', 'print', 'sign'], true)) {
            $permCol = 'perm_'.$action;
            if ($document->owner_id === $user->id && ! $user->hasRole(SentinelRole::Reader->value)) {
                return PolicyDecision::allow('owner_'.$action);
            }

            if ($this->hasValidGrant($user, $document, $permCol)) {
                return PolicyDecision::allow('grant_'.$action);
            }

            if ($user->hasRole(SentinelRole::RecordsOfficer->value) && $this->isUserInAncestorUnit($user, $document)) {
                return PolicyDecision::allow('records_officer_'.$action);
            }

            return PolicyDecision::denyForbidden($action.'_not_granted');
        }

        if ($action === 'delete' || $action === 'declassify') {
            if ($document->legal_hold_active || $document->is_worm) {
                return PolicyDecision::denyForbidden('legal_hold_or_worm_active');
            }

            if (! $user->hasAnyRole([SentinelRole::RecordsOfficer->value, SentinelRole::Approver->value])) {
                return PolicyDecision::denyForbidden('insufficient_role_for_'.$action);
            }

            $level = $document->classificationLevel;
            if ($level !== null && $level->two_person_required) {
                return PolicyDecision::denyForbidden('requires_two_person_approval', requiresTwoPerson: true);
            }

            return PolicyDecision::allow($action.'_authorized');
        }

        return PolicyDecision::denyForbidden('unknown_action');
    }

    /**
     * Enforce Bell-LaPadula No-Write-Down (*-property) when reclassifying or saving into a lower-ranked level.
     */
    public function assertWriteDownAllowed(
        User $user,
        int $sourceClassificationRank,
        int $targetClassificationRank,
        bool $viaApprovedTwoPerson = false,
    ): void {
        if ($targetClassificationRank >= $sourceClassificationRank || $viaApprovedTwoPerson) {
            return;
        }

        $tenant = $user->tenant;
        $mode = $tenant !== null ? $this->profiles->writeDownMode($tenant) : WriteDownMode::Strict;

        if ($mode === WriteDownMode::Strict) {
            throw new WriteDownViolationException(
                'Bell-LaPadula No-Write-Down enforced: lowering classification requires an approved Two-Person Declassification request.'
            );
        }
    }

    /**
     * Pre-validate sharing recipient clearance & compartment eligibility.
     *
     * @param  array<string, bool>  $requestedPerms
     */
    public function assertCanShareToRecipient(
        User $grantor,
        Document $document,
        PrincipalType $principalType,
        string $principalId,
        array $requestedPerms,
        ?Request $request = null,
    ): void {
        $shareDecision = $this->authorize($grantor, 'share', $document, [
            'ip' => $request?->ip(),
            'bypass_abac' => $request === null,
        ]);

        if (! $shareDecision->allowed) {
            throw new InsufficientClearanceException('You do not have share permission on this document.');
        }

        // Prevent grantor from escalating permissions they don't personally possess
        foreach (['download', 'print', 'sign', 'edit'] as $permKey) {
            if (($requestedPerms['perm_'.$permKey] ?? false) === true) {
                $permDecision = $this->authorize($grantor, $permKey, $document, ['bypass_abac' => true]);
                if (! $permDecision->allowed) {
                    throw new InsufficientClearanceException("Cannot grant '{$permKey}' permission you do not hold.");
                }
            }
        }

        // Top Secret forbids group/unit/role shares in strict profiles
        if ($principalType !== PrincipalType::User && $document->classification_rank >= 4) {
            $this->audit->log(
                tenantId: $document->tenant_id,
                eventType: 'share.denied_top_secret_group',
                resourceType: 'document',
                resourceId: $document->id,
                actor: $grantor,
                classificationRank: $document->classification_rank,
                after: ['principal_type' => $principalType->value, 'principal_id' => $principalId],
                request: $request,
            );
            throw new InsufficientClearanceException('Top Secret records may only be shared with individually named cleared recipients.');
        }

        if ($principalType === PrincipalType::User) {
            $recipient = User::query()
                ->where('tenant_id', $document->tenant_id)
                ->where('id', $principalId)
                ->first();

            if ($recipient === null) {
                throw new InsufficientClearanceException('Recipient account not found in this organisation.');
            }

            if ($recipient->clearance_rank < $document->classification_rank) {
                $this->audit->log(
                    tenantId: $document->tenant_id,
                    eventType: 'share.denied_insufficient_clearance',
                    resourceType: 'document',
                    resourceId: $document->id,
                    actor: $grantor,
                    classificationRank: $document->classification_rank,
                    after: [
                        'recipient_id' => $recipient->id,
                        'recipient_rank' => $recipient->clearance_rank,
                        'required_rank' => $document->classification_rank,
                    ],
                    request: $request,
                );

                throw new InsufficientClearanceException(
                    'Share denied: recipient clearance rank is below document classification rank.'
                );
            }

            $docCompartments = [];
            foreach ($document->compartments()->pluck('compartments.id')->all() as $cid) {
                if (is_string($cid) || is_int($cid)) {
                    $docCompartments[] = (string) $cid;
                }
            }
            $recipientCompartments = $recipient->activeCompartmentIds();

            if (array_diff($docCompartments, $recipientCompartments) !== []) {
                $this->audit->log(
                    tenantId: $document->tenant_id,
                    eventType: 'share.denied_missing_compartment',
                    resourceType: 'document',
                    resourceId: $document->id,
                    actor: $grantor,
                    classificationRank: $document->classification_rank,
                    after: ['recipient_id' => $recipient->id],
                    request: $request,
                );

                throw new InsufficientClearanceException(
                    'Share denied: recipient does not hold all required need-to-know compartments.'
                );
            }
        }
    }

    private function checkAbac(User $user, ?string $ip, ?UserSession $session): PolicyDecision
    {
        if ($user->status !== UserStatus::Active) {
            return PolicyDecision::denyForbidden('account_not_active');
        }

        if ($user->locked_until !== null && $user->locked_until->isFuture()) {
            return PolicyDecision::denyForbidden('account_locked');
        }

        if ($ip !== null && is_array($user->allowed_cidrs) && $user->allowed_cidrs !== []) {
            if (! IpMatcher::matchesAny($ip, $user->allowed_cidrs)) {
                return PolicyDecision::denyForbidden('ip_subnet_denied');
            }
        }

        if (is_array($user->allowed_hours) && isset($user->allowed_hours['start'], $user->allowed_hours['end'])) {
            $nowUtc = now('UTC')->format('H:i');
            if ($nowUtc < $user->allowed_hours['start'] || $nowUtc > $user->allowed_hours['end']) {
                return PolicyDecision::denyForbidden('outside_allowed_hours');
            }
        }

        if ($user->mfa_enforced && $session !== null && $session->mfa_verified_at === null) {
            return PolicyDecision::denyForbidden('mfa_verification_required');
        }

        return PolicyDecision::allow('abac_ok');
    }

    private function hasValidGrant(User $user, Document $document, string $permColumn): bool
    {
        $ancestorUnitIds = $user->orgUnit?->ancestorAndSelfIds() ?? [$user->org_unit_id];
        $roleIds = [];
        foreach ($user->roles()->pluck('roles.id')->all() as $rid) {
            if (is_string($rid) || is_int($rid)) {
                $roleIds[] = (string) $rid;
            }
        }

        return DocumentAclGrant::query()
            ->where('document_id', $document->id)
            ->where('tenant_id', $document->tenant_id)
            ->where($permColumn, true)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($query) use ($user, $ancestorUnitIds, $roleIds): void {
                $query->where(function ($q) use ($user): void {
                    $q->where('principal_type', PrincipalType::User->value)
                        ->where('principal_id', $user->id);
                });

                if ($ancestorUnitIds !== []) {
                    $query->orWhere(function ($q) use ($ancestorUnitIds): void {
                        $q->where('principal_type', PrincipalType::OrgUnit->value)
                            ->whereIn('principal_id', $ancestorUnitIds);
                    });
                }

                if ($roleIds !== []) {
                    $query->orWhere(function ($q) use ($roleIds): void {
                        $q->where('principal_type', PrincipalType::Role->value)
                            ->whereIn('principal_id', $roleIds);
                    });
                }
            })
            ->exists();
    }

    private function isUserInAncestorUnit(User $user, Document $document): bool
    {
        if ($user->org_unit_id === $document->org_unit_id) {
            return true;
        }

        $docUnit = $document->orgUnit;
        $userUnit = $user->orgUnit;

        if ($docUnit === null || $userUnit === null) {
            return false;
        }

        return $docUnit->path === $userUnit->path || str_starts_with($docUnit->path, $userUnit->path.'.');
    }
}
