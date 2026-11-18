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
        Schema::table('documents', function (Blueprint $table): void {
            $table->uuid('current_version_id')->nullable()->after('classification_rank');
            $table->text('ocr_text')->nullable()->after('summary');
        });

        Schema::create('document_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('version_major')->default(1);
            $table->unsignedInteger('version_minor')->default(0);
            $table->boolean('is_current')->default(true);
            $table->string('storage_disk', 48)->default('betterdoc_vault');
            $table->string('storage_key', 512);
            $table->string('original_filename');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('file_size_bytes');
            $table->char('sha256_hash', 64);
            $table->text('wrapped_dek');
            $table->string('kek_key_id', 64);
            $table->string('av_status', 24)->default('pending');
            $table->string('cdr_status', 24)->default('pending');
            $table->string('ocr_status', 24)->default('pending');
            $table->boolean('pdfa_compliant')->default(false);
            $table->unsignedInteger('page_count')->default(1);
            $table->text('extracted_text')->nullable();
            $table->text('version_note')->nullable();
            $table->jsonb('cdr_report_json')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'version_major', 'version_minor']);
            $table->index(['tenant_id', 'document_id', 'is_current']);
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->foreign('current_version_id')->references('id')->on('document_versions')->nullOnDelete();
        });

        Schema::create('upload_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('filename');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('total_size');
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->jsonb('uploaded_parts_json');
            $table->char('sha256_expected', 64)->nullable();
            $table->string('storage_temp_prefix', 512);
            $table->string('status', 24)->default('initiated');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'status']);
        });

        Schema::create('document_locks', function (Blueprint $table): void {
            $table->uuid('document_id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('locked_by')->constrained('users')->cascadeOnDelete();
            $table->string('lock_type', 24)->default('checkout');
            $table->text('checkout_note')->nullable();
            $table->timestamp('locked_at');
            $table->timestamp('expires_at');

            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->index(['tenant_id', 'locked_by']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('color_hex', 16)->default('#64748B');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('document_tags', function (Blueprint $table): void {
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->primary(['document_id', 'tag_id']);
        });

        Schema::create('saved_smart_views', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 128);
            $table->jsonb('filter_json');
            $table->boolean('is_shared')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('correspondence_registry', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('direction', 24);
            $table->string('registry_ref_no', 96);
            $table->string('external_party')->nullable();
            $table->string('security_folio_no', 64)->nullable();
            $table->string('physical_file_barcode', 96)->nullable();
            $table->string('dispatch_method', 48)->nullable();
            $table->date('bring_up_date')->nullable();
            $table->foreignUuid('registered_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('registered');
            $table->timestamps();

            $table->unique(['tenant_id', 'registry_ref_no']);
            $table->index(['tenant_id', 'direction', 'bring_up_date']);
        });

        Schema::create('processing_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->char('idempotency_key', 64)->unique();
            $table->string('stage', 48);
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'heartbeat_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement(<<<'SQL'
                ALTER TABLE documents
                ADD COLUMN fts_vector tsvector GENERATED ALWAYS AS (
                    setweight(to_tsvector('english', coalesce(doc_number, '')), 'A') ||
                    setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                    setweight(to_tsvector('english', coalesce(summary, '')), 'B') ||
                    setweight(to_tsvector('english', coalesce(ocr_text, '')), 'C')
                ) STORED
            SQL);
            DB::statement('CREATE INDEX documents_fts_gin_idx ON documents USING GIN (fts_vector)');
            DB::statement('CREATE INDEX documents_title_trgm_idx ON documents USING GIN (title gin_trgm_ops)');
            DB::statement('CREATE INDEX documents_doc_number_trgm_idx ON documents USING GIN (doc_number gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS documents_doc_number_trgm_idx');
            DB::statement('DROP INDEX IF EXISTS documents_title_trgm_idx');
            DB::statement('DROP INDEX IF EXISTS documents_fts_gin_idx');
            DB::statement('ALTER TABLE documents DROP COLUMN IF EXISTS fts_vector');
        }

        Schema::dropIfExists('processing_jobs');
        Schema::dropIfExists('correspondence_registry');
        Schema::dropIfExists('saved_smart_views');
        Schema::dropIfExists('document_tags');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('document_locks');
        Schema::dropIfExists('upload_sessions');

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign(['current_version_id']);
            $table->dropColumn(['current_version_id', 'ocr_text']);
        });

        Schema::dropIfExists('document_versions');
    }
};
