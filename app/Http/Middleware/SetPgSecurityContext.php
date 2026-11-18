<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Security\DbSecurityContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetPgSecurityContext
{
    public function __construct(
        private readonly DbSecurityContext $dbContext,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null) {
            if ($user->status !== UserStatus::Active) {
                if ($request->hasSession()) {
                    Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                abort(403, 'Account is suspended or inactive.');
            }

            $sessionId = $request->hasSession() ? $request->session()->getId() : '';
            if ($sessionId !== '') {
                /** @var UserSession|null $userSession */
                $userSession = UserSession::query()
                    ->where('user_id', $user->id)
                    ->where('session_id', $sessionId)
                    ->first();

                if ($userSession !== null) {
                    if ($userSession->revoked_at !== null) {
                        Auth::guard('web')->logout();
                        $request->session()->invalidate();
                        abort(401, 'Session revoked.');
                    }

                    $uaHash = hash('sha256', (string) ($request->userAgent() ?? 'unknown'));
                    if ($userSession->user_agent_hash !== $uaHash) {
                        $userSession->update(['revoked_at' => now()]);
                        $this->audit->log(
                            tenantId: $user->tenant_id,
                            eventType: 'security.session_device_mismatch',
                            resourceType: 'user_session',
                            resourceId: $userSession->id,
                            actor: $user,
                            classificationRank: $user->clearance_rank,
                            request: $request,
                        );
                        Auth::guard('web')->logout();
                        $request->session()->invalidate();
                        abort(401, 'Session device binding mismatch.');
                    }

                    $userSession->update(['last_seen_at' => now()]);
                }
            }

            $user->loadMissing(['orgUnit', 'clearanceLevel', 'tenant']);
        }

        $this->dbContext->applyForUser($user);

        try {
            return $next($request);
        } finally {
            try {
                $this->dbContext->clear();
            } catch (\Throwable) {
                // Preserve the original exception if the PostgreSQL transaction is already aborted.
            }
        }
    }
}
