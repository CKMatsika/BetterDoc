<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BetterDocRole;
use App\Models\DemoRequest;
use App\Models\OrganisationSetting;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Branding\BrandingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

class BrandingSettingsController extends Controller
{
    public function __construct(
        private readonly BrandingService $branding,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeBranding($user);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        $demoRequests = DemoRequest::query()
            ->latest()
            ->limit(100)
            ->get()
            ->map(static fn (DemoRequest $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'organisation' => $d->organisation,
                'work_email' => $d->work_email,
                'phone' => $d->phone,
                'message' => $d->message,
                'status' => $d->status,
                'created_at' => $d->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('Admin/Branding', [
            'currentBranding' => $this->branding->toPublicPayload($user, $tenant),
            'presets' => array_values($this->branding->presets()),
            'landingModeEnv' => config()->string('betterdoc.landing_mode', 'portal'),
            'demoRequests' => $demoRequests,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeBranding($user);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        /** @var array{
         *   name: string,
         *   short_name: string,
         *   tagline: string,
         *   primary_color: string,
         *   accent_color: string,
         *   welcome_title: string,
         *   welcome_text?: string|null,
         *   notice_text?: string|null,
         *   notice_enabled?: bool,
         *   support_email?: string|null,
         *   support_phone?: string|null,
         *   support_hours?: string|null,
         *   address?: string|null,
         *   website?: string|null,
         *   show_powered_by?: bool,
         *   landing_mode?: string|null
         * } $validated
         */
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'short_name' => ['required', 'string', 'min:2', 'max:64'],
            'tagline' => ['required', 'string', 'max:255'],
            'primary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'welcome_title' => ['required', 'string', 'max:200'],
            'welcome_text' => ['nullable', 'string', 'max:2000'],
            'notice_text' => ['nullable', 'string', 'max:1000'],
            'notice_enabled' => ['nullable', 'boolean'],
            'support_email' => ['nullable', 'email', 'max:160'],
            'support_phone' => ['nullable', 'string', 'max:64'],
            'support_hours' => ['nullable', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'show_powered_by' => ['nullable', 'boolean'],
            'landing_mode' => ['nullable', Rule::in(['portal', 'demo', 'off'])],
            'logo' => ['nullable', 'file', 'max:2048'],
            'logo_dark' => ['nullable', 'file', 'max:2048'],
            'favicon' => ['nullable', 'file', 'max:2048'],
        ]);

        /** @var OrganisationSetting $setting */
        $setting = OrganisationSetting::query()->firstOrNew(['tenant_id' => $tenant->id]);
        if (! $setting->exists) {
            $setting->profile_key = $tenant->active_profile_code->value;
        }

        $logoPath = $setting->logo_path;
        $logoDarkPath = $setting->logo_dark_path;
        $faviconPath = $setting->favicon_path;

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            if ($file !== null) {
                $logoPath = $this->branding->sanitizeAndStoreUpload($file, 'logo', $tenant->id);
            }
        }
        if ($request->hasFile('logo_dark')) {
            $file = $request->file('logo_dark');
            if ($file !== null) {
                $logoDarkPath = $this->branding->sanitizeAndStoreUpload($file, 'logo_dark', $tenant->id);
            }
        }
        if ($request->hasFile('favicon')) {
            $file = $request->file('favicon');
            if ($file !== null) {
                $faviconPath = $this->branding->sanitizeAndStoreUpload($file, 'favicon', $tenant->id);
            }
        }

        $setting->fill([
            'is_public_default' => true,
            'name' => trim($validated['name']),
            'short_name' => trim($validated['short_name']),
            'tagline' => trim($validated['tagline']),
            'logo_path' => $logoPath,
            'logo_dark_path' => $logoDarkPath,
            'favicon_path' => $faviconPath,
            'primary_color' => strtoupper($validated['primary_color']),
            'accent_color' => strtoupper($validated['accent_color']),
            'welcome_title' => trim($validated['welcome_title']),
            'welcome_text' => isset($validated['welcome_text']) ? trim($validated['welcome_text']) : null,
            'notice_text' => isset($validated['notice_text']) ? trim($validated['notice_text']) : null,
            'notice_enabled' => $request->boolean('notice_enabled'),
            'support_email' => isset($validated['support_email']) ? trim($validated['support_email']) : null,
            'support_phone' => isset($validated['support_phone']) ? trim($validated['support_phone']) : null,
            'support_hours' => isset($validated['support_hours']) ? trim($validated['support_hours']) : null,
            'address' => isset($validated['address']) ? trim($validated['address']) : null,
            'website' => isset($validated['website']) ? trim($validated['website']) : null,
            'show_powered_by' => $request->boolean('show_powered_by', true),
            'landing_mode' => isset($validated['landing_mode']) && $validated['landing_mode'] !== '' ? $validated['landing_mode'] : null,
        ]);
        $setting->save();
        $this->branding->activatePublicDefault($setting);

        $this->audit->log(
            tenantId: $tenant->id,
            eventType: 'admin.branding_updated',
            resourceType: 'organisation_setting',
            resourceId: $setting->id,
            actor: $user,
            classificationRank: $user->clearance_rank,
            after: [
                'name' => $setting->name,
                'primary_color' => $setting->primary_color,
                'accent_color' => $setting->accent_color,
                'landing_mode' => $setting->landing_mode,
            ],
            request: $request,
        );

        return back()->with('success', "Branding settings updated for '{$setting->name}'.");
    }

    public function applyPreset(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeBranding($user);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        /** @var array{profile_key: string} $validated */
        $validated = $request->validate([
            'profile_key' => ['required', Rule::in(['betterdoc', 'defence', 'financial', 'government', 'corporate'])],
        ]);

        $presets = $this->branding->presets();
        $preset = $presets[$validated['profile_key']];

        $setting = OrganisationSetting::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'is_public_default' => true,
                'profile_key' => $preset['profile_key'],
                'name' => $preset['name'],
                'short_name' => $preset['short_name'],
                'tagline' => $preset['tagline'],
                'primary_color' => $preset['primary_color'],
                'accent_color' => $preset['accent_color'],
                'welcome_title' => $preset['welcome_title'],
                'welcome_text' => $preset['welcome_text'],
                'notice_text' => $preset['notice_text'],
                'notice_enabled' => $preset['notice_enabled'],
                'support_email' => $preset['support_email'],
                'support_phone' => $preset['support_phone'],
                'support_hours' => $preset['support_hours'],
                'address' => $preset['address'],
                'website' => $preset['website'],
                'show_powered_by' => $preset['show_powered_by'],
            ]
        );
        $this->branding->activatePublicDefault($setting);

        $this->audit->log(
            tenantId: $tenant->id,
            eventType: 'admin.branding_preset_applied',
            resourceType: 'organisation_setting',
            resourceId: $setting->id,
            actor: $user,
            classificationRank: $user->clearance_rank,
            after: ['profile_key' => $preset['profile_key']],
            request: $request,
        );

        return back()->with('success', "Applied '{$preset['label']}' branding preset.");
    }

