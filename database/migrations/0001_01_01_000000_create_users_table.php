<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('org_unit_id')->constrained('org_units')->restrictOnDelete();
            $table->foreignUuid('clearance_level_id')->constrained('clearance_levels')->restrictOnDelete();
            $table->unsignedSmallInteger('clearance_rank')->default(0);
            $table->string('service_number', 64)->nullable();
            $table->string('rank_title', 64)->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('mfa_enforced')->default(true);
            $table->string('smartcard_subject_dn')->nullable();
            $table->string('status', 32)->default('active');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->jsonb('allowed_cidrs')->nullable();
            $table->jsonb('allowed_hours')->nullable();
            $table->string('auth_source', 24)->default('local');
            $table->timestamp('password_changed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['tenant_id', 'org_unit_id']);
            $table->index(['tenant_id', 'clearance_rank']);
            $table->unique(['tenant_id', 'service_number']);
        });

        Schema::create('user_compartments', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('compartment_id')->constrained('compartments')->cascadeOnDelete();
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->primary(['user_id', 'compartment_id']);
            $table->index('expires_at');
        });

        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_id')->unique();
            $table->string('ip_address', 45);
            $table->string('user_agent_hash', 64);
            $table->string('device_label')->nullable();
            $table->timestamp('mfa_verified_at')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('user_compartments');
        Schema::dropIfExists('users');
    }
};
