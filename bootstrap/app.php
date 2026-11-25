<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceMfaMiddleware;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireApiAbility;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SetApiPgSecurityContext;
use App\Http\Middleware\SetPgSecurityContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth', 'mfa.enforced']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'wopi/*',
            'local-agent/*',
        ]);
        $middleware->append(SecurityHeadersMiddleware::class);

        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            SetPgSecurityContext::class,
        );

        $middleware->web(append: [
            SetPgSecurityContext::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'mfa.enforced' => EnforceMfaMiddleware::class,
            'api.pg' => SetApiPgSecurityContext::class,
            'api.ability' => RequireApiAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
