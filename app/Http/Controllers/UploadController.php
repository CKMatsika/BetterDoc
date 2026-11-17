<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Exceptions\MalwareDetectedException;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Compartment;
use App\Models\Document;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Upload\ResumableUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UploadController extends Controller
{
    public function __construct(
        private readonly ResumableUploadService $uploads,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function initiate(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{filename: string, mime_type: string, total_size: int, sha256_expected?: ?string, document_id?: ?string, chunk_size?: ?int} $validated */
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:128'],
            'total_size' => ['required', 'integer', 'min:1'],
            'sha256_expected' => ['nullable', 'string', 'size:64'],
            'document_id' => ['nullable', 'uuid'],
            'chunk_size' => ['nullable', 'integer', 'min:65536', 'max:20971520'],
        ]);

        if (! empty($validated['document_id'])) {
            $doc = Document::query()->findOrFail($validated['document_id']);
            Gate::authorize('update', $doc);
        }

        $session = $this->uploads->initiate(
            user: $user,
            filename: $validated['filename'],
            mimeType: $validated['mime_type'],
            totalSize: (int) $validated['total_size'],
            sha256Expected: $validated['sha256_expected'] ?? null,
            documentId: $validated['document_id'] ?? null,
            chunkSize: isset($validated['chunk_size']) ? (int) $validated['chunk_size'] : null,
        );

        return response()->json([
            'session_id' => $session->id,
            'chunk_size' => $session->chunk_size,
            'total_chunks' => $session->total_chunks,
            'uploaded_parts' => array_keys($session->uploaded_parts_json),
            'missing_parts' => $session->missingParts(),
        ], 201);
    }

    public function uploadChunk(Request $request, UploadSession $uploadSession, int $partIndex): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($uploadSession->user_id === $user->id, 404);

        $chunkFile = $request->file('chunk');
        $bytes = $chunkFile instanceof UploadedFile
            ? (string) file_get_contents($chunkFile->getRealPath())
            : $request->getContent();

        if ($bytes === '') {
            return response()->json(['message' => 'Empty chunk payload.'], 422);
        }

        $expectedSha = $request->header('X-Chunk-Sha256');
        $updated = $this->uploads->storeChunk(
            session: $uploadSession,
            partIndex: $partIndex,
            bytes: $bytes,
            chunkSha256: is_string($expectedSha) && $expectedSha !== '' ? $expectedSha : null,
        );

        return response()->json([
            'session_id' => $updated->id,
            'status' => $updated->status->value,
            'uploaded_parts' => array_keys($updated->uploaded_parts_json),
            'missing_parts' => $updated->missingParts(),
            'bytes_received' => $updated->bytesReceived(),
        ]);
    }

    public function status(Request $request, UploadSession $uploadSession): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($uploadSession->user_id === $user->id, 404);

        return response()->json([
            'session_id' => $uploadSession->id,
            'status' => $uploadSession->status->value,
            'total_chunks' => $uploadSession->total_chunks,
            'chunk_size' => $uploadSession->chunk_size,
            'uploaded_parts' => array_keys($uploadSession->uploaded_parts_json),
            'missing_parts' => $uploadSession->missingParts(),
            'bytes_received' => $uploadSession->bytesReceived(),
            'total_size' => $uploadSession->total_size,
        ]);
    }

    public function complete(Request $request, UploadSession $uploadSession): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($uploadSession->user_id === $user->id, 404);

        if ($uploadSession->document_id !== null) {
            /** @var Document $document */
            $document = Document::query()->findOrFail($uploadSession->document_id);
            Gate::authorize('update', $document);
        } else {
            $request->validate([
                'cabinet_id' => ['required', 'uuid'],
                'classification_level_id' => ['required', 'uuid'],
                'doc_number' => ['required', 'string', 'max:96'],
                'title' => ['required', 'string', 'max:255'],
                'summary' => ['nullable', 'string', 'max:4000'],
                'compartment_ids' => ['nullable', 'array'],
                'compartment_ids.*' => ['uuid'],
            ]);

            /** @var ClearanceLevel $level */
            $level = ClearanceLevel::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('id', $request->string('classification_level_id')->toString())
                ->firstOrFail();

            if ($level->rank > $user->clearance_rank) {
                return response()->json(['message' => 'Cannot classify document above your clearance rank.'], 422);
            }

            /** @var list<string> $compartmentIds */
            $compartmentIds = [];
            $rawComp = $request->input('compartment_ids', []);
            if (is_array($rawComp)) {
                foreach ($rawComp as $cid) {
                    if (is_string($cid) && $cid !== '') {
                        $compartmentIds[] = $cid;
                    }
                }
            }

            if (array_diff($compartmentIds, $user->activeCompartmentIds()) !== []) {
                return response()->json(['message' => 'Cannot assign compartment you do not hold.'], 422);
            }

            /** @var Cabinet $cabinet */
            $cabinet = Cabinet::query()->where('id', $request->string('cabinet_id')->toString())->firstOrFail();
            $summaryVal = $request->input('summary');

            $document = DB::transaction(function () use ($user, $cabinet, $level, $request, $summaryVal, $compartmentIds): Document {
                /** @var Document $created */
                $created = Document::query()->create([
                    'tenant_id' => $user->tenant_id,
                    'cabinet_id' => $cabinet->id,
                    'org_unit_id' => $user->org_unit_id,
                    'owner_id' => $user->id,
                    'classification_level_id' => $level->id,
                    'classification_rank' => $level->rank,
                    'doc_number' => $request->string('doc_number')->toString(),
                    'title' => $request->string('title')->toString(),
                    'summary' => is_string($summaryVal) ? $summaryVal : null,
                    'status' => DocumentStatus::Active,
                    'unit_wide_readable' => true,
                ]);

                if ($compartmentIds !== []) {
                    $validIds = Compartment::query()
                        ->where('tenant_id', $user->tenant_id)
                        ->whereIn('id', $compartmentIds)
                        ->pluck('id')
                        ->all();
                    $created->compartments()->sync($validIds);
                }

                $this->audit->log(
                    tenantId: $user->tenant_id,
                    eventType: 'document.created',
                    resourceType: 'document',
                    resourceId: $created->id,
                    actor: $user,
                    classificationRank: $created->classification_rank,
                    after: ['doc_number' => $created->doc_number, 'title' => $created->title],
                    request: $request,
                );

                return $created;
            });
        }

        $major = $request->boolean('major');
        $noteVal = $request->input('version_note');

        try {
            $version = $this->uploads->finalizeIntoDocumentVersion(
                session: $uploadSession,
                document: $document,
                actor: $user,
                major: $major,
                versionNote: is_string($noteVal) && $noteVal !== '' ? $noteVal : null,
                request: $request,
            );
        } catch (MalwareDetectedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'document_id' => $document->id,
            'version_id' => $version->id,
            'version_label' => $version->label(),
            'sha256' => $version->sha256_hash,
            'av_status' => $version->av_status->value,
            'cdr_status' => $version->cdr_status->value,
            'ocr_status' => $version->ocr_status->value,
            'page_count' => $version->page_count,
        ], 201);
    }
}
