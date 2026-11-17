<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->jsonb('mentioned_user_ids_json')->nullable();
            $table->jsonb('suppressed_mention_ids_json')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'document_id', 'created_at']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::table('document_comments', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('document_comments')->cascadeOnDelete();
        });

        Schema::create('document_annotations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->string('kind', 24);
            $table->jsonb('coords_json');
            $table->text('content')->nullable();
            $table->foreignUuid('redacted_in_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'document_id', 'version_id', 'page_number'], 'doc_annot_doc_ver_page_idx');
        });

        Schema::table('document_locks', function (Blueprint $table): void {
            $table->string('wopi_lock_id', 128)->nullable()->after('lock_type');
            $table->uuid('wopi_session_id')->nullable()->after('wopi_lock_id');
        });

        Schema::create('wopi_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('wopi_lock_id', 128)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->boolean('can_write')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'document_id', 'revoked_at'], 'wopi_tok_user_doc_rev_idx');
        });

        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('title', 255);
            $table->text('body');
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('action_url', 255)->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'read_at', 'created_at'], 'user_notif_inbox_idx');
        });

        Schema::create('workflow_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->jsonb('bpmn_graph_json');
            $table->jsonb('form_schema_json')->nullable();
            $table->unsignedInteger('sla_hours')->default(48);
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'key', 'version']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('workflow_instances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('definition_id')->constrained('workflow_definitions')->restrictOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('started_by')->constrained('users')->restrictOnDelete();
            $table->jsonb('current_nodes_json');
            $table->jsonb('variables_json')->nullable();
            $table->string('status', 24)->default('running');
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'document_id', 'status']);
        });

        Schema::create('workflow_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->string('node_label', 160);
            $table->string('node_type', 32)->default('approval');
            $table->foreignUuid('assignee_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('delegated_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('decision', 24)->nullable();
            $table->text('decision_comment')->nullable();
            $table->jsonb('form_data_json')->nullable();
            $table->char('approval_hash', 64)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'assignee_id', 'status', 'due_at'], 'wf_tasks_assignee_idx');
            $table->index(['instance_id', 'node_id', 'status']);
        });

        Schema::create('document_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->string('category', 32);
            $table->text('body_html');
            $table->jsonb('placeholders_json');
            $table->foreignUuid('default_classification_level_id')->nullable()->constrained('clearance_levels')->nullOnDelete();
            $table->string('auto_number_prefix', 32);
            $table->unsignedInteger('next_sequence')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('retention_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->string('trigger_event', 32)->default('created_at');
            $table->unsignedInteger('retention_days');
            $table->string('disposition_action', 32)->default('archive');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignUuid('retention_schedule_id')->nullable()->after('current_version_id')->constrained('retention_schedules')->nullOnDelete();
            $table->timestamp('retention_expires_at')->nullable()->after('retention_schedule_id');
            $table->text('legal_hold_reason')->nullable()->after('legal_hold_active');
            $table->timestamp('worm_locked_at')->nullable()->after('is_worm');
            $table->timestamp('worm_retention_until')->nullable()->after('worm_locked_at');
        });

        Schema::create('disposition_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('retention_schedule_id')->constrained('retention_schedules')->restrictOnDelete();
            $table->foreignUuid('initiated_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('two_person_request_id')->nullable()->constrained('two_person_requests')->nullOnDelete();
            $table->string('proposed_action', 32);
            $table->string('status', 24)->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disposition_reviews');

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign(['retention_schedule_id']);
            $table->dropColumn([
                'retention_schedule_id',
                'retention_expires_at',
                'legal_hold_reason',
                'worm_locked_at',
                'worm_retention_until',
            ]);
        });

        Schema::dropIfExists('retention_schedules');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('workflow_tasks');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_definitions');
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('wopi_tokens');

        Schema::table('document_locks', function (Blueprint $table): void {
            $table->dropColumn(['wopi_lock_id', 'wopi_session_id']);
        });

        Schema::dropIfExists('document_annotations');
        Schema::dropIfExists('document_comments');
    }
};
