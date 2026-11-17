<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceMfaMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || ! $user->mfa_enforced) {
            return $next($request);
        }

        if ($request->routeIs('security.mfa.*', 'mfa.*', 'logout')) {
            return $next($request);
        }

        if (! $user->hasMfaConfigured()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'MFA enrollment is required before accessing protected endpoints.'], 403);
            }

            return redirect()->route('security.mfa.setup');
        }

        $sessionId = $request->hasSession() ? $request->session()->getId() : '';
        $verified = UserSession::query()
            ->where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->whereNull('revoked_at')
            ->whereNotNull('mfa_verified_at')
            ->exists();

        if (! $verified) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'MFA verification is required for this session.'], 403);
            }

            return redirect()->route('mfa.challenge');
        }

        return $next($request);
    }
}
