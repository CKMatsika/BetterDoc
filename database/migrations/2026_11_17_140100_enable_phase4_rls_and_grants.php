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

        // Document-bound collaboration & governance tables inherit document MAC/compartment visibility
        foreach (['document_comments', 'document_annotations', 'workflow_instances', 'disposition_reviews'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$table}_mac_access ON {$table}
                FOR ALL
                USING (
                    tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                    AND EXISTS (
                        SELECT 1 FROM documents d WHERE d.id = {$table}.document_id
                    )
                )
                WITH CHECK (
                    tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                )
            SQL);
        }

        // Workflow tasks inherit visibility through parent workflow_instance -> document
        DB::statement('ALTER TABLE workflow_tasks ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE workflow_tasks FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY workflow_tasks_mac_access ON workflow_tasks
            FOR ALL
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM workflow_instances wi WHERE wi.id = workflow_tasks.instance_id
                )
            )
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
            )
        SQL);

        // User notifications strictly isolated to owning user within tenant (split INSERT so authorized actors can notify recipients cleanly)
        DB::statement('ALTER TABLE user_notifications ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE user_notifications FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY user_notifications_owner_select ON user_notifications
            FOR SELECT
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND user_id = NULLIF(current_setting('betterdoc.user_id', true), '')::uuid
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY user_notifications_owner_update ON user_notifications
            FOR UPDATE
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
            CREATE POLICY user_notifications_owner_delete ON user_notifications
            FOR DELETE
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND user_id = NULLIF(current_setting('betterdoc.user_id', true), '')::uuid
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY user_notifications_tenant_insert ON user_notifications
            FOR INSERT
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
            )
        SQL);

        // Tenant-scoped definitions, templates, retention schedules, and WOPI tokens
        foreach (['workflow_definitions', 'document_templates', 'retention_schedules', 'wopi_tokens'] as $table) {
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

        // SECURITY DEFINER lookup for unauthenticated Collabora WOPI server callbacks by hashed token
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION betterdoc_wopi_token_lookup(p_token_hash char(64))
            RETURNS jsonb
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                v_row RECORD;
            BEGIN
                SELECT id, tenant_id, document_id, user_id, wopi_lock_id, ip_address, user_agent_hash, can_write, expires_at, revoked_at
                INTO v_row
                FROM wopi_tokens
                WHERE token_hash = p_token_hash
                LIMIT 1;

                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                RETURN jsonb_build_object(
                    'id', v_row.id,
                    'tenant_id', v_row.tenant_id,
                    'document_id', v_row.document_id,
                    'user_id', v_row.user_id,
                    'wopi_lock_id', v_row.wopi_lock_id,
                    'ip_address', v_row.ip_address,
                    'user_agent_hash', v_row.user_agent_hash,
                    'can_write', v_row.can_write,
                    'expires_at', v_row.expires_at,
                    'revoked_at', v_row.revoked_at
                );
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP FUNCTION IF EXISTS betterdoc_wopi_token_lookup(char)');

        foreach (['workflow_definitions', 'document_templates', 'retention_schedules', 'wopi_tokens'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        foreach (['user_notifications_owner_select', 'user_notifications_owner_update', 'user_notifications_owner_delete', 'user_notifications_tenant_insert'] as $pol) {
            DB::statement("DROP POLICY IF EXISTS {$pol} ON user_notifications");
        }
        DB::statement('ALTER TABLE user_notifications DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP POLICY IF EXISTS workflow_tasks_mac_access ON workflow_tasks');
        DB::statement('ALTER TABLE workflow_tasks DISABLE ROW LEVEL SECURITY');

        foreach (['document_comments', 'document_annotations', 'workflow_instances', 'disposition_reviews'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_mac_access ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
