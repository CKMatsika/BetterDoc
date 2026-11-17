<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Enums\HostingMode;
use App\Enums\ProfileCode;
use App\Enums\WriteDownMode;
use App\Models\ClearanceLevel;
use App\Models\Compartment;
use App\Models\OrganizationProfile;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Pennant\Feature;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class ProfileManager
{
    /**
     * Load raw YAML profile pack from config/profiles/{code}.yaml.
     *
     * @return array<string, mixed>
     */
    public function loadPack(ProfileCode $code): array
    {
        $path = rtrim(config()->string('sentinel.profiles_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$code->value.'.yaml';
        if (! File::exists($path)) {
            throw new RuntimeException("Profile pack not found: {$path}");
        }

        /** @var mixed $parsed */
        $parsed = Yaml::parseFile($path);
        if (! is_array($parsed)) {
            throw new RuntimeException("Invalid YAML profile pack: {$path}");
        }

        /** @var array<string, mixed> $result */
        $result = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return list<array{code: string, name: string, hosting_mode_default: string, air_gapped_enforced: bool, write_down_mode: string}>
     */
    public function listAvailablePacks(): array
    {
        $packs = [];
        foreach (ProfileCode::cases() as $code) {
            $data = $this->loadPack($code);
            $packs[] = [
                'code' => $this->strVal($data['code'] ?? null, $code->value),
                'name' => $this->strVal($data['name'] ?? null, $code->label()),
                'hosting_mode_default' => $this->strVal($data['hosting_mode_default'] ?? null, HostingMode::AirGappedOnPrem->value),
                'air_gapped_enforced' => (bool) ($data['air_gapped_enforced'] ?? true),
                'write_down_mode' => $this->strVal($data['write_down_mode'] ?? null, WriteDownMode::Strict->value),
            ];
        }

        return $packs;
    }

    public function applyToTenant(Tenant $tenant, ProfileCode $code, bool $syncTaxonomy = true): OrganizationProfile
    {
        $pack = $this->loadPack($code);

        return DB::transaction(function () use ($tenant, $code, $pack, $syncTaxonomy): OrganizationProfile {
            OrganizationProfile::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $maxVersion = OrganizationProfile::query()
                ->where('tenant_id', $tenant->id)
                ->where('profile_code', $code->value)
                ->max('version');
            $nextVersion = (is_numeric($maxVersion) ? (int) $maxVersion : 0) + 1;

            $profile = OrganizationProfile::query()->create([
                'tenant_id' => $tenant->id,
                'profile_code' => $code,
                'version' => $nextVersion,
                'config_json' => $pack,
                'is_active' => true,
            ]);

            $airGapped = config()->boolean('sentinel.air_gapped', true) || (bool) ($pack['air_gapped_enforced'] ?? false);

            $tenant->update([
                'active_profile_code' => $code,
                'air_gapped' => $airGapped,
                'hosting_mode' => $airGapped
                    ? HostingMode::AirGappedOnPrem
                    : HostingMode::from($this->strVal($pack['hosting_mode_default'] ?? null, HostingMode::LanWithCloudBackup->value)),
            ]);

            if ($syncTaxonomy) {
                $this->syncClearanceLadder($tenant, $pack);
                $this->syncCompartments($tenant, $pack);
            }

            $this->syncFeatures($tenant, $pack, $airGapped);

            return $profile;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function activeConfig(Tenant $tenant): array
    {
        $profile = $tenant->activeProfile;
        if ($profile !== null) {
            return $profile->config_json;
        }

        return $this->loadPack($tenant->active_profile_code);
    }

    public function writeDownMode(Tenant $tenant): WriteDownMode
    {
        $config = $this->activeConfig($tenant);
        $raw = $this->strVal($config['write_down_mode'] ?? null, 'strict');

        return WriteDownMode::tryFrom($raw) ?? WriteDownMode::Strict;
    }

    /**
     * @param  array<string, mixed>  $pack
     */
    private function syncClearanceLadder(Tenant $tenant, array $pack): void
    {
        $ladder = $pack['clearance_ladder'] ?? null;
        if (! is_array($ladder)) {
            return;
        }

        foreach ($ladder as $item) {
            if (! is_array($item)) {
                continue;
            }
            $rank = is_numeric($item['rank'] ?? null) ? (int) $item['rank'] : 0;

            ClearanceLevel::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'rank' => $rank,
                ],
                [
                    'code' => $this->strVal($item['code'] ?? null, 'PUBLIC'),
                    'name' => $this->strVal($item['name'] ?? null, 'Public'),
                    'color_hex' => $this->strVal($item['color_hex'] ?? null, '#475569'),
                    'watermark_mode' => $this->strVal($item['watermark_mode'] ?? null, 'none'),
                    'two_person_required' => (bool) ($item['two_person_required'] ?? false),
                    'secure_print_required' => (bool) ($item['secure_print_required'] ?? false),
                    'cloud_sync_allowed' => (bool) ($item['cloud_sync_allowed'] ?? false),
                    'e2ee_required' => (bool) ($item['e2ee_required'] ?? false),
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $pack
     */
    private function syncCompartments(Tenant $tenant, array $pack): void
    {
        $items = $pack['default_compartments'] ?? null;
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            Compartment::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'code' => $this->strVal($item['code'] ?? null, ''),
                ],
                [
                    'name' => $this->strVal($item['name'] ?? null, ''),
                    'description' => is_string($item['description'] ?? null) ? $item['description'] : null,
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $pack
     */
    private function syncFeatures(Tenant $tenant, array $pack, bool $airGapped): void
    {
        $features = $pack['features'] ?? null;
        if (! is_array($features)) {
            return;
        }

        foreach ($features as $feature => $enabled) {
            if (! is_string($feature)) {
                continue;
            }

            $value = (bool) $enabled;
            if ($airGapped && in_array($feature, ['cloud_sync', 'cloud_ai'], true)) {
                $value = false;
            }

            if ($value) {
                Feature::for($tenant)->activate($feature);
            } else {
                Feature::for($tenant)->deactivate($feature);
            }
        }
    }

    private function strVal(mixed $val, string $default): string
    {
        return is_string($val) || is_int($val) ? (string) $val : $default;
    }
}
