<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\TwoPersonStatus;
use App\Models\TwoPersonRequest;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Profile\ProfileManager;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\PermissionRegistrar;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(
        private readonly ProfileManager $profiles,
    ) {}

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $authPayload = null;
        $tenantPayload = null;
        $pendingApprovals = 0;

        if ($user !== null) {
            $user->loadMissing(['tenant', 'orgUnit', 'clearanceLevel', 'compartments']);
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

            $sessionId = $request->hasSession() ? $request->session()->getId() : '';
            $sessionRecord = UserSession::query()
                ->where('user_id', $user->id)
                ->where('session_id', $sessionId)
                ->whereNull('revoked_at')
                ->first();

            $stepUpTtl = config()->integer('sentinel.security.step_up_mfa_ttl_minutes', 5);

            $authPayload = [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'service_number' => $user->service_number,
                    'rank_title' => $user->rank_title,
                    'clearance_rank' => $user->clearance_rank,
                    'clearance' => $user->clearanceLevel ? [
                        'id' => $user->clearanceLevel->id,
                        'code' => $user->clearanceLevel->code,
                        'name' => $user->clearanceLevel->name,
                        'color_hex' => $user->clearanceLevel->color_hex,
                    ] : null,
                    'org_unit' => $user->orgUnit ? [
                        'id' => $user->orgUnit->id,
                        'code' => $user->orgUnit->code,
                        'name' => $user->orgUnit->name,
                        'path' => $user->orgUnit->path,
                    ] : null,
                    'compartments' => $user->compartments->map(static fn ($c): array => [
                        'id' => $c->id,
                        'code' => $c->code,
                        'name' => $c->name,
                    ])->values()->all(),
                    'roles' => $user->getRoleNames()->values()->all(),
                    'mfa_configured' => $user->hasMfaConfigured(),
                    'mfa_step_up_fresh' => $sessionRecord?->isMfaFresh($stepUpTtl) ?? false,
                ],
            ];

            if ($user->tenant !== null) {
                $profileConfig = $this->profiles->activeConfig($user->tenant);
                $tenantPayload = [
                    'id' => $user->tenant->id,
                    'slug' => $user->tenant->slug,
                    'name' => $user->tenant->name,
                    'hosting_mode' => $user->tenant->hosting_mode->value,
                    'hosting_mode_label' => $user->tenant->hosting_mode->label(),
                    'air_gapped' => $user->tenant->air_gapped,
                    'active_profile_code' => $user->tenant->active_profile_code->value,
                    'branding' => $profileConfig['branding'] ?? [],
                    'terminology' => $profileConfig['terminology'] ?? [],
                    'features' => $profileConfig['features'] ?? [],
                ];

                $pendingApprovals = TwoPersonRequest::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('status', TwoPersonStatus::Pending->value)
                    ->where('requested_by', '!=', $user->id)
                    ->where('target_classification_rank', '<=', $user->clearance_rank)
                    ->count();
            }
        }

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name', 'SENTINEL DMS'),
                'air_gapped' => (bool) config('sentinel.air_gapped', true),
                'demo_switcher_enabled' => (bool) config('sentinel.demo_switcher_enabled', false),
            ],
            'auth' => $authPayload,
            'tenant' => $tenantPayload,
            'pendingApprovalsCount' => $pendingApprovals,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
