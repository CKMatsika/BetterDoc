<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('driver', 32); // local_mirror | s3_compatible | laravel_cloud | secondary_site
            $table->string('endpoint_url', 255)->nullable();
            $table->string('bucket_or_site', 160);
            $table->smallInteger('max_classification_rank')->default(1);
            $table->string('conflict_strategy', 32)->default('branch_conflict');
            $table->boolean('enabled')->default(true);
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('sync_outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('sync_target_id')->nullable()->constrained('sync_targets')->nullOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->cascadeOnDelete();
            $table->string('event_type', 48);
            $table->smallInteger('classification_rank')->default(0);
            $table->string('payload_sha256', 64);
            $table->text('storage_key')->nullable();
            $table->text('wrapped_dek')->nullable();
            $table->string('kek_key_id', 64)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('conflict_branch_label', 120)->nullable();
            $table->text('conflict_details')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('backup_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('snapshot_label', 160);
            $table->string('backup_tier', 32)->default('tier1_local');
            $table->text('storage_disk');
            $table->text('storage_key');
            $table->string('manifest_sha256', 64);
            $table->string('signature_hmac', 128);
            $table->text('wrapped_dek');
            $table->string('kek_key_id', 64);
            $table->bigInteger('size_bytes')->default(0);
            $table->boolean('includes_wal')->default(true);
            $table->string('status', 32)->default('verified');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('last_restored_at')->nullable();
            $table->integer('restore_duration_ms')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'backup_tier', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_snapshots');
        Schema::dropIfExists('sync_outbox_events');
        Schema::dropIfExists('sync_targets');
    }
};
