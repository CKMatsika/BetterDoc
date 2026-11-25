<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\ExternalPortalRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WhatsAppChannel;
use App\Models\WhatsAppMessage;
use App\Services\Branding\BrandingService;
use App\Services\Integration\WhatsAppIntakeService;
use App\Services\Portal\ExternalPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ExternalPortalController extends Controller
{
    public function __construct(
        private readonly ExternalPortalService $portal,
        private readonly WhatsAppIntakeService $whatsapp,
        private readonly BrandingService $branding,
    ) {}

    /**
     * Authenticated internal management hub for External Magic-Link Requests & WhatsApp Business Intake.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $requests = ExternalPortalRequest::query()
            ->with(['cabinet', 'document'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(40)
            ->get()
            ->map(static fn (ExternalPortalRequest $r): array => [
                'id' => $r->id,
                'request_type' => $r->request_type,
                'title' => $r->title,
                'recipient_name' => $r->recipient_name,
                'recipient_email' => $r->recipient_email,
                'recipient_phone' => $r->recipient_phone,
                'policy_number' => $r->policy_number,
                'claim_number' => $r->claim_number,
                'cabinet_name' => $r->cabinet->name ?? '',
                'document_title' => $r->document?->title,
                'status' => $r->status,
                'otp_protected' => $r->otp_hash !== null,
                'uploads_count' => $r->uploads_count,
                'max_uploads' => $r->max_uploads,
                'signed_at' => $r->signed_at?->toIso8601String(),
                'signed_by_name' => $r->signed_by_name,
                'signature_hash' => $r->signature_hash,
                'expires_at' => $r->expires_at->toIso8601String(),
                'created_at' => $r->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $waChannels = WhatsAppChannel::query()
            ->with(['defaultCabinet'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->get()
            ->map(static fn (WhatsAppChannel $c): array => [
                'id' => $c->id,
                'display_name' => $c->display_name,
                'phone_number_id' => $c->phone_number_id,
                'business_account_id' => $c->business_account_id,
                'default_cabinet_name' => $c->defaultCabinet->name ?? '',
                'auto_idp_invoices' => $c->auto_idp_invoices,
                'is_active' => $c->is_active,
                'last_inbound_at' => $c->last_inbound_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $waMessages = WhatsAppMessage::query()
            ->with(['document'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(30)
            ->get()
            ->map(static fn (WhatsAppMessage $m): array => [
                'id' => $m->id,
                'direction' => $m->direction,
                'from_phone' => $m->from_phone,
                'to_phone' => $m->to_phone,
                'sender_name' => $m->sender_name,
                'message_type' => $m->message_type,
                'body_text' => $m->body_text,
                'document_id' => $m->document_id,
                'document_number' => $m->document?->doc_number,
                'status' => $m->status,
                'created_at' => $m->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('Portal/Manage', [
            'portalRequests' => $requests,
            'waChannels' => $waChannels,
            'waMessages' => $waMessages,
            'webhookUrl' => url('/api/v1/webhooks/whatsapp'),
            'cabinets' => Cabinet::query()->where('tenant_id', $user->tenant_id)->orderBy('name')->get(['id', 'name']),
            'allowedClearances' => ClearanceLevel::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('rank', '<=', $user->clearance_rank)
                ->orderBy('rank')
                ->get(['id', 'rank', 'name']),
            'documents' => Document::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('classification_rank', '<=', $user->clearance_rank)
                ->latest()
                ->limit(40)
                ->get(['id', 'doc_number', 'title']),
            'vendors' => Vendor::query()->where('tenant_id', $user->tenant_id)->orderBy('name')->get(['id', 'name', 'code']),
            'issuedPortalLink' => session('issued_portal_link'),
            'issuedWaVerifyToken' => session('issued_wa_verify_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{cabinet_id: string, classification_level_id: string, document_id?: string|null, vendor_id?: string|null, request_type: string, title: string, instructions?: string|null, recipient_name: string, recipient_email?: string|null, recipient_phone?: string|null, policy_number?: string|null, claim_number?: string|null, require_otp?: bool, max_uploads?: int, expires_in_hours?: int} $validated */
        $validated = $request->validate([
            'cabinet_id' => ['required', 'uuid'],
            'classification_level_id' => ['required', 'uuid'],
            'document_id' => ['nullable', 'uuid'],
            'vendor_id' => ['nullable', 'uuid'],
            'request_type' => ['required', Rule::in(['upload', 'esign', 'upload_and_esign'])],
            'title' => ['required', 'string', 'max:180'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'recipient_name' => ['required', 'string', 'max:160'],
            'recipient_email' => ['nullable', 'email', 'max:160'],
            'recipient_phone' => ['nullable', 'string', 'max:48'],
            'policy_number' => ['nullable', 'string', 'max:64'],
            'claim_number' => ['nullable', 'string', 'max:64'],
            'require_otp' => ['nullable', 'boolean'],
            'max_uploads' => ['nullable', 'integer', 'min:1', 'max:25'],
            'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $created = $this->portal->createRequest($user, $validated, $request);

        if ($request->wantsJson()) {
            return response()->json([
                'portal_request_id' => $created['portal_request']->id,
                'public_url' => $created['public_url'],
                'plain_token' => $created['plain_token'],
                'plain_otp' => $created['plain_otp'],
            ], 201);
        }

        return back()
            ->with('success', "Magic-link portal created for {$created['portal_request']->recipient_name}.")
            ->with('issued_portal_link', [
                'public_url' => $created['public_url'],
                'plain_otp' => $created['plain_otp'],
                'recipient_name' => $created['portal_request']->recipient_name,
                'recipient_phone' => $created['portal_request']->recipient_phone,
            ]);
    }

    public function revoke(Request $request, ExternalPortalRequest $portalRequest): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($portalRequest->tenant_id === $user->tenant_id, 404);

        $this->portal->revokeRequest($portalRequest, $user, $request);

        if ($request->wantsJson()) {
            return response()->json(['status' => 'revoked']);
        }

        return back()->with('success', 'External portal link revoked immediately.');
    }

    public function storeWhatsAppChannel(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{display_name: string, phone_number_id: string, business_account_id?: string|null, app_secret?: string|null, access_token?: string|null, default_cabinet_id: string, default_classification_level_id: string, auto_idp_invoices?: bool} $validated */
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:160'],
            'phone_number_id' => ['required', 'string', 'max:64'],
            'business_account_id' => ['nullable', 'string', 'max:64'],
            'app_secret' => ['nullable', 'string', 'max:200'],
            'access_token' => ['nullable', 'string', 'max:500'],
            'default_cabinet_id' => ['required', 'uuid'],
            'default_classification_level_id' => ['required', 'uuid'],
            'auto_idp_invoices' => ['nullable', 'boolean'],
        ]);

        $res = $this->whatsapp->configureChannel($user, $validated, $request);

        if ($request->wantsJson()) {
            return response()->json([
                'channel_id' => $res['channel']->id,
                'verify_token' => $res['verify_token'],
            ], 201);
        }

        return back()
            ->with('success', "WhatsApp Business channel '{$res['channel']->display_name}' configured.")
            ->with('issued_wa_verify_token', $res['verify_token']);
    }

    public function sendWhatsAppAlert(Request $request, WhatsAppChannel $channel): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($channel->tenant_id === $user->tenant_id, 404);

        /** @var array{recipient_phone: string, subject: string, action_url: string} $validated */
        $validated = $request->validate([
            'recipient_phone' => ['required', 'string', 'max:48'],
            'subject' => ['required', 'string', 'max:200'],
            'action_url' => ['required', 'string', 'max:500'],
        ]);

        $msg = $this->whatsapp->sendApprovalAlert(
            channel: $channel,
            actor: $user,
            recipientPhone: $validated['recipient_phone'],
            subject: $validated['subject'],
            actionUrl: $validated['action_url'],
            request: $request,
        );

        if ($request->wantsJson()) {
            return response()->json(['message' => $msg]);
        }

        return back()->with('success', "WhatsApp alert status: {$msg->status} (to {$msg->to_phone}).");
    }

    /**
     * Public unauthenticated magic-link portal view (`GET /portal/{token}`).
     */
    public function showPublic(string $token): Response
    {
        $resolved = $this->portal->resolveByToken($token);
        if ($resolved === null) {
            abort(404, 'Portal link not found.');
        }

        $req = $resolved['portal_request'];
        $tenant = $resolved['tenant'];
        $brand = $this->branding->toPublicPayload(null, $tenant);

        return Inertia::render('Portal/PublicPortal', [
            'token' => $token,
            'branding' => [
                'name' => $brand['name'],
                'short_name' => $brand['short_name'],
                'logo_url' => $brand['logo_url'],
                'primary_color' => $brand['primary_color'],
            ],
            'portalRequest' => [
                'request_type' => $req->request_type,
                'title' => $req->title,
                'instructions' => $req->instructions,
                'recipient_name' => $req->recipient_name,
                'policy_number' => $req->policy_number,
                'claim_number' => $req->claim_number,
                'document_title' => $req->document?->title,
                'document_number' => $req->document?->doc_number,
                'status' => $req->expires_at->isPast() && $req->status === 'active' ? 'expired' : $req->status,
                'requires_otp' => $req->otp_hash !== null,
                'uploads_count' => $req->uploads_count,
                'max_uploads' => $req->max_uploads,
                'signed_at' => $req->signed_at?->toIso8601String(),
                'signed_by_name' => $req->signed_by_name,
                'signature_hash' => $req->signature_hash,
                'expires_at' => $req->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function publicUpload(Request $request, string $token): RedirectResponse|JsonResponse
    {
        /** @var array{file: UploadedFile, otp?: string|null, uploader_note?: string|null} $validated */
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:25600', 'mimes:pdf,png,jpg,jpeg,webp,tif,tiff,bmp'],
            'otp' => ['nullable', 'string', 'max:16'],
            'uploader_note' => ['nullable', 'string', 'max:400'],
        ]);

        $file = $validated['file'];
        $rawBytes = file_get_contents($file->getRealPath());
        if (! is_string($rawBytes) || $rawBytes === '') {
            throw ValidationException::withMessages(['file' => 'Uploaded file is empty.']);
        }

        try {
            $doc = $this->portal->submitUpload(
                plainToken: $token,
                filename: $file->getClientOriginalName() ?: 'portal-upload.pdf',
                rawBytes: $rawBytes,
                otp: $validated['otp'] ?? null,
                uploaderNote: $validated['uploader_note'] ?? null,
                request: $request,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['portal' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'document_id' => $doc->id,
                'doc_number' => $doc->doc_number,
                'policy_number' => $doc->policy_number,
                'claim_number' => $doc->claim_number,
            ], 201);
        }

        return back()->with('success', "File received & virus-scanned. Reference: {$doc->doc_number}");
    }

    public function publicSign(Request $request, string $token): RedirectResponse|JsonResponse
    {
        /** @var array{signer_name: string, signer_id_number: string, otp?: string|null} $validated */
        $validated = $request->validate([
            'signer_name' => ['required', 'string', 'max:160'],
            'signer_id_number' => ['required', 'string', 'max:64'],
            'otp' => ['nullable', 'string', 'max:16'],
        ]);

        try {
            $signed = $this->portal->submitSignature(
                plainToken: $token,
                signerName: $validated['signer_name'],
                signerIdNumber: $validated['signer_id_number'],
                otp: $validated['otp'] ?? null,
                request: $request,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['portal' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'status' => $signed->status,
                'signed_by_name' => $signed->signed_by_name,
                'signature_hash' => $signed->signature_hash,
                'signed_at' => $signed->signed_at?->toIso8601String(),
            ]);
        }

        return back()->with('success', "Document signed with tamper-evident seal: {$signed->signature_hash}");
    }
}