    public function reset(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeBranding($user);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        $defaults = $this->branding->presets()['betterdoc'];

        $setting = OrganisationSetting::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'is_public_default' => true,
                'profile_key' => $defaults['profile_key'],
                'name' => $defaults['name'],
                'short_name' => $defaults['short_name'],
                'tagline' => $defaults['tagline'],
                'logo_path' => null,
                'logo_dark_path' => null,
                'favicon_path' => null,
                'primary_color' => $defaults['primary_color'],
                'accent_color' => $defaults['accent_color'],
                'welcome_title' => $defaults['welcome_title'],
                'welcome_text' => $defaults['welcome_text'],
                'notice_text' => $defaults['notice_text'],
                'notice_enabled' => $defaults['notice_enabled'],
                'support_email' => $defaults['support_email'],
                'support_phone' => $defaults['support_phone'],
                'support_hours' => $defaults['support_hours'],
                'address' => $defaults['address'],
                'website' => $defaults['website'],
                'show_powered_by' => true,
                'landing_mode' => null,
            ]
        );
        $this->branding->activatePublicDefault($setting);

        $this->audit->log(
            tenantId: $tenant->id,
            eventType: 'admin.branding_reset',
            resourceType: 'organisation_setting',
            resourceId: $setting->id,
            actor: $user,
            classificationRank: $user->clearance_rank,
            request: $request,
        );

        return back()->with('success', 'Organisation branding reset to default BetterDoc DMS settings.');
    }

    private function authorizeBranding(User $user): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        abort_unless(
            $user->hasRole(BetterDocRole::SystemAdmin->value) && $user->can('manage-branding'),
            403
        );
    }
}
