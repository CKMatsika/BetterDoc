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

        // document_versions inherits document visibility via documents RLS (non-recursive)
        DB::statement('ALTER TABLE document_versions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_versions FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY document_versions_mac_access ON document_versions
            FOR ALL
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM documents d WHERE d.id = document_versions.document_id
                )
            )
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
            )
        SQL);

        // correspondence_registry inherits linked document MAC/compartment visibility
        DB::statement('ALTER TABLE correspondence_registry ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE correspondence_registry FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY correspondence_registry_mac_access ON correspondence_registry
            FOR ALL
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM documents d WHERE d.id = correspondence_registry.document_id
                )
            )
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
            )
        SQL);

        // upload_sessions restricted strictly to the owning user
        DB::statement('ALTER TABLE upload_sessions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE upload_sessions FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY upload_sessions_owner_isolation ON upload_sessions
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

        // saved_smart_views restricted to owner or tenant shared views
        DB::statement('ALTER TABLE saved_smart_views ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE saved_smart_views FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY saved_smart_views_isolation ON saved_smart_views
            FOR ALL
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND (
                    is_shared = true
                    OR user_id = NULLIF(current_setting('betterdoc.user_id', true), '')::uuid
                )
            )
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND user_id = NULLIF(current_setting('betterdoc.user_id', true), '')::uuid
            )
        SQL);

        foreach (['document_locks', 'processing_jobs'] as $table) {
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
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['document_locks', 'processing_jobs'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        DB::statement('DROP POLICY IF EXISTS saved_smart_views_isolation ON saved_smart_views');
        DB::statement('ALTER TABLE saved_smart_views DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP POLICY IF EXISTS upload_sessions_owner_isolation ON upload_sessions');
        DB::statement('ALTER TABLE upload_sessions DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP POLICY IF EXISTS correspondence_registry_mac_access ON correspondence_registry');
        DB::statement('ALTER TABLE correspondence_registry DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP POLICY IF EXISTS document_versions_mac_access ON document_versions');
        DB::statement('ALTER TABLE document_versions DISABLE ROW LEVEL SECURITY');
    }
};
