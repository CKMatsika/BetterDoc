<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_authorities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('parent_ca_id')->nullable();
            $table->string('name', 160);
            $table->string('role', 24); // root | intermediate | tsa | ocsp
            $table->string('serial_number', 64);
            $table->string('subject_dn', 255);
            $table->string('issuer_dn', 255);
            $table->text('certificate_pem');
            $table->char('fingerprint_sha256', 64);
            $table->string('pkcs11_key_label', 128);
            $table->unsignedBigInteger('crl_number')->default(1);
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'serial_number']);
            $table->index(['tenant_id', 'role']);
        });

        Schema::table('certificate_authorities', function (Blueprint $table): void {
            $table->foreign('parent_ca_id')->references('id')->on('certificate_authorities')->nullOnDelete();
        });

        Schema::create('user_certificates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('ca_id')->constrained('certificate_authorities')->restrictOnDelete();
            $table->string('serial_number', 64);
            $table->string('subject_dn', 255);
            $table->string('issuer_dn', 255);
            $table->text('certificate_pem');
            $table->char('fingerprint_sha256', 64);
            $table->string('custody_mode', 32)->default('hsm_managed');
            $table->string('pkcs11_key_label', 128);
            $table->string('pkcs11_slot_id', 32)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 64)->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('crl_published_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'serial_number']);
            $table->index(['tenant_id', 'user_id', 'status']);
        });

        Schema::create('signature_envelopes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('source_version_id')->constrained('document_versions')->restrictOnDelete();
            $table->foreignUuid('working_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->foreignUuid('sealed_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->string('certificate_storage_key', 512)->nullable();
            $table->text('certificate_wrapped_dek')->nullable();
            $table->string('certificate_kek_key_id', 64)->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('mode', 24)->default('ordered');
            $table->string('status', 24)->default('in_progress');
            $table->string('title', 255);
            $table->text('message')->nullable();
            $table->char('verification_token', 32)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'document_id', 'status']);
        });

        Schema::create('signature_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('envelope_id')->constrained('signature_envelopes')->cascadeOnDelete();
            $table->foreignUuid('signer_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('delegated_from_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sign_order')->default(1);
            $table->string('tier', 24)->default('tier2_pades');
            $table->string('status', 24)->default('pending');
            $table->string('field_name', 64);
            $table->jsonb('field_coords_json');
            $table->string('signature_reason', 255)->nullable();
            $table->string('signature_location', 128)->nullable();
            $table->text('drawn_stamp_svg')->nullable();
            $table->string('typed_name', 128)->nullable();
            $table->foreignUuid('user_certificate_id')->nullable()->constrained('user_certificates')->nullOnDelete();
            $table->string('cert_serial', 64)->nullable();
            $table->string('tsa_token_serial', 64)->nullable();
            $table->timestamp('tsa_timestamp')->nullable();
            $table->char('document_sha256_at_sign', 64)->nullable();
            $table->foreignUuid('signed_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->unsignedBigInteger('audit_event_id')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->unsignedSmallInteger('reminder_count')->default(0);
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->index(['envelope_id', 'sign_order', 'status']);
            $table->index(['tenant_id', 'signer_id', 'status']);
        });

        Schema::create('signature_verifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('document_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->string('overall_status', 24);
            $table->unsignedInteger('signatures_count')->default(0);
            $table->jsonb('details_json');
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->unique('document_version_id');
            $table->index(['tenant_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_verifications');
        Schema::dropIfExists('signature_requests');
        Schema::dropIfExists('signature_envelopes');
        Schema::dropIfExists('user_certificates');
        Schema::dropIfExists('certificate_authorities');
    }
};
