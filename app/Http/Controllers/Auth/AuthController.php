<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Auth\LdapDirectoryService;
use App\Services\Security\IpMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

class AuthController extends Controller
{
    public function __construct(
        private readonly ImmutableAuditLogger $audit,
        private readonly LdapDirectoryService $ldap,
    ) {}

    public function showLogin(): Response
    {
        $demoUsers = [];
        if ((bool) config('betterdoc.demo_switcher_enabled', false)) {
            $demoUsers = User::query()
                ->with(['tenant', 'clearanceLevel', 'orgUnit', 'compartments'])
                ->orderBy('tenant_id')
                ->orderByDesc('clearance_rank')
                ->get()
                ->map(static function (User $user): array {
                    app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'service_number' => $user->service_number,
                        'rank_title' => $user->rank_title,
                        'tenant_slug' => $user->tenant?->slug,
                        'tenant_name' => $user->tenant?->name,
                        'profile' => $user->tenant?->active_profile_code->value,
                        'clearance_name' => $user->clearanceLevel?->name,
                        'clearance_color' => $user->clearanceLevel?->color_hex,
                        'clearance_rank' => $user->clearance_rank,
                        'org_unit_code' => $user->orgUnit?->code,
                        'compartments' => $user->compartments->pluck('code')->values()->all(),
                        'roles' => $user->getRoleNames()->values()->all(),
                    ];
                })
                ->values()
                ->all();
        }

        return Inertia::render('Auth/Login', [
            'demoUsers' => $demoUsers,
        ]);
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $identifier = trim($request->string('identifier')->toString());
        $password = $request->string('password')->toString();

        /** @var User|null $user */
        $user = User::query()
            ->where('email', strtolower($identifier))
            ->orWhere('service_number', $identifier)
            ->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'identifier' => 'Invalid credentials.',
            ]);
        }

        if ($user->status !== UserStatus::Active) {
            throw ValidationException::withMessages([
                'identifier' => 'Account is suspended or inactive.',
            ]);
        }

        if ($user->locked_until !== null && $user->locked_until->isFuture()) {
            throw ValidationException::withMessages([
                'identifier' => 'Account temporarily locked due to repeated failed attempts.',
            ]);
        }

        if (is_array($user->allowed_cidrs) && $user->allowed_cidrs !== [] && ! IpMatcher::matchesAny((string) $request->ip(), $user->allowed_cidrs)) {
            $this->audit->log(
                tenantId: $user->tenant_id,
                eventType: 'auth.ip_denied',
                resourceType: 'user',
                resourceId: $user->id,
                actor: $user,
                classificationRank: $user->clearance_rank,
                after: ['ip' => $request->ip()],
                request: $request,
            );

            throw ValidationException::withMessages([
                'identifier' => 'Login denied from unauthorized network subnet.',
            ]);
        }

        $authenticated = $user->auth_source === 'ldap' && $this->ldap->isEnabled()
            ? $this->ldap->authenticate($identifier, $password) !== null
            : Hash::check($password, $user->password);

        if (! $authenticated) {
            $attempts = $user->failed_login_attempts + 1;
            $maxAttempts = config()->integer('betterdoc.security.max_failed_logins', 5);
            $lockMinutes = config()->integer('betterdoc.security.lockout_minutes', 15);

            $user->update([
                'failed_login_attempts' => $attempts,
                'locked_until' => $attempts >= $maxAttempts ? now()->addMinutes($lockMinutes) : null,
            ]);

            $this->audit->log(
                tenantId: $user->tenant_id,
                eventType: 'auth.login_failed',
                resourceType: 'user',
                resourceId: $user->id,
                actor: $user,
                classificationRank: $user->clearance_rank,
                after: ['attempts' => $attempts],
                request: $request,
            );

            throw ValidationException::withMessages([
                'identifier' => 'Invalid credentials.',
            ]);
        }

        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        UserSession::query()->create([
            'user_id' => $user->id,
            'session_id' => $request->session()->getId(),
            'ip_address' => (string) $request->ip(),
            'user_agent_hash' => hash('sha256', (string) ($request->userAgent() ?? 'unknown')),
            'device_label' => substr((string) ($request->userAgent() ?? 'Workstation'), 0, 100),
            'mfa_verified_at' => $user->mfa_enforced ? null : now(),
            'last_seen_at' => now(),
        ]);

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: 'auth.login_success',
            resourceType: 'user',
            resourceId: $user->id,
            actor: $user,
            classificationRank: $user->clearance_rank,
            request: $request,
        );

        if ($user->mfa_enforced) {
            return $user->hasMfaConfigured()
                ? redirect()->route('mfa.challenge')
                : redirect()->route('security.mfa.setup');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function demoLogin(Request $request): RedirectResponse
    {
        abort_unless((bool) config('betterdoc.demo_switcher_enabled', false), 404);

        /** @var array{user_id: string} $validated */
        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
        ]);

        /** @var User $user */
        $user = User::query()->findOrFail($validated['user_id']);

        Auth::login($user);
        $request->session()->regenerate();

        UserSession::query()->create([
            'user_id' => $user->id,
            'session_id' => $request->session()->getId(),
            'ip_address' => (string) $request->ip(),
            'user_agent_hash' => hash('sha256', (string) ($request->userAgent() ?? 'unknown')),
            'device_label' => 'Demo Persona Switch',
            'mfa_verified_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: 'auth.demo_persona_switch',
            resourceType: 'user',
            resourceId: $user->id,
            actor: $user,
            classificationRank: $user->clearance_rank,
            request: $request,
        );

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null) {
            UserSession::query()
                ->where('user_id', $user->id)
                ->where('session_id', $request->session()->getId())
                ->update(['revoked_at' => now()]);

            $this->audit->log(
                tenantId: $user->tenant_id,
                eventType: 'auth.logout',
                resourceType: 'user',
                resourceId: $user->id,
                actor: $user,
                classificationRank: $user->clearance_rank,
                request: $request,
            );
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
