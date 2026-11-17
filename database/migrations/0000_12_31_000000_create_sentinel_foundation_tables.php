<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS ltree');
            DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        }

        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('hosting_mode', 48)->default('air_gapped_on_prem');
            $table->boolean('air_gapped')->default(true);
            $table->string('active_profile_code', 32)->default('defence');
            $table->timestamps();
        });

        Schema::create('organization_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('profile_code', 32);
            $table->unsignedInteger('version')->default(1);
            $table->jsonb('config_json');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'profile_code', 'version']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('org_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('code', 64);
            $table->string('name');
            $table->string('unit_type', 48)->default('unit');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'parent_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE org_units ADD COLUMN path ltree NOT NULL');
            DB::statement('CREATE INDEX org_units_path_gist_idx ON org_units USING GIST (path)');
        } else {
            Schema::table('org_units', function (Blueprint $table): void {
                $table->string('path')->default('root');
            });
        }

        Schema::table('org_units', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('org_units')->nullOnDelete();
        });

        Schema::create('clearance_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->string('code', 48);
            $table->string('name');
            $table->string('color_hex', 16)->default('#475569');
            $table->string('watermark_mode', 48)->default('none');
            $table->boolean('two_person_required')->default(false);
            $table->boolean('secure_print_required')->default(false);
            $table->boolean('cloud_sync_allowed')->default(false);
            $table->boolean('e2ee_required')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'rank']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('compartments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('owner_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->string('code', 48);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compartments');
        Schema::dropIfExists('clearance_levels');
        Schema::dropIfExists('org_units');
        Schema::dropIfExists('organization_profiles');
        Schema::dropIfExists('tenants');
    }
};
