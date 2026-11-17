<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Auth\TotpMfaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MfaController extends Controller
{
    public function __construct(
        private readonly TotpMfaService $totp,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function showSetup(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $rawPending = $request->session()->get('sentinel_mfa_pending_secret');
        $pendingSecret = is_string($rawPending) ? $rawPending : '';
        if ($pendingSecret === '') {
            $pendingSecret = $this->totp->generateSecret();
            $request->session()->put('sentinel_mfa_pending_secret', $pendingSecret);
        }

        $qrSvg = $this->totp->provisionSvgQrCode($user, $pendingSecret);

        return Inertia::render('Security/MfaSetup', [
            'secret' => $pendingSecret,
            'qrSvg' => $qrSvg,
            'alreadyConfigured' => $user->hasMfaConfigured(),
        ]);
    }

    public function confirmSetup(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{code: string} $validated */
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:12'],
        ]);

        $rawPending = $request->session()->get('sentinel_mfa_pending_secret');
        $pendingSecret = is_string($rawPending) ? $rawPending : '';
        if ($pendingSecret === '' || ! $this->totp->verifyCode($pendingSecret, $validated['code'], isEncrypted: false)) {
            throw ValidationException::withMessages([
                'code' => 'Invalid authenticator code. Verify device time synchronization.',
            ]);
        }

        $recoveryCodes = $this->totp->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($pendingSecret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($recoveryCodes, JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $request->session()->forget('sentinel_mfa_pending_secret');

        UserSession::query()
            ->where('user_id', $user->id)
            ->where('session_id', $request->session()->getId())
            ->whereNull('revoked_at')
            ->update(['mfa_verified_at' => now()]);

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: 'auth.mfa_enrolled',
            resourceType: 'user',
            resourceId: $user->id,
            actor: $user,
            classificationRank: $user->clearance_rank,
            request: $request,
        );

        return redirect()->route('dashboard')
            ->with('success', 'MFA enrolled offline. Recovery codes: '.implode(', ', $recoveryCodes));
    }

    public function showChallenge(): Response
    {
        return Inertia::render('Auth/MfaChallenge');
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{code: string} $validated */
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:20'],
        ]);

        if (! $this->verifyUserCode($user, $validated['code'])) {
            $this->audit->log(
                tenantId: $user->tenant_id,
                eventType: 'auth.mfa_failed',
                resourceType: 'user',
                resourceId: $user->id,
                actor: $user,
                classificationRank: $user->clearance_rank,
                request: $request,
            );

            throw ValidationException::withMessages([
                'code' => 'Invalid MFA verification code.',
            ]);
        }

        UserSession::query()
            ->where('user_id', $user->id)
            ->where('session_id', $request->session()->getId())
            ->whereNull('revoked_at')
            ->update(['mfa_verified_at' => now()]);

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: 'auth.mfa_verified',
            resourceType: 'user',
            resourceId: $user->id,
            actor: $user,
            classificationRank: $user->clearance_rank,
            request: $request,
        );

        return redirect()->intended(route('dashboard'));
    }

    public function stepUp(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{code: string} $validated */
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:20'],
        ]);

        if (! $this->verifyUserCode($user, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Step-up MFA verification failed.',
            ]);
        }

        UserSession::query()
            ->where('user_id', $user->id)
            ->where('session_id', $request->session()->getId())
            ->whereNull('revoked_at')
            ->update(['mfa_verified_at' => now()]);

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: 'auth.step_up_verified',
            resourceType: 'user',
            resourceId: $user->id,
            actor: $user,
            classificationRank: $user->clearance_rank,
            request: $request,
        );

        return back()->with('success', 'Step-up MFA verified for 5 minutes.');
    }

    private function verifyUserCode(User $user, string $code): bool
    {
        if ($user->two_factor_secret !== null && $this->totp->verifyCode((string) $user->two_factor_secret, $code, isEncrypted: true)) {
            return true;
        }

        if ($user->two_factor_recovery_codes !== null) {
            /** @var list<string> $codes */
            $codes = json_decode(Crypt::decryptString((string) $user->two_factor_recovery_codes), true, 512, JSON_THROW_ON_ERROR);
            $normalized = strtoupper(trim($code));
            $idx = array_search($normalized, $codes, true);
            if ($idx !== false) {
                unset($codes[$idx]);
                $user->forceFill([
                    'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($codes), JSON_THROW_ON_ERROR)),
                ])->save();

                return true;
            }
        }

        return false;
    }
}
