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

        // Grant least-privilege runtime permissions to betterdoc_app when present
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

        // 1. Documents RLS (MAC + Compartment Subset + Ownership/Unit/ACL + HR Subject Shield + Break-Glass)
        DB::statement('ALTER TABLE documents ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE documents FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY documents_tenant_mac_select ON documents
            FOR SELECT
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND deleted_at IS NULL
                AND (
                    id = ANY(
                        COALESCE(
                            string_to_array(NULLIF(current_setting('betterdoc.break_glass_doc_ids', true), ''), ',')::uuid[],
                            ARRAY[]::uuid[]
                        )
                    )
                    OR (
                        classification_rank <= COALESCE(NULLIF(current_setting('betterdoc.clearance_rank', true), '')::int, -1)
                        AND NOT EXISTS (
                            SELECT 1 FROM document_compartments dc
                            WHERE dc.document_id = documents.id
                              AND dc.compartment_id <> ALL(
                                  COALESCE(
                                      string_to_array(NULLIF(current_setting('betterdoc.compartment_ids', true), ''), ',')::uuid[],
                                      ARRAY[]::uuid[]
                                  )
                              )
                        )
                        AND NOT (
                            hr_restricted_from_subject = true
                            AND hr_subject_user_id = NULLIF(current_setting('betterdoc.user_id', true), '')::uuid
                        )
                        AND (
                            owner_id = NULLIF(current_setting('betterdoc.user_id', true), '')::uuid
                            OR EXISTS (
                                SELECT 1 FROM document_acl_grants g
                                WHERE g.document_id = documents.id
                                  AND g.tenant_id = documents.tenant_id
                                  AND g.perm_view = true
                                  AND (g.expires_at IS NULL OR g.expires_at > now())
                                  AND (
                                      (g.principal_type = 'user' AND g.principal_id = NULLIF(current_setting('betterdoc.user_id', true), ''))
                                      OR (g.principal_type = 'org_unit' AND g.principal_id = ANY(
                                          COALESCE(string_to_array(NULLIF(current_setting('betterdoc.ancestor_unit_ids', true), ''), ','), ARRAY[]::text[])
                                      ))
                                      OR (g.principal_type = 'role' AND g.principal_id = ANY(
                                          COALESCE(string_to_array(NULLIF(current_setting('betterdoc.role_ids', true), ''), ','), ARRAY[]::text[])
                                      ))
                                  )
                            )
                            OR (
                                unit_wide_readable = true
                                AND NULLIF(current_setting('betterdoc.org_unit_path', true), '') IS NOT NULL
                                AND EXISTS (
                                    SELECT 1 FROM org_units ou
                                    WHERE ou.id = documents.org_unit_id
                                      AND ou.path <@ NULLIF(current_setting('betterdoc.org_unit_path', true), '')::ltree
                                )
                            )
                        )
                    )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY documents_tenant_mac_insert ON documents
            FOR INSERT
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND owner_id = NULLIF(current_setting('betterdoc.user_id', true), '')::uuid
                AND classification_rank <= COALESCE(NULLIF(current_setting('betterdoc.clearance_rank', true), '')::int, -1)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY documents_tenant_mac_update ON documents
            FOR UPDATE
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND classification_rank <= COALESCE(NULLIF(current_setting('betterdoc.clearance_rank', true), '')::int, -1)
                AND NOT EXISTS (
                    SELECT 1 FROM document_compartments dc
                    WHERE dc.document_id = documents.id
                      AND dc.compartment_id <> ALL(
                          COALESCE(
                              string_to_array(NULLIF(current_setting('betterdoc.compartment_ids', true), ''), ',')::uuid[],
                              ARRAY[]::uuid[]
                          )
                      )
                )
            )
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY documents_tenant_mac_delete ON documents
            FOR DELETE
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND classification_rank <= COALESCE(NULLIF(current_setting('betterdoc.clearance_rank', true), '')::int, -1)
            )
        SQL);

        // 2. Cabinets & Folders RLS (Tenant + Classification floor)
        foreach (['cabinets', 'folders'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_mac_all ON {$table}
                FOR ALL
                USING (
                    tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                    AND default_classification_rank <= COALESCE(NULLIF(current_setting('betterdoc.clearance_rank', true), '')::int, -1)
                )
                WITH CHECK (
                    tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                    AND default_classification_rank <= COALESCE(NULLIF(current_setting('betterdoc.clearance_rank', true), '')::int, -1)
                )
            SQL);
        }

        // 3. Tenant-isolated RLS for ACL grants, Two-Person, Break-Glass (no recursive query back to documents!)
        foreach (['document_acl_grants', 'two_person_requests', 'break_glass_sessions'] as $table) {
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

        foreach (['break_glass_sessions', 'two_person_requests', 'document_acl_grants'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        foreach (['folders', 'cabinets'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_mac_all ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        foreach (['documents_tenant_mac_delete', 'documents_tenant_mac_update', 'documents_tenant_mac_insert', 'documents_tenant_mac_select'] as $policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy} ON documents");
        }
        DB::statement('ALTER TABLE documents DISABLE ROW LEVEL SECURITY');
    }
};
