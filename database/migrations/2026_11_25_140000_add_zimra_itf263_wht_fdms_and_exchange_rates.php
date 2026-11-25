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
        Schema::table('vendors', function (Blueprint $table): void {
            $table->string('itf263_certificate_no', 64)->nullable()->after('vat_number');
            $table->date('itf263_expires_at')->nullable()->after('itf263_certificate_no');
            $table->uuid('itf263_document_id')->nullable()->after('itf263_expires_at');
            $table->string('praz_registration_no', 64)->nullable()->after('itf263_document_id');
            $table->date('praz_expires_at')->nullable()->after('praz_registration_no');
            $table->string('nssa_clearance_no', 64)->nullable()->after('praz_expires_at');
            $table->date('nssa_expires_at')->nullable()->after('nssa_clearance_no');
            $table->jsonb('compliance_docs_json')->nullable()->after('nssa_expires_at');

            $table->foreign('itf263_document_id')->references('id')->on('documents')->nullOnDelete();
        });

        Schema::table('ap_invoices', function (Blueprint $table): void {
            $table->boolean('wht_applicable')->default(false)->after('total_cents');
            $table->unsignedInteger('wht_rate_bps')->default(0)->after('wht_applicable');
            $table->bigInteger('wht_cents')->default(0)->after('wht_rate_bps');
            $table->bigInteger('net_payable_cents')->default(0)->after('wht_cents');
            $table->decimal('exchange_rate_to_usd', 14, 6)->default(1.000000)->after('net_payable_cents');
            $table->bigInteger('usd_equivalent_cents')->default(0)->after('exchange_rate_to_usd');
            $table->boolean('fdms_qr_verified')->default(false)->after('zimra_qr_payload');
            $table->jsonb('fdms_verification_json')->nullable()->after('fdms_qr_verified');
        });

        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('base_currency', 8)->default('USD');
            $table->string('quote_currency', 8);
            $table->decimal('rate', 14, 6);
            $table->date('effective_date');
            $table->string('source', 64)->default('RBZ Interbank');
            $table->uuid('recorded_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'base_currency', 'quote_currency', 'effective_date'], 'exchange_rates_tenant_pair_date_unique');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'betterdoc_app') THEN
                        GRANT SELECT, INSERT, UPDATE, DELETE ON exchange_rates TO betterdoc_app;
                    END IF;
                END
                $$;
            SQL);

            DB::statement('ALTER TABLE exchange_rates ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE exchange_rates FORCE ROW LEVEL SECURITY');
            DB::statement(<<<'SQL'
                CREATE POLICY exchange_rates_tenant_isolation ON exchange_rates
                FOR ALL
                USING (
                    tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                )
                WITH CHECK (
                    tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                )
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS exchange_rates_tenant_isolation ON exchange_rates');
        }

        Schema::dropIfExists('exchange_rates');

        Schema::table('ap_invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'wht_applicable',
                'wht_rate_bps',
                'wht_cents',
                'net_payable_cents',
                'exchange_rate_to_usd',
                'usd_equivalent_cents',
                'fdms_qr_verified',
                'fdms_verification_json',
            ]);
        });

        Schema::table('vendors', function (Blueprint $table): void {
            $table->dropForeign(['itf263_document_id']);
            $table->dropColumn([
                'itf263_certificate_no',
                'itf263_expires_at',
                'itf263_document_id',
                'praz_registration_no',
                'praz_expires_at',
                'nssa_clearance_no',
                'nssa_expires_at',
                'compliance_docs_json',
            ]);
        });
    }
};
