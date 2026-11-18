<?php

declare(strict_types=1);

namespace App\Services\Collaboration;

use App\Enums\AvStatus;
use App\Enums\CdrStatus;
use App\Enums\OcrStatus;
use App\Models\Document;
use App\Models\DocumentLock;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Models\WopiToken;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Crypto\EnvelopeEncryptionService;
use App\Services\Search\DocumentSearchService;
use App\Services\Security\ClearancePolicyEngine;
use App\Services\Security\DbSecurityContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class WopiService
{
    public function __construct(
        private readonly ClearancePolicyEngine $policyEngine,
        private readonly DbSecurityContext $dbContext,
        private readonly EnvelopeEncryptionService $crypto,
        private readonly DocumentSearchService $search,
        private readonly ImmutableAuditLogger $audit,
        private readonly RealtimeNotificationService $notifications,
    ) {}

    /**
     * Issue a short-lived user+document scoped WOPI session token for Collabora Online.
     *
     * @return array{access_token: string, token_ttl: int, wopi_src: string, collabora_iframe_url: string|null, can_write: bool, expires_at: string}
     */
    public function issueSession(Document $document, User $user, ?Request $request = null): array
    {
        $viewDecision = $this->policyEngine->authorize($user, 'view', $document, [
            'ip' => $request?->ip(),
            'bypass_abac' => $request === null,
        ]);

        if (! $viewDecision->allowed) {
            throw new RuntimeException('Access denied to open co-editing session.');
        }

        $editDecision = $this->policyEngine->authorize($user, 'update', $document, [
            'ip' => $request?->ip(),
            'bypass_abac' => $request === null,
        ]);

        $canWrite = $editDecision->allowed && ! $document->is_worm;
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $ttlMinutes = max(10, config()->integer('betterdoc.collabora.token_ttl_minutes', 120));
        $expiresAt = now()->addMinutes($ttlMinutes);

        WopiToken::query()->create([
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'token_hash' => $tokenHash,
            'ip_address' => $request?->ip(),
            'user_agent_hash' => $request !== null ? hash('sha256', (string) ($request->userAgent() ?? 'unknown')) : null,
            'can_write' => $canWrite,
            'expires_at' => $expiresAt,
        ]);

        $this->audit->log(
            tenantId: $document->tenant_id,
            eventType: 'document.wopi_session_issued',
            resourceType: 'document',
            resourceId: $document->id,
            actor: $user,
            classificationRank: $document->classification_rank,
            after: ['can_write' => $canWrite, 'expires_at' => $expiresAt->toIso8601String()],
            request: $request,
        );

        $wopiBase = rtrim(config()->string('betterdoc.collabora.wopi_base_url', 'http://127.0.0.1:8000'), '/');
        $wopiSrc = "{$wopiBase}/wopi/files/{$document->id}";
        $collaboraEnabled = config()->boolean('betterdoc.collabora.enabled', false);
        $collaboraBase = rtrim(config()->string('betterdoc.collabora.url', 'http://collabora:9980'), '/');

        return [
            'access_token' => $rawToken,
            'token_ttl' => $ttlMinutes * 60 * 1000,
            'wopi_src' => $wopiSrc,
            'collabora_iframe_url' => $collaboraEnabled
                ? "{$collaboraBase}/browser/dist/cool.html?WOPISrc=".rawurlencode($wopiSrc).'&access_token='.rawurlencode($rawToken)
                : null,
            'can_write' => $canWrite,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Authenticate an incoming Collabora WOPI HTTP call, apply PostgreSQL RLS context for the token holder,
     * and verify real-time MAC/Compartment clearance has not been downgraded since issuance.
     *
     * @return array{token: WopiToken, user: User, document: Document}
     *
     * @throws AuthenticationException
     */
    public function authenticateWopiCall(string $rawToken, string $documentId, ?Request $request = null): array
    {
        if ($rawToken === '') {
            throw new AuthenticationException('Missing WOPI access token.');
        }

        $tokenHash = hash('sha256', $rawToken);
        $driver = DB::connection()->getDriverName();

        $tokenData = null;
        if ($driver === 'pgsql') {
            $res = DB::selectOne('SELECT betterdoc_wopi_token_lookup(?) AS data', [$tokenHash]);
            if (is_object($res) && property_exists($res, 'data') && is_string($res->data)) {
                /** @var mixed $decoded */
                $decoded = json_decode($res->data, true);
                $tokenData = is_array($decoded) ? $decoded : null;
            }
        } else {
            $direct = WopiToken::query()->where('token_hash', $tokenHash)->first();
            $tokenData = $direct?->toArray();
        }

        if (! is_array($tokenData) || ($tokenData['document_id'] ?? null) !== $documentId) {
            throw new AuthenticationException('Invalid WOPI access token.');
        }

        if (($tokenData['revoked_at'] ?? null) !== null) {
            throw new AuthenticationException('WOPI session has been revoked.');
        }

        $expiresRaw = $tokenData['expires_at'] ?? null;
        if (! is_string($expiresRaw) || Carbon::parse($expiresRaw)->isPast()) {
            throw new AuthenticationException('WOPI session has expired.');
        }

        if (config()->boolean('betterdoc.collabora.bind_client_ip', false) && $request !== null) {
            $boundIp = is_string($tokenData['ip_address'] ?? null) ? $tokenData['ip_address'] : null;
            if ($boundIp !== null && $boundIp !== $request->ip()) {
                throw new AuthenticationException('WOPI IP binding mismatch.');
            }
        }

        $userIdStr = is_string($tokenData['user_id'] ?? null) ? $tokenData['user_id'] : '';
        /** @var User|null $user */
        $user = User::query()->where('id', $userIdStr)->first();
        if ($user === null) {
            throw new AuthenticationException('WOPI user not found.');
        }

        $user->loadMissing(['orgUnit', 'clearanceLevel', 'tenant']);
        $this->dbContext->applyForUser($user);

        $tokenIdStr = is_string($tokenData['id'] ?? null) ? $tokenData['id'] : '';
        /** @var WopiToken|null $token */
        $token = WopiToken::query()->where('id', $tokenIdStr)->first();
        if ($token === null || ! $token->isValid()) {
            throw new AuthenticationException('WOPI token state invalid.');
        }

        /** @var Document|null $document */
        $document = Document::query()->with(['currentVersion', 'classificationLevel'])->where('id', $documentId)->first();
        if ($document === null) {
            // Clearance/RLS blocked visibility -> revoke token immediately.
            $token->update(['revoked_at' => now()]);
            throw new AuthenticationException('Document is no longer within active clearance.');
        }

        $decision = $this->policyEngine->authorize($user, 'view', $document, [
            'bypass_abac' => true,
        ]);

        if (! $decision->allowed) {
            $token->update(['revoked_at' => now()]);
            throw new AuthenticationException('Clearance authorization revoked for active WOPI session.');
        }

        $token->update(['last_seen_at' => now()]);

        return [
            'token' => $token,
            'user' => $user,
            'document' => $document,
        ];
    }

    /**
     * Build standard WOPI CheckFileInfo JSON payload with BetterDoc MAC/Watermark controls.
     *
     * @return array<string, mixed>
     */
    public function checkFileInfo(WopiToken $token, User $user, Document $document): array
    {
        $version = $document->currentVersion;
        $canWrite = $token->can_write
            && ! $document->is_worm
            && $this->policyEngine->authorize($user, 'update', $document, ['bypass_abac' => true])->allowed;

        $canPrint = $this->policyEngine->authorize($user, 'print', $document, ['bypass_abac' => true])->allowed;
        $canDownload = $this->policyEngine->authorize($user, 'download', $document, ['bypass_abac' => true])->allowed
            && $document->classificationLevel?->watermark_mode !== 'dynamic_no_download';

        $label = strtoupper($document->classificationLevel->name ?? 'CLASSIFIED');

        return [
            'BaseFileName' => $version->original_filename ?? ($document->doc_number.'.docx'),
            'Size' => $version->file_size_bytes ?? 0,
            'OwnerId' => $document->owner_id,
            'UserId' => $user->id,
            'UserFriendlyName' => ($user->rank_title ? $user->rank_title.' ' : '').$user->name,
            'Version' => $version->id ?? $document->id,
            'UserCanWrite' => $canWrite,
            'ReadOnly' => ! $canWrite,
            'SupportsLocks' => true,
            'SupportsGetLock' => true,
            'SupportsUpdate' => true,
            'UserCanNotWriteRelative' => true,
            'DisablePrint' => ! $canPrint,
            'DisableExport' => ! $canDownload,
            'DisableCopy' => $document->classification_rank >= 3,
            'WatermarkText' => "{$label} · {$user->name} · ".now('UTC')->format('Y-m-d H:i').' UTC',
        ];
    }

    /**
     * Handle WOPI LOCK / GET_LOCK / REFRESH_LOCK / UNLOCK / UNLOCK_AND_RELOCK transitions.
     *
     * @return array{status: int, headers: array<string, string>}
     */
    public function handleLockAction(
        WopiToken $token,
        User $user,
        Document $document,
        string $override,
        ?string $lockId,
        ?string $oldLockId = null,
    ): array {
        return DB::transaction(function () use ($token, $user, $document, $override, $lockId, $oldLockId): array {
            /** @var DocumentLock|null $lock */
            $lock = DocumentLock::query()->where('document_id', $document->id)->lockForUpdate()->first();
            $currentLockId = ($lock !== null && $lock->isActive()) ? ($lock->wopi_lock_id ?? '') : '';

            if ($override === 'GET_LOCK') {
                return ['status' => 200, 'headers' => ['X-WOPI-Lock' => $currentLockId]];
            }

            if ($lockId === null || $lockId === '') {
                return ['status' => 400, 'headers' => []];
            }

            if ($override === 'LOCK') {
                if ($oldLockId !== null && $oldLockId !== '') {
                    // UNLOCK_AND_RELOCK semantics
                    if ($currentLockId !== $oldLockId) {
                        return ['status' => 409, 'headers' => ['X-WOPI-Lock' => $currentLockId]];
                    }
                    $lock->update(['wopi_lock_id' => $lockId, 'wopi_session_id' => $token->id, 'expires_at' => now()->addHours(2)]);
                    $token->update(['wopi_lock_id' => $lockId]);

                    return ['status' => 200, 'headers' => ['X-WOPI-Lock' => $lockId]];
                }

                if ($currentLockId === '' || $currentLockId === $lockId) {
                    DocumentLock::query()->updateOrCreate(
                        ['document_id' => $document->id],
                        [
                            'tenant_id' => $document->tenant_id,
                            'locked_by' => $user->id,
                            'lock_type' => 'wopi',
                            'wopi_lock_id' => $lockId,
                            'wopi_session_id' => $token->id,
                            'checkout_note' => 'Collabora Online co-editing session',
                            'locked_at' => now(),
                            'expires_at' => now()->addHours(2),
                        ]
                    );
                    $token->update(['wopi_lock_id' => $lockId]);

                    return ['status' => 200, 'headers' => ['X-WOPI-Lock' => $lockId]];
                }

                return ['status' => 409, 'headers' => ['X-WOPI-Lock' => $currentLockId]];
            }

            if ($override === 'REFRESH_LOCK') {
                if ($currentLockId === '' || $currentLockId !== $lockId) {
                    return ['status' => 409, 'headers' => ['X-WOPI-Lock' => $currentLockId]];
                }
                $lock->update(['expires_at' => now()->addHours(2)]);

                return ['status' => 200, 'headers' => ['X-WOPI-Lock' => $lockId]];
            }

            if ($override === 'UNLOCK') {
                if ($currentLockId === '' || $currentLockId !== $lockId) {
                    return ['status' => 409, 'headers' => ['X-WOPI-Lock' => $currentLockId]];
                }
                $lock->delete();
                $token->update(['wopi_lock_id' => null]);

                return ['status' => 200, 'headers' => ['X-WOPI-Lock' => '']];
            }

            return ['status' => 400, 'headers' => []];
        });
    }

    /**
     * Commit PutFile content from Collabora Online as a new encrypted minor DocumentVersion.
     *
     * @return array{status: int, version: DocumentVersion|null, conflict_lock: string}
     */
    public function putFile(
        WopiToken $token,
        User $user,
        Document $document,
        string $rawBytes,
        ?string $wopiLockId = null,
        ?Request $request = null,
    ): array {
        if ($document->is_worm) {
            return ['status' => 403, 'version' => null, 'conflict_lock' => ''];
        }

        if (! $token->can_write || ! $this->policyEngine->authorize($user, 'update', $document, ['bypass_abac' => true])->allowed) {
            return ['status' => 403, 'version' => null, 'conflict_lock' => ''];
        }

        /** @var DocumentLock|null $lock */
        $lock = DocumentLock::query()->where('document_id', $document->id)->first();
        if ($lock !== null && $lock->isActive()) {
            $activeLockId = $lock->wopi_lock_id ?? '';
            if ($activeLockId !== '' && $activeLockId !== ($wopiLockId ?? '')) {
                return ['status' => 409, 'version' => null, 'conflict_lock' => $activeLockId];
            }
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'sent_wopi_');
        if ($tmpPath === false) {
            throw new RuntimeException('Unable to allocate temporary buffer for WOPI PutFile.');
        }

        try {
            file_put_contents($tmpPath, $rawBytes);

            /** @var DocumentVersion|null $current */
            $current = $document->currentVersion;
            $major = $current->version_major ?? 1;
            $maxMinor = DocumentVersion::query()
                ->where('document_id', $document->id)
                ->where('version_major', $major)
                ->max('version_minor');
            $nextMinor = (is_numeric($maxMinor) ? (int) $maxMinor : 0) + 1;

            $newId = (string) Str::uuid7();
            $storageKey = "tenants/{$document->tenant_id}/docs/{$document->id}/v{$major}_{$nextMinor}_{$newId}.enc";
            $enc = $this->crypto->encryptFileToDisk($tmpPath, 'betterdoc_vault', $storageKey);
            $sha256 = hash('sha256', $rawBytes);

            $version = DB::transaction(function () use ($newId, $document, $current, $major, $nextMinor, $storageKey, $rawBytes, $sha256, $enc, $user, $request): DocumentVersion {
                DocumentVersion::query()
                    ->where('document_id', $document->id)
                    ->where('is_current', true)
                    ->update(['is_current' => false]);

                $created = new DocumentVersion([
                    'tenant_id' => $document->tenant_id,
                    'document_id' => $document->id,
                    'version_major' => $major,
                    'version_minor' => $nextMinor,
                    'is_current' => true,
                    'storage_disk' => 'betterdoc_vault',
                    'storage_key' => $storageKey,
                    'original_filename' => $current->original_filename ?? ($document->doc_number.'.docx'),
                    'mime_type' => $current->mime_type ?? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'file_size_bytes' => strlen($rawBytes),
                    'sha256_hash' => $sha256,
                    'wrapped_dek' => $enc['wrapped_dek'],
                    'kek_key_id' => $enc['kek_key_id'],
                    'av_status' => AvStatus::Clean,
                    'cdr_status' => CdrStatus::Sanitized,
                    'ocr_status' => OcrStatus::Completed,
                    'pdfa_compliant' => false,
                    'page_count' => $current->page_count ?? 1,
                    'version_note' => 'Saved via offline Collabora Online WOPI session',
                    'created_by' => $user->id,
                ]);
                $created->id = $newId;
                $created->save();

                $document->update(['current_version_id' => $created->id]);

                $this->audit->log(
                    tenantId: $document->tenant_id,
                    eventType: 'document.wopi_saved',
                    resourceType: 'document',
                    resourceId: $document->id,
                    actor: $user,
                    classificationRank: $document->classification_rank,
                    after: ['version_id' => $created->id, 'sha256_hash' => $sha256],
                    request: $request,
                );

                return $created;
            });

            $this->search->indexDocument($document->refresh());
            $this->notifications->broadcastDocumentActivity($document->id, 'document.wopi_saved', [
                'version_id' => $version->id,
            ]);

            return ['status' => 200, 'version' => $version, 'conflict_lock' => ''];
        } finally {
            @unlink($tmpPath);
        }
    }
}
