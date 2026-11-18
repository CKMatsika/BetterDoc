<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\OrganisationSetting;
use App\Models\Tenant;
use App\Services\Branding\BrandingService;
use App\Services\Security\PermissionCatalog;
use Illuminate\Support\Facades\DB;

class SeedBrandingHelper
{
    public static function seedDefaults(Tenant $tenant): void
    {
        PermissionCatalog::syncTenantRolesAndPermissions($tenant->id);

        $presets = app(BrandingService::class)->presets();

        if (! OrganisationSetting::query()->whereNull('tenant_id')->exists()) {
            $defaultPreset = $presets['betterdoc'];
            $hasAnyPublic = OrganisationSetting::query()->where('is_public_default', true)->exists();

            OrganisationSetting::query()->create([
                'tenant_id' => null,
                'is_public_default' => ! $hasAnyPublic,
                'profile_key' => $defaultPreset['profile_key'],
                'name' => $defaultPreset['name'],
                'short_name' => $defaultPreset['short_name'],
                'tagline' => $defaultPreset['tagline'],
                'primary_color' => $defaultPreset['primary_color'],
                'accent_color' => $defaultPreset['accent_color'],
                'welcome_title' => $defaultPreset['welcome_title'],
                'welcome_text' => $defaultPreset['welcome_text'],
                'notice_text' => $defaultPreset['notice_text'],
                'notice_enabled' => $defaultPreset['notice_enabled'],
                'support_email' => $defaultPreset['support_email'],
                'support_phone' => $defaultPreset['support_phone'],
                'support_hours' => $defaultPreset['support_hours'],
                'address' => $defaultPreset['address'],
                'website' => $defaultPreset['website'],
                'show_powered_by' => true,
                'landing_mode' => null,
            ]);
        }

        $key = $tenant->active_profile_code->value;
        $p = $presets[$key] ?? $presets['betterdoc'];

        if (! OrganisationSetting::query()->where('tenant_id', $tenant->id)->exists()) {
            DB::transaction(static function () use ($tenant, $p): void {
                OrganisationSetting::query()->create([
                    'tenant_id' => $tenant->id,
                    'is_public_default' => false,
                    'profile_key' => $p['profile_key'],
                    'name' => $tenant->name,
                    'short_name' => $p['short_name'],
                    'tagline' => $p['tagline'],
                    'primary_color' => $p['primary_color'],
                    'accent_color' => $p['accent_color'],
                    'welcome_title' => $p['welcome_title'],
                    'welcome_text' => $p['welcome_text'],
                    'notice_text' => $p['notice_text'],
                    'notice_enabled' => $p['notice_enabled'],
                    'support_email' => $p['support_email'],
                    'support_phone' => $p['support_phone'],
                    'support_hours' => $p['support_hours'],
                    'address' => $p['address'],
                    'website' => $p['website'],
                    'show_powered_by' => true,
                    'landing_mode' => null,
                ]);
            });
        }
    }
}
