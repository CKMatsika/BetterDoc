<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EnvelopeMode;
use App\Enums\SignatureTier;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignatureEnvelope;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Security\DbSecurityContext;
use App\Services\Signing\PadesSignerService;
use App\Services\Signing\SigningEnvelopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SignatureController extends Controller
{
    public function __construct(
        private readonly SigningEnvelopeService $envelopes,
        private readonly PadesSignerService $pades,
        private readonly DbSecurityContext $dbContext,
    ) {}

    public function storeEnvelope(Request $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('view', $document);

        /** @var array{title: string, mode: string, message?: ?string, signers: list<array{signer_id: string, sign_order?: int, tier?: string, field_coords?: array{page?: int, x?: float|int, y?: float|int, width?: float|int, height?: float|int}}>} $validated */
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'mode' => ['required', Rule::enum(EnvelopeMode::class)],
            'message' => ['nullable', 'string', 'max:1000'],
            'signers' => ['required', 'array', 'min:1', 'max:12'],
            'signers.*.signer_id' => ['required', 'uuid'],
            'signers.*.sign_order' => ['nullable', 'integer', 'min:1', 'max:20'],
            'signers.*.tier' => ['nullable', Rule::enum(SignatureTier::class)],
            'signers.*.field_coords' => ['nullable', 'array'],
            'signers.*.field_coords.page' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'signers.*.field_coords.x' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'signers.*.field_coords.y' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'signers.*.field_coords.width' => ['nullable', 'numeric', 'min:60', 'max:600'],
            'signers.*.field_coords.height' => ['nullable', 'numeric', 'min:24', 'max:300'],
        ]);

        // Single self-sign allows explicit perm_sign, inviting other signers requires share authority
        $invitingOthers = false;
        foreach ($validated['signers'] as $entry) {
            if ($entry['signer_id'] !== $user->id) {
                $invitingOthers = true;
                break;
            }
        }
        Gate::authorize($invitingOthers ? 'share' : 'sign', $document);

        $this->envelopes->createEnvelope(
            document: $document,
            creator: $user,
            title: $validated['title'],
            mode: EnvelopeMode::from($validated['mode']),
            signers: $validated['signers'],
            message: $validated['message'] ?? null,
            request: $request,
        );

        return back()->with('success', 'Signing envelope initiated.');
    }

    public function sign(Request $request, SignatureRequest $signatureRequest): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $document = $signatureRequest->envelope()->with('document')->firstOrFail()->document;
        if (! $document instanceof Document) {
            abort(404);
        }
        Gate::authorize('sign', $document);

        /** @var array{reason: string, location?: ?string, tier?: ?string, typed_name?: ?string, drawn_stamp_svg?: ?string} $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'location' => ['nullable', 'string', 'max:128'],
            'tier' => ['nullable', Rule::enum(SignatureTier::class)],
            'typed_name' => ['nullable', 'string', 'max:128'],
            'drawn_stamp_svg' => ['nullable', 'string', 'max:32000'],
        ]);

        $session = $this->currentSession($request, $user);
        $overrideTier = isset($validated['tier']) ? SignatureTier::from($validated['tier']) : null;

        $updated = $this->envelopes->signRequest(
            sigRequest: $signatureRequest,
            actor: $user,
            session: $session,
            reason: $validated['reason'],
            location: $validated['location'] ?? 'BetterDoc LAN',
            overrideTier: $overrideTier,
            typedName: $validated['typed_name'] ?? null,
            drawnSvgPath: $validated['drawn_stamp_svg'] ?? null,
            request: $request,
        );

        return back()->with('success', "Signature applied ({$updated->tier->value}).");
    }

    public function prepareExternal(Request $request, SignatureRequest $signatureRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $document = $signatureRequest->envelope()->with('document')->firstOrFail()->document;
        if (! $document instanceof Document) {
            abort(404);
        }
        Gate::authorize('sign', $document);

        /** @var array{reason: string, location?: ?string} $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'location' => ['nullable', 'string', 'max:128'],
        ]);

        $session = $this->currentSession($request, $user);

        return response()->json(
            $this->envelopes->prepareSmartcardSigning(
                sigRequest: $signatureRequest,
                actor: $user,
                session: $session,
                reason: $validated['reason'],
                location: $validated['location'] ?? 'Workstation Smartcard',
            )
        );
    }

    public function finalizeExternal(Request $request, SignatureRequest $signatureRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $document = $signatureRequest->envelope()->with('document')->firstOrFail()->document;
        if (! $document instanceof Document) {
            abort(404);
        }
        Gate::authorize('sign', $document);

        /** @var array{challenge_token: string, signature_b64: string} $validated */
        $validated = $request->validate([
            'challenge_token' => ['required', 'string'],
            'signature_b64' => ['required', 'string'],
        ]);

        $completed = $this->envelopes->finalizeSmartcardSigning(
            sigRequest: $signatureRequest,
            actor: $user,
            challengeToken: $validated['challenge_token'],
            signatureB64: $validated['signature_b64'],
            request: $request,
        );

        return response()->json([
            'status' => $completed->status->value,
            'cert_serial' => $completed->cert_serial,
            'tsa_token_serial' => $completed->tsa_token_serial,
            'signed_version_id' => $completed->signed_version_id,
        ]);
    }

    public function decline(Request $request, SignatureRequest $signatureRequest): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $document = $signatureRequest->envelope()->with('document')->firstOrFail()->document;
        if (! $document instanceof Document) {
            abort(404);
        }
        Gate::authorize('view', $document);

        /** @var array{reason: string} $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->envelopes->decline($signatureRequest, $user, $validated['reason'], $request);

        return back()->with('success', 'Signature request declined.');
    }

    public function reassign(Request $request, SignatureRequest $signatureRequest): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $document = $signatureRequest->envelope()->with('document')->firstOrFail()->document;
        if (! $document instanceof Document) {
            abort(404);
        }
        Gate::authorize('view', $document);

        /** @var array{new_signer_id: string} $validated */
        $validated = $request->validate([
            'new_signer_id' => ['required', 'uuid'],
        ]);

        $this->envelopes->reassign($signatureRequest, $user, $validated['new_signer_id'], $request);

        return back()->with('success', 'Signature slot reassigned to cleared officer.');
    }

    public function remind(Request $request, SignatureRequest $signatureRequest): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $document = $signatureRequest->envelope()->with('document')->firstOrFail()->document;
        if (! $document instanceof Document) {
            abort(404);
        }
        Gate::authorize('view', $document);

        $this->envelopes->remind($signatureRequest, $user, $request);

        return back()->with('success', 'Signer reminder logged.');
    }

    public function downloadCertificate(Request $request, SignatureEnvelope $envelope): SymfonyResponse
    {
        $envelope->loadMissing('document');
        $document = $envelope->document;
        if (! $document instanceof Document) {
            abort(404);
        }
        Gate::authorize('view', $document);

        $stream = $this->pades->openCertificatePdfStream($envelope);

        return response()->streamDownload(function () use ($stream): void {
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
            }
            fclose($stream);
        }, 'certificate-'.str_replace(['/', '\\'], '-', $document->doc_number).'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function verifyVersion(Request $request, Document $document, DocumentVersion $version): RedirectResponse
    {
        abort_unless($version->document_id === $document->id, 404);
        Gate::authorize('view', $document);

        $verification = $this->pades->verifyDocumentVersion($version);

        return back()->with('success', 'Cryptographic verification complete: '.strtoupper($verification->overall_status->value));
    }

    /**
     * Offline / LAN QR Verification Portal.
     * Enforces Bell-LaPadula & compartment confidentiality: hides document title/number unless Rank 0 or caller passes RLS.
     */
    public function verifyToken(Request $request, string $token): InertiaResponse
    {
        $cleanToken = trim($token);
        if ($cleanToken === '' || strlen($cleanToken) !== 32) {
            abort(404);
        }

        $row = DB::selectOne('SELECT betterdoc_verify_token_lookup(?) AS payload', [$cleanToken]);
        $rawPayload = is_object($row) ? ($row->payload ?? null) : null;
        if (! is_string($rawPayload) || $rawPayload === '') {
            abort(404);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            abort(404);
        }

        /** @var array<string, mixed> $env */
        $env = is_array($decoded['envelope'] ?? null) ? $decoded['envelope'] : [];
        /** @var array<string, mixed> $doc */
        $doc = is_array($decoded['document'] ?? null) ? $decoded['document'] : [];
        /** @var array<string, mixed> $ver */
        $ver = is_array($decoded['verification'] ?? null) ? $decoded['verification'] : [];
        /** @var list<array<string, mixed>> $signers */
        $signers = is_array($decoded['signers'] ?? null) ? array_values($decoded['signers']) : [];

        $rank = is_numeric($doc['classification_rank'] ?? null) ? (int) $doc['classification_rank'] : 1;
        $docId = is_string($doc['id'] ?? null) ? $doc['id'] : '';

        /** @var User|null $viewer */
        $viewer = $request->user();
        $isMetadataVisible = $rank === 0;
        if (! $isMetadataVisible && $viewer instanceof User && $docId !== '') {
            $isMetadataVisible = $this->dbContext->runAs(
                $viewer,
                static fn (): bool => Document::query()->where('id', $docId)->exists()
            );
        }

        return Inertia::render('Verify/Show', [
            'token' => $cleanToken,
            'envelope' => [
                'status' => is_string($env['status'] ?? null) ? $env['status'] : 'unknown',
                'mode' => is_string($env['mode'] ?? null) ? $env['mode'] : 'ordered',
                'completed_at' => is_string($env['completed_at'] ?? null) ? $env['completed_at'] : null,
                'title' => $isMetadataVisible && is_string($env['title'] ?? null)
                    ? $env['title']
                    : '[CLASSIFIED SEALED RECORD — AUTHENTICATE TO INSPECT]',
            ],
            'document' => [
                'classification_rank' => $rank,
                'doc_number' => $isMetadataVisible && is_string($doc['doc_number'] ?? null) ? $doc['doc_number'] : '[REDACTED]',
                'title' => $isMetadataVisible && is_string($doc['title'] ?? null) ? $doc['title'] : '[CLASSIFIED — CLEARANCE REQUIRED]',
                'metadata_hidden' => ! $isMetadataVisible,
            ],
            'verification' => [
                'overall_status' => is_string($ver['overall_status'] ?? null) ? $ver['overall_status'] : 'unsigned',
                'signatures_count' => is_numeric($ver['signatures_count'] ?? null) ? (int) $ver['signatures_count'] : 0,
                'verified_at' => is_string($ver['verified_at'] ?? null) ? $ver['verified_at'] : null,
                'details' => is_array($ver['details'] ?? null) ? $ver['details'] : [],
            ],
            'signers' => $signers,
        ]);
    }

    private function currentSession(Request $request, User $user): ?UserSession
    {
        $sessionId = $request->hasSession() ? $request->session()->getId() : '';
        if ($sessionId === '') {
            return null;
        }

        return UserSession::query()
            ->where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->whereNull('revoked_at')
            ->first();
    }
}
