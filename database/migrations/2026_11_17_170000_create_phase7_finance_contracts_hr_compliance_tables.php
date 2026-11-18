<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 160);
            $table->string('tax_id_tin', 48)->nullable();
            $table->string('vat_number', 48)->nullable();
            $table->string('currency', 8)->default('USD');
            $table->string('peppol_endpoint_id', 120)->nullable();
            $table->string('email', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('po_number', 64);
            $table->string('status', 24)->default('open');
            $table->string('currency', 8)->default('USD');
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->date('issued_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'po_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('sku', 64);
            $table->string('description', 200);
            $table->decimal('qty_ordered', 12, 3);
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedInteger('tax_rate_bps')->default(1500);
            $table->timestamps();

            $table->unique(['purchase_order_id', 'line_number']);
        });

        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignUuid('received_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('grn_number', 64);
            $table->timestamp('received_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'grn_number']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->decimal('qty_received', 12, 3);
            $table->timestamps();
        });

        Schema::create('ap_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('idp_extraction_id')->nullable()->constrained('idp_extractions')->nullOnDelete();
            $table->string('invoice_number', 64);
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('currency', 8)->default('USD');
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('vat_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->string('status', 28)->default('pending_match');
            $table->string('match_mode', 12)->default('3_way');
            $table->jsonb('match_report_json')->nullable();
            $table->string('zimra_fiscal_status', 24)->default('pending');
            $table->string('zimra_receipt_no', 80)->nullable();
            $table->text('zimra_qr_payload')->nullable();
            $table->unsignedSmallInteger('zimra_attempts')->default(0);
            $table->char('ubl_xml_hash', 64)->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'vendor_id', 'invoice_number'], 'ap_invoices_dup_idx');
            $table->index(['tenant_id', 'status', 'zimra_fiscal_status'], 'ap_invoices_status_idx');
        });

        Schema::create('ap_invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('ap_invoice_id')->constrained('ap_invoices')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('sku', 64);
            $table->string('description', 200);
            $table->decimal('qty_invoiced', 12, 3);
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedBigInteger('line_total_cents');
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('contract_number', 64);
            $table->string('title', 200);
            $table->string('counterparty_name', 160);
            $table->date('effective_date');
            $table->date('expiry_date');
            $table->unsignedSmallInteger('renewal_notice_days')->default(30);
            $table->unsignedBigInteger('value_cents')->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('status', 24)->default('active');
            $table->boolean('auto_renew')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'contract_number']);
            $table->index(['tenant_id', 'status', 'expiry_date'], 'contracts_expiry_idx');
        });

        Schema::create('contract_clauses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contract_id')->nullable()->constrained('contracts')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('category', 48);
            $table->text('clause_text');
            $table->boolean('is_standard_template')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'category']);
        });

        Schema::create('contract_obligations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->date('due_date');
            $table->string('status', 24)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'due_date']);
        });

        Schema::create('hr_employee_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('subject_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('employee_number', 48);
            $table->string('department_name', 120);
            $table->string('job_title', 120);
            $table->foreignUuid('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->date('hire_date')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'subject_user_id']);
            $table->unique(['tenant_id', 'employee_number']);
        });

        Schema::create('compliance_packs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('generated_by')->constrained('users')->restrictOnDelete();
            $table->string('pack_name', 180);
            $table->string('regulation_standard', 48);
            $table->unsignedInteger('event_count')->default(0);
            $table->char('head_hash', 64);
            $table->foreignUuid('checkpoint_id')->nullable()->constrained('audit_checkpoints')->nullOnDelete();
            $table->string('storage_disk', 48)->default('betterdoc_vault');
            $table->string('storage_key', 512);
            $table->text('wrapped_dek');
            $table->string('kek_key_id', 64);
            $table->char('manifest_sha256', 64);
            $table->char('signature_hmac', 64);
            $table->timestamps();

            $table->index(['tenant_id', 'regulation_standard', 'created_at'], 'compliance_packs_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_packs');
        Schema::dropIfExists('hr_employee_files');
        Schema::dropIfExists('contract_obligations');
        Schema::dropIfExists('contract_clauses');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('ap_invoice_lines');
        Schema::dropIfExists('ap_invoices');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('vendors');
    }
};
