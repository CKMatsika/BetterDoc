<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Security\DbSecurityContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetApiPgSecurityContext
{
    public function __construct(
        private readonly DbSecurityContext $dbContext,
    ) {}

    /**
     * Bind PostgreSQL FORCE ROW LEVEL SECURITY session variables for Sanctum-authenticated API requests.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->status !== UserStatus::Active) {
            return response()->json(['message' => 'Account is suspended or inactive.'], 403);
        }

        $user->loadMissing(['orgUnit', 'clearanceLevel', 'tenant']);
        $this->dbContext->applyForUser($user);

        try {
            return $next($request);
        } finally {
            try {
                $this->dbContext->clear();
            } catch (\Throwable) {
                // Preserve original exception when an active PostgreSQL transaction is aborted.
            }
        }
    }
}
