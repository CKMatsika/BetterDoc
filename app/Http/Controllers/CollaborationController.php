<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AnnotationKind;
use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentComment;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Collaboration\CommentService;
use App\Services\Collaboration\WopiService;
use App\Services\Dms\PdfRedactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class CollaborationController extends Controller
{
    public function __construct(
        private readonly CommentService $comments,
        private readonly PdfRedactionService $redactions,
        private readonly WopiService $wopi,
    ) {}

    public function storeComment(Request $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('view', $document);

        /** @var array{body: string, parent_id?: string|null, mentioned_user_ids?: list<string>} $validated */
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:4000'],
            'parent_id' => ['nullable', 'uuid'],
            'mentioned_user_ids' => ['nullable', 'array'],
            'mentioned_user_ids.*' => ['uuid'],
        ]);

        if (! empty($validated['parent_id'])) {
            DocumentComment::query()
                ->where('id', $validated['parent_id'])
                ->where('document_id', $document->id)
                ->firstOrFail();
        }

        $this->comments->createComment(
            document: $document,
            author: $user,
            body: $validated['body'],
            parentId: $validated['parent_id'] ?? null,
            explicitMentionIds: $validated['mentioned_user_ids'] ?? [],
            request: $request,
        );

        return back()->with('success', 'Comment posted.');
    }

    public function resolveComment(Request $request, Document $document, DocumentComment $comment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($comment->document_id === $document->id, 404);
        Gate::authorize('view', $document);

        $comment->update([
            'resolved_at' => $comment->resolved_at === null ? now() : null,
            'resolved_by' => $comment->resolved_at === null ? $user->id : null,
        ]);

        return back()->with('success', $comment->resolved_at !== null ? 'Comment thread resolved.' : 'Comment thread reopened.');
    }

    public function storeAnnotation(Request $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('update', $document);

        /** @var array{version_id?: string|null, page_number: int, kind: string, x: float, y: float, width: float, height: float, content?: string|null} $validated */
        $validated = $request->validate([
            'version_id' => ['nullable', 'uuid'],
            'page_number' => ['required', 'integer', 'min:1'],
            'kind' => ['required', Rule::in(array_column(AnnotationKind::cases(), 'value'))],
            'x' => ['required', 'numeric', 'min:0', 'max:1'],
            'y' => ['required', 'numeric', 'min:0', 'max:1'],
            'width' => ['required', 'numeric', 'min:0.005', 'max:1'],
            'height' => ['required', 'numeric', 'min:0.005', 'max:1'],
            'content' => ['nullable', 'string', 'max:1000'],
        ]);

        $versionId = $validated['version_id'] ?? $document->current_version_id;
        if (! is_string($versionId) || $versionId === '') {
            throw ValidationException::withMessages(['annotation' => 'Document has no uploaded version to annotate.']);
        }

        /** @var DocumentVersion $version */
        $version = DocumentVersion::query()
            ->where('document_id', $document->id)
            ->where('id', $versionId)
            ->firstOrFail();

        try {
            $this->redactions->createAnnotation(
                document: $document,
                version: $version,
                actor: $user,
                pageNumber: (int) $validated['page_number'],
                kind: AnnotationKind::from($validated['kind']),
                coords: [
                    'x' => (float) $validated['x'],
                    'y' => (float) $validated['y'],
                    'width' => (float) $validated['width'],
                    'height' => (float) $validated['height'],
                ],
                content: $validated['content'] ?? null,
                request: $request,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['annotation' => $e->getMessage()]);
        }

        return back()->with('success', 'Annotation / redaction mark saved.');
    }

    public function destroyAnnotation(Request $request, Document $document, DocumentAnnotation $annotation): RedirectResponse
    {
        abort_unless($annotation->document_id === $document->id, 404);
        Gate::authorize('update', $document);

        if ($annotation->redacted_in_version_id !== null) {
            throw ValidationException::withMessages(['annotation' => 'Committed redaction records cannot be removed.']);
        }

        $annotation->delete();

        return back()->with('success', 'Annotation mark deleted.');
    }

    public function applyRedactions(Request $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('update', $document);

        $currentVersion = $document->currentVersion;
        if (! $currentVersion instanceof DocumentVersion) {
            throw ValidationException::withMessages(['redaction' => 'No active PDF version available to redact.']);
        }

        /** @var array{annotation_ids?: list<string>|null} $validated */
        $validated = $request->validate([
            'annotation_ids' => ['nullable', 'array'],
            'annotation_ids.*' => ['uuid'],
        ]);

        try {
            $newVersion = $this->redactions->applyRedactions(
                document: $document,
                sourceVersion: $currentVersion,
                actor: $user,
                annotationIds: $validated['annotation_ids'] ?? null,
                request: $request,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['redaction' => $e->getMessage()]);
        }

        return back()->with('success', "Destructive PDF redaction committed as {$newVersion->label()}. Underlying redacted text permanently purged.");
    }

    public function editOffice(Request $request, Document $document): Response
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('view', $document);

        $session = $this->wopi->issueSession($document, $user, $request);
        $currentVersion = $document->currentVersion;

        return Inertia::render('Documents/CollaboraEditor', [
            'document' => [
                'id' => $document->id,
                'doc_number' => $document->doc_number,
                'title' => $document->title,
                'classification_rank' => $document->classification_rank,
                'is_worm' => $document->is_worm,
                'filename' => $currentVersion instanceof DocumentVersion ? $currentVersion->original_filename : ($document->doc_number.'.docx'),
                'mime_type' => $currentVersion instanceof DocumentVersion ? $currentVersion->mime_type : 'application/pdf',
            ],
            'wopiSession' => $session,
            'collaboraEnabled' => config()->boolean('sentinel.collabora.enabled', false),
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rows = UserNotification::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->latest()
            ->limit(25)
            ->get()
            ->map(static fn (UserNotification $n): array => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'document_id' => $n->document_id,
                'action_url' => $n->action_url,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json(['notifications' => $rows]);
    }

    public function markNotificationRead(Request $request, UserNotification $notification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($notification->user_id === $user->id && $notification->tenant_id === $user->tenant_id, 404);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return back();
    }

    public function markAllNotificationsRead(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        UserNotification::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }
}
