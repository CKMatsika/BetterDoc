<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificateCustodyMode;
use App\Enums\SentinelRole;
use App\Models\CertificateAuthority;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserCertificate;
use App\Services\Pki\InternalCaService;
use App\Services\Security\DbSecurityContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PkiController extends Controller
{
    public function __construct(
        private readonly InternalCaService $ca,
        private readonly DbSecurityContext $dbContext,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($user->tenant_id);

        $isAdminOrRecords = $user->hasAnyRole([SentinelRole::SystemAdmin->value, SentinelRole::RecordsOfficer->value]);

        $authorities = CertificateAuthority::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('role')
            ->get()
            ->map(static fn (CertificateAuthority $ca): array => [
                'id' => $ca->id,
                'name' => $ca->name,
                'role' => $ca->role,
                'serial_number' => $ca->serial_number,
                'subject_dn' => $ca->subject_dn,
                'fingerprint_sha256' => $ca->fingerprint_sha256,
                'crl_number' => $ca->crl_number,
                'valid_from' => $ca->valid_from->toIso8601String(),
                'valid_to' => $ca->valid_to->toIso8601String(),
            ])
            ->values()
            ->all();

        $certsQuery = UserCertificate::query()
            ->with('user')
            ->where('tenant_id', $tenant->id)
            ->latest();

        if (! $isAdminOrRecords) {
            $certsQuery->where('user_id', $user->id);
        }

        $certificates = $certsQuery->get()->map(static fn (UserCertificate $cert): array => [
            'id' => $cert->id,
            'user_id' => $cert->user_id,
            'user_name' => $cert->user->name ?? 'Unknown',
            'user_email' => $cert->user->email ?? '',
            'serial_number' => $cert->serial_number,
            'subject_dn' => $cert->subject_dn,
            'fingerprint_sha256' => $cert->fingerprint_sha256,
            'custody_mode' => $cert->custody_mode->value,
            'pkcs11_key_label' => $cert->pkcs11_key_label,
            'status' => $cert->status->value,
            'valid_from' => $cert->valid_from->toIso8601String(),
            'valid_to' => $cert->valid_to->toIso8601String(),
            'revoked_at' => $cert->revoked_at?->toIso8601String(),
            'revocation_reason' => $cert->revocation_reason,
        ])->values()->all();

        $tenantUsers = $isAdminOrRecords
            ? User::query()->where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name', 'email', 'rank_title'])->toArray()
            : [];

        return Inertia::render('Pki/Certificates', [
            'tenantId' => $tenant->id,
            'authorities' => $authorities,
            'certificates' => $certificates,
            'tenantUsers' => $tenantUsers,
            'canManageAll' => $isAdminOrRecords,
            'urls' => [
                'root_ca' => route('pki.root_ca', $tenant->id),
                'crl' => route('pki.crl', $tenant->id),
            ],
        ]);
    }

    public function bootstrap(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(
            $user->hasAnyRole([SentinelRole::SystemAdmin->value, SentinelRole::RecordsOfficer->value]),
            403
        );

        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($user->tenant_id);
        $this->ca->ensureBootstrapped($tenant, $user, $request);

        return back()->with('success', 'Internal Offline Root CA, Intermediate CA, Local TSA, and OCSP Responder initialized.');
    }

    public function issue(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $isAdminOrRecords = $user->hasAnyRole([SentinelRole::SystemAdmin->value, SentinelRole::RecordsOfficer->value]);

        /** @var array{user_id?: ?string, custody_mode: string, valid_days?: ?int, csr_pem?: ?string, pkcs11_slot_id?: ?string} $validated */
        $validated = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'custody_mode' => ['required', Rule::enum(CertificateCustodyMode::class)],
            'valid_days' => ['nullable', 'integer', 'min:1', 'max:1825'],
            'csr_pem' => ['nullable', 'string', 'max:16384'],
            'pkcs11_slot_id' => ['nullable', 'string', 'max:32'],
        ]);

        $targetUserId = $validated['user_id'] ?? $user->id;
        if ($targetUserId !== $user->id && ! $isAdminOrRecords) {
            abort(403, 'Only System Admins or Records Officers may issue certificates for other personnel.');
        }

        /** @var User $subjectUser */
        $subjectUser = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', $targetUserId)
            ->firstOrFail();

        $custodyMode = CertificateCustodyMode::from($validated['custody_mode']);
        if ($custodyMode === CertificateCustodyMode::SmartcardPkcs11 && empty($validated['csr_pem'])) {
            return back()->withErrors(['csr_pem' => 'Smartcard/PKCS#11 certificates require a client-generated CSR (private key never touches the server).']);
        }

        $cert = $this->ca->issueUserCertificate(
            subjectUser: $subjectUser,
            custodyMode: $custodyMode,
            actor: $user,
            validDays: (int) ($validated['valid_days'] ?? 365),
            csrPem: $validated['csr_pem'] ?? null,
            pkcs11SlotId: $validated['pkcs11_slot_id'] ?? null,
            request: $request,
        );

        return back()->with('success', "X.509 Certificate {$cert->serial_number} issued for {$subjectUser->name}.");
    }

    public function revoke(Request $request, UserCertificate $certificate): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $isAdminOrRecords = $user->hasAnyRole([SentinelRole::SystemAdmin->value, SentinelRole::RecordsOfficer->value]);

        if ($certificate->user_id !== $user->id && ! $isAdminOrRecords) {
            abort(403, 'Not authorized to revoke this certificate.');
        }

        /** @var array{reason: string} $validated */
        $validated = $request->validate([
            'reason' => ['required', Rule::in([
                'key_compromise',
                'cessation_of_operation',
                'privilege_withdrawn',
                'affiliation_changed',
                'superseded',
            ])],
        ]);

        $this->ca->revokeCertificate($certificate, $user, $validated['reason'], $request);

        return back()->with('success', "Certificate {$certificate->serial_number} revoked and CRL updated.");
    }

    public function downloadRootCa(string $tenantId): HttpResponse
    {
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        /** @var CertificateAuthority|null $root */
        $root = $this->dbContext->runForTenant($tenant->id, static fn (): ?CertificateAuthority => CertificateAuthority::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'root')
            ->first());

        if ($root === null) {
            abort(404, 'Tenant Root CA not initialized.');
        }

        return response($root->certificate_pem, 200, [
            'Content-Type' => 'application/x-pem-file',
            'Content-Disposition' => "attachment; filename=\"{$tenant->slug}-root-ca.crt\"",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function downloadCrl(string $tenantId): HttpResponse
    {
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        $der = $this->dbContext->runForTenant($tenant->id, fn (): string => $this->ca->latestCrlDer($tenant));

        return response($der, 200, [
            'Content-Type' => 'application/pkix-crl',
            'Content-Disposition' => "inline; filename=\"{$tenant->slug}.crl\"",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function ocspCheck(string $tenantId, string $serial): HttpResponse
    {
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        $der = $this->dbContext->runForTenant($tenant->id, fn (): string => $this->ca->buildOcspResponseDer($tenant, $serial));

        return response($der, 200, [
            'Content-Type' => 'application/ocsp-response',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
