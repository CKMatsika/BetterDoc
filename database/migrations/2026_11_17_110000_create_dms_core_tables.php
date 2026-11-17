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
        Schema::create('cabinets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('org_unit_id')->constrained('org_units')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('default_classification_rank')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'org_unit_id']);
            $table->index(['tenant_id', 'default_classification_rank']);
        });

        Schema::create('folders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->foreignUuid('org_unit_id')->constrained('org_units')->restrictOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('default_classification_rank')->default(0);
            $table->boolean('inherit_acl')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'cabinet_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE folders ADD COLUMN path ltree NOT NULL');
            DB::statement('CREATE INDEX folders_path_gist_idx ON folders USING GIST (path)');
        } else {
            Schema::table('folders', function (Blueprint $table): void {
                $table->string('path')->default('root');
            });
        }

        Schema::table('folders', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('folders')->nullOnDelete();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('cabinet_id')->constrained('cabinets')->restrictOnDelete();
            $table->foreignUuid('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->foreignUuid('org_unit_id')->constrained('org_units')->restrictOnDelete();
            $table->foreignUuid('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('classification_level_id')->constrained('clearance_levels')->restrictOnDelete();
            $table->unsignedSmallInteger('classification_rank');
            $table->string('doc_number', 96);
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('status', 32)->default('active');
            $table->boolean('unit_wide_readable')->default(true);
            $table->boolean('legal_hold_active')->default(false);
            $table->boolean('is_worm')->default(false);
            $table->boolean('client_e2ee')->default(false);
            $table->foreignUuid('hr_subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('hr_restricted_from_subject')->default(false);
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'doc_number']);
            $table->index(['tenant_id', 'classification_rank', 'folder_id']);
            $table->index(['tenant_id', 'org_unit_id']);
            $table->index(['tenant_id', 'owner_id']);
        });

        Schema::create('document_compartments', function (Blueprint $table): void {
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('compartment_id')->constrained('compartments')->cascadeOnDelete();
            $table->primary(['document_id', 'compartment_id']);
        });

        Schema::create('document_acl_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('principal_type', 24);
            $table->string('principal_id', 64);
            $table->boolean('perm_view')->default(true);
            $table->boolean('perm_download')->default(false);
            $table->boolean('perm_print')->default(false);
            $table->boolean('perm_sign')->default(false);
            $table->boolean('perm_edit')->default(false);
            $table->boolean('perm_share')->default(false);
            $table->foreignUuid('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'principal_type', 'principal_id']);
            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::create('two_person_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('action_type', 48);
            $table->string('target_type', 48);
            $table->uuid('target_id');
            $table->unsignedSmallInteger('target_classification_rank')->default(0);
            $table->jsonb('payload_json');
            $table->text('reason');
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->text('decision_note')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('break_glass_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('first_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('second_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('justification');
            $table->string('status', 32)->default('pending_first');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'document_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_glass_sessions');
        Schema::dropIfExists('two_person_requests');
        Schema::dropIfExists('document_acl_grants');
        Schema::dropIfExists('document_compartments');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('folders');
        Schema::dropIfExists('cabinets');
    }
};
