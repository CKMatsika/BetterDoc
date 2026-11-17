<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Collaboration\WopiService;
use App\Services\Crypto\EnvelopeEncryptionService;
use App\Services\Security\DbSecurityContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WopiController extends Controller
{
    public function __construct(
        private readonly WopiService $wopi,
        private readonly DbSecurityContext $dbContext,
        private readonly EnvelopeEncryptionService $crypto,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function checkFileInfo(Request $request, string $documentId): JsonResponse
    {
        $rawToken = (string) $request->query('access_token', '');

        try {
            $ctx = $this->wopi->authenticateWopiCall($rawToken, $documentId, $request);

            return response()->json($this->wopi->checkFileInfo($ctx['token'], $ctx['user'], $ctx['document']));
        } catch (AuthenticationException $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        } finally {
            $this->dbContext->clear();
        }
    }

    public function postFile(Request $request, string $documentId): Response
    {
        $rawToken = (string) $request->query('access_token', '');
        $override = strtoupper((string) $request->header('X-WOPI-Override', ''));
        $lockId = $request->header('X-WOPI-Lock');
        $oldLockId = $request->header('X-WOPI-OldLock');

        try {
            $ctx = $this->wopi->authenticateWopiCall($rawToken, $documentId, $request);
            $res = $this->wopi->handleLockAction(
                $ctx['token'],
                $ctx['user'],
                $ctx['document'],
                $override,
                is_string($lockId) ? $lockId : null,
                is_string($oldLockId) ? $oldLockId : null,
            );

            return response('', $res['status'], $res['headers']);
        } catch (AuthenticationException $e) {
            return response($e->getMessage(), 401);
        } finally {
            $this->dbContext->clear();
        }
    }

    public function getFile(Request $request, string $documentId): SymfonyResponse
    {
        $rawToken = (string) $request->query('access_token', '');

        try {
            $ctx = $this->wopi->authenticateWopiCall($rawToken, $documentId, $request);
            $version = $ctx['document']->currentVersion;
            if ($version === null) {
                return response('No document content available.', 404);
            }

            $handle = $this->crypto->decryptVersionToTempHandle($version);
            $stat = fstat($handle);
            $size = is_array($stat) ? (int) $stat['size'] : 0;

            $this->audit->log(
                tenantId: $ctx['document']->tenant_id,
                eventType: 'document.wopi_read',
                resourceType: 'document',
                resourceId: $ctx['document']->id,
                actor: $ctx['user'],
                classificationRank: $ctx['document']->classification_rank,
                after: ['version_id' => $version->id],
                request: $request,
            );

            return response()->stream(function () use ($handle): void {
                while (! feof($handle)) {
                    $chunk = fread($handle, 65536);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                }
                fclose($handle);
            }, 200, [
                'Content-Type' => $version->mime_type,
                'Content-Length' => (string) $size,
            ]);
        } catch (AuthenticationException $e) {
            return response($e->getMessage(), 401);
        } finally {
            $this->dbContext->clear();
        }
    }

    public function putFile(Request $request, string $documentId): JsonResponse
    {
        $rawToken = (string) $request->query('access_token', '');
        $wopiLock = $request->header('X-WOPI-Lock');

        try {
            $ctx = $this->wopi->authenticateWopiCall($rawToken, $documentId, $request);
            $rawBytes = $request->getContent();

            $res = $this->wopi->putFile(
                token: $ctx['token'],
                user: $ctx['user'],
                document: $ctx['document'],
                rawBytes: $rawBytes,
                wopiLockId: is_string($wopiLock) ? $wopiLock : null,
                request: $request,
            );

            if ($res['status'] === 409) {
                return response()->json(['error' => 'WOPI Lock mismatch'], 409, [
                    'X-WOPI-Lock' => $res['conflict_lock'],
                ]);
            }

            if ($res['status'] === 403 || $res['version'] === null) {
                return response()->json(['error' => 'WOPI write forbidden'], 403);
            }

            return response()->json([
                'LastModifiedTime' => $res['version']->created_at?->toIso8601String(),
                'Version' => $res['version']->id,
            ]);
        } catch (AuthenticationException $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        } finally {
            $this->dbContext->clear();
        }
    }
}
