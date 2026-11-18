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
        Schema::create('document_chunks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('version_id')->nullable()->constrained('document_versions')->cascadeOnDelete();
            $table->unsignedSmallInteger('classification_rank')->default(0);
            $table->foreignUuid('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->unsignedInteger('chunk_index')->default(0);
            $table->text('content_text');
            $table->jsonb('embedding_json');
            $table->unsignedSmallInteger('embedding_dims')->default(128);
            $table->unsignedInteger('token_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'document_id', 'chunk_index'], 'doc_chunks_doc_idx');
            $table->index(['tenant_id', 'classification_rank']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE document_chunks
                ADD COLUMN content_tsv tsvector GENERATED ALWAYS AS (
                    to_tsvector('english', coalesce(content_text, ''))
                ) STORED
            SQL);
            DB::statement('CREATE INDEX document_chunks_tsv_gin_idx ON document_chunks USING GIN (content_tsv)');

            $hasVector = DB::selectOne("SELECT 1 FROM pg_available_extensions WHERE name = 'vector'") !== null;
            if ($hasVector) {
                DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
                DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector(1024)');
                DB::statement('CREATE INDEX document_chunks_hnsw_idx ON document_chunks USING hnsw (embedding vector_cosine_ops)');
            }
        }

        Schema::create('idp_extractions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('document_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->string('model_name', 96);
            $table->string('detected_doc_type', 32);
            $table->jsonb('extracted_fields_json');
            $table->decimal('overall_confidence', 4, 3)->default(0);
            $table->string('review_status', 24)->default('pending_review');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'review_status', 'created_at'], 'idp_ext_queue_idx');
            $table->index(['document_id', 'detected_doc_type']);
        });

        Schema::create('ai_chat_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 160);
            $table->unsignedSmallInteger('high_water_rank')->default(0);
            $table->jsonb('high_water_compartments');
            $table->string('model_name', 80);
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'updated_at'], 'ai_chat_sess_user_idx');
        });

        Schema::create('ai_chat_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('session_id')->constrained('ai_chat_sessions')->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('content');
            $table->jsonb('cited_doc_ids')->nullable();
            $table->jsonb('cited_chunks_json')->nullable();
            $table->unsignedSmallInteger('high_water_rank')->default(0);
            $table->jsonb('high_water_compartments')->nullable();
            $table->string('model_used', 80);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamps();

            $table->index(['session_id', 'created_at']);
        });

        Schema::create('ai_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('ai_chat_sessions')->nullOnDelete();
            $table->string('action', 48);
            $table->string('model_used', 80);
            $table->char('prompt_sha256', 64);
            $table->char('output_sha256', 64);
            $table->jsonb('retrieved_doc_ids')->nullable();
            $table->unsignedSmallInteger('high_water_rank')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'created_at'], 'ai_audit_tenant_idx');
        });

        Schema::create('ai_anomaly_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('alert_type', 48);
            $table->string('severity', 16)->default('high');
            $table->string('status', 24)->default('open');
            $table->string('title', 200);
            $table->jsonb('details_json');
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'severity', 'created_at'], 'ai_anomaly_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_anomaly_alerts');
        Schema::dropIfExists('ai_audit_logs');
        Schema::dropIfExists('ai_chat_messages');
        Schema::dropIfExists('ai_chat_sessions');
        Schema::dropIfExists('idp_extractions');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS document_chunks_hnsw_idx');
            DB::statement('DROP INDEX IF EXISTS document_chunks_tsv_gin_idx');
        }

        Schema::dropIfExists('document_chunks');
    }
};
