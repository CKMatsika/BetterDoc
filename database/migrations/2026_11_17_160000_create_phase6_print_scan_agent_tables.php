<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('rfid_badge_uid', 64)->nullable()->after('smartcard_subject_dn');
            $table->index(['tenant_id', 'rfid_badge_uid'], 'users_tenant_rfid_idx');
        });

        Schema::create('cost_centres', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->unsignedInteger('monthly_page_quota')->default(5000);
            $table->unsignedInteger('pages_used_month')->default(0);
            $table->unsignedBigInteger('budget_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('printers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('location', 160)->nullable();
            $table->string('cups_queue_name', 120);
            $table->string('ipps_uri', 255);
            $table->string('driver', 24)->default('local_spool');
            $table->unsignedSmallInteger('max_classification_rank')->default(1);
            $table->boolean('supports_secure_release')->default(true);
            $table->boolean('supports_duplex')->default(true);
            $table->boolean('is_online')->default(true);
            $table->jsonb('capabilities_json')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'cups_queue_name']);
            $table->index(['tenant_id', 'max_classification_rank']);
        });

        Schema::create('print_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('document_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('printer_id')->constrained('printers')->restrictOnDelete();
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('cost_centres')->nullOnDelete();
            $table->string('status', 24)->default('held');
            $table->unsignedSmallInteger('copies')->default(1);
            $table->unsignedInteger('page_count')->default(1);
            $table->string('release_pin_hash')->nullable();
            $table->string('rfid_badge_uid', 64)->nullable();
            $table->unsignedSmallInteger('pin_attempts')->default(0);
            $table->string('watermarked_storage_disk', 48)->nullable();
            $table->string('watermarked_storage_key', 512)->nullable();
            $table->text('watermarked_wrapped_dek')->nullable();
            $table->string('watermarked_kek_key_id', 64)->nullable();
            $table->string('watermark_stamp_text', 255)->nullable();
            $table->unsignedSmallInteger('classification_rank')->default(0);
            $table->string('cups_job_id', 64)->nullable();
            $table->timestamp('held_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'status'], 'print_jobs_user_status_idx');
            $table->index(['tenant_id', 'status', 'held_at'], 'print_jobs_queue_idx');
        });

        Schema::create('scan_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('source_type', 24);
            $table->string('endpoint_or_path', 255);
            $table->foreignUuid('target_cabinet_id')->nullable()->constrained('cabinets')->nullOnDelete();
            $table->foreignUuid('default_classification_level_id')->nullable()->constrained('clearance_levels')->nullOnDelete();
            $table->boolean('auto_ocr')->default(true);
            $table->boolean('auto_split_barcode')->default(true);
            $table->boolean('auto_deskew')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'source_type', 'is_active']);
        });

        Schema::create('scan_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('scan_source_id')->nullable()->constrained('scan_sources')->nullOnDelete();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('cabinet_id')->constrained('cabinets')->restrictOnDelete();
            $table->string('source_channel', 24);
            $table->string('original_filename');
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('split_documents_count')->default(0);
            $table->string('status', 24)->default('queued');
            $table->jsonb('report_json')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at'], 'scan_batches_tenant_idx');
        });

        Schema::create('local_agents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('workstation_name', 120);
            $table->string('agent_version', 32)->default('1.0.0');
            $table->string('pairing_code_hash')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->jsonb('capabilities_json')->nullable();
            $table->string('status', 24)->default('pending_pair');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('paired_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_agents');
        Schema::dropIfExists('scan_batches');
        Schema::dropIfExists('scan_sources');
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('printers');
        Schema::dropIfExists('cost_centres');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_tenant_rfid_idx');
            $table->dropColumn('rfid_badge_uid');
        });
    }
};
