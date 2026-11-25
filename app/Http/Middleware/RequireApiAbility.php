<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiAbility
{
    /**
     * Enforce scoped Sanctum token abilities on API endpoints.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->tokenCan($ability) && ! $user->tokenCan('*')) {
            return response()->json([
                'message' => "API token lacks required scope '{$ability}'.",
            ], 403);
        }

        return $next($request);
    }
}
