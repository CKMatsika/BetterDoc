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
        Schema::create('erp_bridge_agents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('integration_connection_id')->nullable()->constrained('integration_connections')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('erp_target', 48); // sage_pastel_evolution | sage_pastel_partner | belina_payroll | custom_lan_erp
            $table->string('hostname', 160)->nullable();
            $table->string('agent_version', 32)->nullable();
            $table->string('status', 32)->default('pending_pairing'); // pending_pairing | online | offline | revoked
            $table->string('pairing_code_hash', 64)->nullable()->index();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->string('api_token_hash', 64)->nullable()->unique();
            $table->jsonb('capabilities_json');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('erp_bridge_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('erp_bridge_agent_id')->constrained('erp_bridge_agents')->cascadeOnDelete();
            $table->foreignUuid('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('job_type', 48); // health_check | pull_vendors | pull_purchase_orders | push_invoices | pull_employees
            $table->string('status', 32)->default('queued'); // queued | claimed | completed | failed
            $table->jsonb('payload_json')->nullable();
            $table->jsonb('result_json')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'erp_bridge_agent_id', 'status'], 'erp_bridge_jobs_poll_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'betterdoc_app') THEN
                        GRANT SELECT, INSERT, UPDATE, DELETE ON erp_bridge_agents, erp_bridge_jobs TO betterdoc_app;
                    END IF;
                END
                $$;
            SQL);

            foreach (['erp_bridge_agents', 'erp_bridge_jobs'] as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement(<<<SQL
                    CREATE POLICY {$table}_tenant_isolation ON {$table}
                    FOR ALL
                    USING (
                        tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                    )
                    WITH CHECK (
                        tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                    )
                SQL);
            }

            // SECURITY DEFINER lookup allows unauthenticated bridge pair/poll endpoints to resolve exact (agent_id, tenant_id)
            // without relaxing FORCE ROW LEVEL SECURITY on erp_bridge_agents.
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION resolve_erp_bridge_agent(p_pairing_hash TEXT, p_token_hash TEXT)
                RETURNS TABLE (agent_id UUID, tenant_id UUID)
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = public
                AS $$
                BEGIN
                    IF p_pairing_hash IS NOT NULL AND p_pairing_hash <> '' THEN
                        RETURN QUERY
                        SELECT a.id, a.tenant_id
                        FROM erp_bridge_agents a
                        WHERE a.pairing_code_hash = p_pairing_hash
                          AND a.status = 'pending_pairing'
                          AND (a.pairing_expires_at IS NULL OR a.pairing_expires_at > NOW())
                        LIMIT 1;
                    ELSIF p_token_hash IS NOT NULL AND p_token_hash <> '' THEN
                        RETURN QUERY
                        SELECT a.id, a.tenant_id
                        FROM erp_bridge_agents a
                        WHERE a.api_token_hash = p_token_hash
                          AND a.status <> 'revoked'
                        LIMIT 1;
                    END IF;
                END;
                $$;
            SQL);

            DB::statement(<<<'SQL'
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'betterdoc_app') THEN
                        GRANT EXECUTE ON FUNCTION resolve_erp_bridge_agent(TEXT, TEXT) TO betterdoc_app;
                    END IF;
                END
                $$;
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS resolve_erp_bridge_agent(TEXT, TEXT)');
            foreach (['erp_bridge_jobs', 'erp_bridge_agents'] as $table) {
                DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
                DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            }
        }

        Schema::dropIfExists('erp_bridge_jobs');
        Schema::dropIfExists('erp_bridge_agents');
    }
};
