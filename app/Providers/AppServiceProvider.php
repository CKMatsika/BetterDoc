<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Document;
use App\Models\Tenant;
use App\Policies\DocumentPolicy;
use App\Services\Profile\ProfileManager;
use App\Services\Security\BetterDocHttpClientGuard;
use App\Services\Security\DbSecurityContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Pennant\Feature;
use Psr\Http\Message\RequestInterface;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DbSecurityContext::class);
    }

    public function boot(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);

        $trustedProxies = trim(config()->string('betterdoc.security.trusted_proxies', ''));
        if ($trustedProxies !== '') {
            $proxies = $trustedProxies === '*'
                ? '*'
                : array_values(array_filter(array_map('trim', explode(',', $trustedProxies))));
            TrustProxies::at($proxies);
        }

        if (config()->boolean('betterdoc.security.enforce_https', false)) {
            URL::forceScheme('https');
        }

        RateLimiter::for('login', static function (Request $request): array {
            $ip = (string) ($request->ip() ?? 'unknown');
            $identifier = strtolower(trim($request->string('identifier')->toString()));

            return [
                Limit::perMinute(10)->by($ip.'|'.$identifier),
                Limit::perMinute(30)->by($ip),
            ];
        });

        RateLimiter::for('mfa', static function (Request $request): Limit {
            $actor = $request->user();
            $key = $actor !== null ? (string) $actor->id : (string) ($request->ip() ?? 'unknown');

            return Limit::perMinute(10)->by($key);
        });

        RateLimiter::for('public-verify', static fn (Request $request): Limit => Limit::perMinute(60)->by((string) ($request->ip() ?? 'unknown')));

        // Hard runtime egress block in air-gapped mode for every Laravel Http:: client call
        Http::globalRequestMiddleware(
            static fn (RequestInterface $request): RequestInterface => app(BetterDocHttpClientGuard::class)->assertOutboundAllowed($request)
        );

        // Guarantee PostgreSQL session security GUCs never bleed between Octane requests/tasks
        if (class_exists(RequestTerminated::class)) {
            Event::listen(RequestTerminated::class, static function (): void {
                app(DbSecurityContext::class)->clear();
            });
            Event::listen(TaskTerminated::class, static function (): void {
                app(DbSecurityContext::class)->clear();
            });
        }

        // Pennant feature resolvers backed by active tenant profile + air-gap safety floor
        foreach ([
            'correspondence_registry',
            'ap_automation',
            'zimra_e_invoicing',
            'contracts_lifecycle',
            'hr_vault',
            'cloud_sync',
            'cloud_ai',
            'two_person_strict',
            'secure_print_default',
        ] as $featureName) {
            Feature::define($featureName, static function (Tenant $tenant) use ($featureName): bool {
                if (config()->boolean('betterdoc.air_gapped', true) && in_array($featureName, ['cloud_sync', 'cloud_ai'], true)) {
                    return false;
                }

                $config = app(ProfileManager::class)->activeConfig($tenant);
                $features = $config['features'] ?? null;

                return is_array($features) && (bool) ($features[$featureName] ?? false);
            });
        }
    }
}
