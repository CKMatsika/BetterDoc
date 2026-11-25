<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\ExternalPortalRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\PrintScan\ScanIngestionService;
use App\Services\Search\DocumentSearchService;
use App\Services\Security\DbSecurityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ExternalPortalService
{
    public function __construct(
        private readonly DbSecurityContext $dbContext,
        private readonly ScanIngestionService $scanIngestion,
        private readonly DocumentSearchService $search,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * Create a branded, expiring, optionally OTP-protected magic-link portal request
     * for external clients, brokers, claimants, or suppliers to upload files and/or e-sign.
     *
     * @param  array{
     *     cabinet_id: string,
     *     classification_level_id: string,
     *     document_id?: string|null,
     *     vendor_id?: string|null,
     *     request_type: string,
     *     title: string,
     *     instructions?: string|null,
     *     recipient_name: string,
     *     recipient_email?: string|null,
     *     recipient_phone?: string|null,
     *     policy_number?: string|null,
     *     claim_number?: string|null,
     *     require_otp?: bool,
     *     max_uploads?: int,
     *     expires_in_hours?: int
     * }  $data
     * @return array{portal_request: ExternalPortalRequest, plain_token: string, plain_otp: string|null, public_url: string}
     */
    public function createRequest(User $actor, array $data, ?Request $request = null): array
    {
        /** @var Cabinet $cabinet */
        $cabinet = Cabinet::query()->where('tenant_id', $actor->tenant_id)->findOrFail($data['cabinet_id']);
        /** @var ClearanceLevel $level */
        $level = ClearanceLevel::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('rank', '<=', $actor->clearance_rank)
            ->findOrFail($data['classification_level_id']);

        $documentId = null;
        if (! empty($data['document_id'])) {
            $doc = Document::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('classification_rank', '<=', $actor->clearance_rank)
                ->findOrFail($data['document_id']);
            $documentId = $doc->id;
        }

        $plainToken = 'bdp_'.Str::random(40);
        $plainOtp = (bool) ($data['require_otp'] ?? false)
            ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
            : null;

        $hours = max(1, min(720, (int) ($data['expires_in_hours'] ?? 72)));

        /** @var ExternalPortalRequest $portalReq */
        $portalReq = ExternalPortalRequest::query()->create([
            'tenant_id' => $actor->tenant_id,
            'created_by' => $actor->id,
            'cabinet_id' => $cabinet->id,
            'classification_level_id' => $level->id,
            'document_id' => $documentId,
            'vendor_id' => $data['vendor_id'] ?? null,
            'request_type' => $data['request_type'],
            'title' => trim($data['title']),
            'instructions' => isset($data['instructions']) ? trim($data['instructions']) : null,
            'recipient_name' => trim($data['recipient_name']),
            'recipient_email' => $data['recipient_email'] ?? null,
            'recipient_phone' => $data['recipient_phone'] ?? null,
            'policy_number' => $data['policy_number'] ?? null,
            'claim_number' => $data['claim_number'] ?? null,
            'token_hash' => hash('sha256', $plainToken),
            'otp_hash' => $plainOtp !== null ? hash('sha256', $plainOtp) : null,
            'status' => 'active',
            'max_uploads' => max(1, min(25, (int) ($data['max_uploads'] ?? 5))),
            'uploads_count' => 0,
            'expires_at' => now()->addHours($hours),
            'uploaded_doc_ids_json' => [],
        ]);

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'portal.request_created',
            resourceType: 'external_portal_request',
            resourceId: $portalReq->id,
            actor: $actor,
            classificationRank: $level->rank,
            after: [
                'request_type' => $portalReq->request_type,
                'recipient_name' => $portalReq->recipient_name,
                'policy_number' => $portalReq->policy_number,
                'claim_number' => $portalReq->claim_number,
                'otp_protected' => $plainOtp !== null,
                'expires_at' => $portalReq->expires_at->toIso8601String(),
            ],
            request: $request,
        );

        return [
            'portal_request' => $portalReq,
            'plain_token' => $plainToken,
            'plain_otp' => $plainOtp,
            'public_url' => url("/portal/{$plainToken}"),
        ];
    }

    /**
     * Resolve an external portal request safely across tenants using SECURITY DEFINER lookup.
     *
     * @return array{portal_request: ExternalPortalRequest, tenant: Tenant}|null
     */
    public function resolveByToken(string $plainToken): ?array
    {
        $trimmed = trim($plainToken);
        if ($trimmed === '') {
            return null;
        }

        $ref = $this->lookupTokenRef(hash('sha256', $trimmed));
        if ($ref === null) {
            return null;
        }

        return $this->dbContext->runForTenant($ref['tenant_id'], function () use ($ref): ?array {
            /** @var ExternalPortalRequest|null $portalReq */
            $portalReq = ExternalPortalRequest::query()
                ->with(['document', 'cabinet', 'classificationLevel', 'creator'])
                ->find($ref['request_id']);
            if ($portalReq === null) {
                return null;
            }

            /** @var Tenant $tenant */
            $tenant = Tenant::query()->findOrFail($ref['tenant_id']);

            return [
                'portal_request' => $portalReq,
                'tenant' => $tenant,
            ];
        });
    }

    /**
     * Handle an unauthenticated external client/broker/supplier document upload through the magic link.
     */
    public function submitUpload(
        string $plainToken,
        string $filename,
        string $rawBytes,
        ?string $otp,
        ?string $uploaderNote,
        ?Request $request = null,
    ): Document {
        $resolved = $this->resolveByToken($plainToken);
        if ($resolved === null) {
            throw new RuntimeException('Invalid or unknown portal link.');
        }

        $portalReq = $resolved['portal_request'];
        $tenant = $resolved['tenant'];

        $this->assertPortalUsable($portalReq, $otp);

        if (! in_array($portalReq->request_type, ['upload', 'upload_and_esign'], true)) {
            throw new RuntimeException('This portal link is configured for e-signing only.');
        }

        if ($portalReq->uploads_count >= $portalReq->max_uploads) {
            throw new RuntimeException('Maximum upload limit reached for this portal link.');
        }

        $creator = $portalReq->creator;
        if (! $creator instanceof User) {
            throw new RuntimeException('Portal request issuer is unavailable.');
        }

        return $this->dbContext->runAs($creator, function () use (
            $tenant,
            $creator,
            $portalReq,
            $filename,
            $rawBytes,
            $uploaderNote,
            $request,
        ): Document {
            /** @var Cabinet|null $cabinet */
            $cabinet = Cabinet::query()->where('tenant_id', $tenant->id)->find($portalReq->cabinet_id);
            /** @var ClearanceLevel|null $level */
            $level = ClearanceLevel::query()->where('tenant_id', $tenant->id)->find($portalReq->classification_level_id);
            if ($cabinet === null || $level === null) {
                throw new RuntimeException('Portal request target cabinet or clearance level is unavailable.');
            }

            $batch = $this->scanIngestion->ingestBatch(
                tenant: $tenant,
                actor: $creator,
                cabinet: $cabinet,
                defaultLevel: $level,
                sourceChannel: 'external_portal',
                filename: $filename,
                rawBytes: $rawBytes,
                options: [
                    'auto_split_barcode' => false,
                    'auto_deskew' => true,
                    'auto_idp' => true,
                    'title_prefix' => "{$portalReq->recipient_name} — {$portalReq->title}",
                ],
                request: $request,
            );

            $createdIds = is_array($batch->report_json['created_doc_ids'] ?? null)
                ? array_values(array_filter($batch->report_json['created_doc_ids'], 'is_string'))
                : [];

            if ($createdIds === []) {
                throw new RuntimeException('External upload could not be catalogued.');
            }

            /** @var Document $doc */
            $doc = Document::query()->where('tenant_id', $tenant->id)->findOrFail($createdIds[0]);

            $meta = is_array($doc->metadata_json) ? $doc->metadata_json : [];
            $meta['external_portal'] = [
                'portal_request_id' => $portalReq->id,
                'recipient_name' => $portalReq->recipient_name,
                'recipient_email' => $portalReq->recipient_email,
                'uploader_note' => $uploaderNote,
                'uploaded_ip' => $request?->ip(),
                'uploaded_at' => now('UTC')->toIso8601String(),
            ];

            $doc->update([
                'policy_number' => $portalReq->policy_number ?? $doc->policy_number,
                'claim_number' => $portalReq->claim_number ?? $doc->claim_number,
                'metadata_json' => $meta,
            ]);
            $this->search->indexDocument($doc->refresh());

            $existingUploaded = is_array($portalReq->uploaded_doc_ids_json) ? $portalReq->uploaded_doc_ids_json : [];
            $existingUploaded[] = $doc->id;

            $nextCount = $portalReq->uploads_count + 1;
            $nextStatus = ($portalReq->request_type === 'upload' && $nextCount >= $portalReq->max_uploads)
                ? 'completed'
                : $portalReq->status;

            $portalReq->update([
                'uploads_count' => $nextCount,
                'uploaded_doc_ids_json' => $existingUploaded,
                'status' => $nextStatus,
            ]);

            $this->audit->log(
                tenantId: $tenant->id,
                eventType: 'portal.external_document_uploaded',
                resourceType: 'document',
                resourceId: $doc->id,
                actor: $creator,
                classificationRank: $doc->classification_rank,
                after: [
                    'portal_request_id' => $portalReq->id,
                    'recipient_name' => $portalReq->recipient_name,
                    'policy_number' => $portalReq->policy_number,
                    'claim_number' => $portalReq->claim_number,
                    'ip' => $request?->ip(),
                ],
                request: $request,
            );

            return $doc;
        });
    }

    /**
     * Record a tamper-evident external e-signature attestation via the magic link.
     */
    public function submitSignature(
        string $plainToken,
        string $signerName,
        string $signerIdNumber,
        ?string $otp,
        ?Request $request = null,
    ): ExternalPortalRequest {
        $resolved = $this->resolveByToken($plainToken);
        if ($resolved === null) {
            throw new RuntimeException('Invalid or unknown portal link.');
        }

        $portalReq = $resolved['portal_request'];
        $tenant = $resolved['tenant'];

        $this->assertPortalUsable($portalReq, $otp);

        if (! in_array($portalReq->request_type, ['esign', 'upload_and_esign'], true)) {
            throw new RuntimeException('This portal link is configured for document uploads only.');
        }

        if ($portalReq->signed_at !== null) {
            throw new RuntimeException('This document has already been signed.');
        }

        $creator = $portalReq->creator;
        if (! $creator instanceof User) {
            throw new RuntimeException('Portal request issuer is unavailable.');
        }

        return $this->dbContext->runAs($creator, function () use (
            $tenant,
            $creator,
            $portalReq,
            $signerName,
            $signerIdNumber,
            $request,
        ): ExternalPortalRequest {
            $signedAt = now('UTC');
            $cleanName = trim($signerName);
            $cleanId = trim($signerIdNumber);
            $signerIp = $request?->ip() ?? 'unknown';

            $sealInput = implode('|', [
                $tenant->id,
                $portalReq->id,
                $portalReq->document_id ?? 'no-doc',
                $cleanName,
                $cleanId,
                $signedAt->toIso8601String(),
                $signerIp,
            ]);
            $signatureHash = hash('sha256', $sealInput);

            $portalReq->update([
                'signed_at' => $signedAt,
                'signed_by_name' => $cleanName,
                'signer_id_number' => $cleanId,
                'signature_hash' => $signatureHash,
                'signer_ip' => $signerIp,
                'status' => 'completed',
            ]);

            if ($portalReq->document_id !== null) {
                $doc = Document::query()->where('tenant_id', $tenant->id)->find($portalReq->document_id);
                if ($doc !== null) {
                    $meta = is_array($doc->metadata_json) ? $doc->metadata_json : [];
                    $meta['external_esignature'] = [
                        'portal_request_id' => $portalReq->id,
                        'signed_by_name' => $cleanName,
                        'signer_id_number' => $cleanId,
                        'signature_hash' => $signatureHash,
                        'signer_ip' => $signerIp,
                        'signed_at' => $signedAt->toIso8601String(),
                    ];
                    $doc->update(['metadata_json' => $meta]);
                }
            }

            $this->audit->log(
                tenantId: $tenant->id,
                eventType: 'portal.external_esign_completed',
                resourceType: 'external_portal_request',
                resourceId: $portalReq->id,
                actor: $creator,
                classificationRank: $portalReq->classificationLevel->rank ?? 1,
                after: [
                    'document_id' => $portalReq->document_id,
                    'signed_by_name' => $cleanName,
                    'signature_hash' => $signatureHash,
                    'signer_ip' => $signerIp,
                ],
                request: $request,
            );

            return $portalReq->refresh();
        });
    }

    public function revokeRequest(ExternalPortalRequest $portalReq, User $actor, ?Request $request = null): ExternalPortalRequest
    {
        if ($portalReq->tenant_id !== $actor->tenant_id) {
            throw new RuntimeException('Cross-tenant portal revocation forbidden.');
        }

        $portalReq->update(['status' => 'revoked']);

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'portal.request_revoked',
            resourceType: 'external_portal_request',
            resourceId: $portalReq->id,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            after: ['status' => 'revoked'],
            request: $request,
        );

        return $portalReq->refresh();
    }

    private function assertPortalUsable(ExternalPortalRequest $portalReq, ?string $otp): void
    {
        if ($portalReq->status !== 'active') {
            throw new RuntimeException("This portal link is {$portalReq->status} and no longer accepts submissions.");
        }

        if ($portalReq->expires_at->isPast()) {
            $portalReq->update(['status' => 'expired']);
            throw new RuntimeException('This portal link has expired.');
        }

        if ($portalReq->otp_hash !== null) {
            $provided = trim((string) $otp);
            if ($provided === '' || ! hash_equals($portalReq->otp_hash, hash('sha256', $provided))) {
                throw new RuntimeException('Invalid 6-digit security PIN for this portal link.');
            }
        }
    }

    /**
     * @return array{request_id: string, tenant_id: string}|null
     */
    private function lookupTokenRef(string $tokenHash): ?array
    {
        if (DB::getDriverName() === 'pgsql') {
            $row = DB::selectOne('SELECT request_id, tenant_id FROM resolve_external_portal_request(?)', [$tokenHash]);
            if ($row === null) {
                return null;
            }

            /** @var object{request_id: string, tenant_id: string} $row */
            return [
                'request_id' => $row->request_id,
                'tenant_id' => $row->tenant_id,
            ];
        }

        $found = ExternalPortalRequest::query()->where('token_hash', $tokenHash)->first();

        return $found !== null ? ['request_id' => $found->id, 'tenant_id' => $found->tenant_id] : null;
    }
}
