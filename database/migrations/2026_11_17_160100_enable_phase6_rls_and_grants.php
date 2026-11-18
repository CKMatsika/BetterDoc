<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'betterdoc_app') THEN
                    GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO betterdoc_app;
                    GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO betterdoc_app;
                    REVOKE UPDATE, DELETE, TRUNCATE ON audit_events FROM betterdoc_app;
                    GRANT SELECT, INSERT ON audit_events TO betterdoc_app;
                END IF;
            END
            $$;
        SQL);

        foreach (['cost_centres', 'printers', 'scan_sources'] as $table) {
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

        // Print jobs enforce MAC visibility via user_id OR parent document RLS clearance
        DB::statement('ALTER TABLE print_jobs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE print_jobs FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY print_jobs_mac_access ON print_jobs
            FOR ALL
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND (
                    user_id = NULLIF(current_setting('betterdoc.user_id', true), '')::uuid
                    OR EXISTS (
                        SELECT 1 FROM documents d WHERE d.id = print_jobs.document_id
                    )
                )
            )
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
            )
        SQL);

        DB::statement('ALTER TABLE scan_batches ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE scan_batches FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY scan_batches_tenant_isolation ON scan_batches
            FOR ALL
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
            )
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
            )
        SQL);

        DB::statement('ALTER TABLE local_agents ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE local_agents FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY local_agents_owner_isolation ON local_agents
            FOR ALL
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND user_id = NULLIF(current_setting('betterdoc.user_id', true), '')::uuid
            )
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND user_id = NULLIF(current_setting('betterdoc.user_id', true), '')::uuid
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION betterdoc_lookup_local_agent_by_token(p_token_hash char(64))
            RETURNS TABLE (id uuid, tenant_id uuid, user_id uuid, status varchar)
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
                SELECT id, tenant_id, user_id, status
                FROM local_agents
                WHERE token_hash = p_token_hash
                LIMIT 1;
            $$;
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION betterdoc_lookup_local_agent_by_id(p_agent_id uuid)
            RETURNS TABLE (id uuid, tenant_id uuid, user_id uuid, pairing_code_hash varchar, status varchar)
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
                SELECT id, tenant_id, user_id, pairing_code_hash, status
                FROM local_agents
                WHERE id = p_agent_id
                LIMIT 1;
            $$;
        SQL);

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'betterdoc_app') THEN
                    REVOKE ALL ON FUNCTION betterdoc_lookup_local_agent_by_token(char) FROM PUBLIC;
                    GRANT EXECUTE ON FUNCTION betterdoc_lookup_local_agent_by_token(char) TO betterdoc_app;
                    REVOKE ALL ON FUNCTION betterdoc_lookup_local_agent_by_id(uuid) FROM PUBLIC;
                    GRANT EXECUTE ON FUNCTION betterdoc_lookup_local_agent_by_id(uuid) TO betterdoc_app;
                END IF;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP FUNCTION IF EXISTS betterdoc_lookup_local_agent_by_id(uuid)');
        DB::statement('DROP FUNCTION IF EXISTS betterdoc_lookup_local_agent_by_token(char)');

        DB::statement('DROP POLICY IF EXISTS local_agents_owner_isolation ON local_agents');
        DB::statement('ALTER TABLE local_agents DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP POLICY IF EXISTS scan_batches_tenant_isolation ON scan_batches');
        DB::statement('ALTER TABLE scan_batches DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP POLICY IF EXISTS print_jobs_mac_access ON print_jobs');
        DB::statement('ALTER TABLE print_jobs DISABLE ROW LEVEL SECURITY');

        foreach (['cost_centres', 'printers', 'scan_sources'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
