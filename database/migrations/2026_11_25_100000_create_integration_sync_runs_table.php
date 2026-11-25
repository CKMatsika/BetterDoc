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
        Schema::create('integration_sync_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('integration_connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->foreignUuid('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 48); // test_connection | pull_vendors | pull_purchase_orders | push_invoices
            $table->string('status', 32)->default('running'); // running | completed | failed | blocked_airgap
            $table->unsignedInteger('records_processed')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->jsonb('summary_json')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'integration_connection_id']);
            $table->index(['tenant_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'betterdoc_app') THEN
                        GRANT SELECT, INSERT, UPDATE, DELETE ON integration_sync_runs TO betterdoc_app;
                    END IF;
                END
                $$;
            SQL);

            DB::statement('ALTER TABLE integration_sync_runs ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE integration_sync_runs FORCE ROW LEVEL SECURITY');
            DB::statement(<<<'SQL'
                CREATE POLICY integration_sync_runs_tenant_isolation ON integration_sync_runs
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
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS integration_sync_runs_tenant_isolation ON integration_sync_runs');
            DB::statement('ALTER TABLE integration_sync_runs NO FORCE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE integration_sync_runs DISABLE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('integration_sync_runs');
    }
};
