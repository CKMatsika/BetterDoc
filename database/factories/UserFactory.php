<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\ClearanceLevel;
use App\Models\OrgUnit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::query()->value('id'),
            'org_unit_id' => OrgUnit::query()->value('id'),
            'clearance_level_id' => ClearanceLevel::query()->value('id'),
            'clearance_rank' => 0,
            'service_number' => 'SN-'.fake()->unique()->numerify('####'),
            'rank_title' => 'Officer',
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Password!2026'),
            'mfa_enforced' => false,
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }
}
