<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProfileCode;
use App\Models\Tenant;
use App\Services\Profile\ProfileManager;
use Illuminate\Console\Command;

class ApplyProfileCommand extends Command
{
    protected $signature = 'sentinel:profile:apply {tenantSlug : Tenant slug} {profileCode : defence|financial|corporate}';

    protected $description = 'Apply an organization configuration profile pack to a tenant.';

    public function handle(ProfileManager $profiles): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenantSlug'))->first();
        if ($tenant === null) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $code = ProfileCode::tryFrom((string) $this->argument('profileCode'));
        if ($code === null) {
            $this->error('Invalid profile code. Use: defence, financial, or corporate.');

            return self::FAILURE;
        }

        $profile = $profiles->applyToTenant($tenant, $code);
        $this->info("Applied profile [{$code->value}] v{$profile->version} to tenant [{$tenant->slug}].");

        return self::SUCCESS;
    }
}
