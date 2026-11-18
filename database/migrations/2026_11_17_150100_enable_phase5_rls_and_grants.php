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

        // Chunks & IDP extractions strictly enforce pre-retrieval MAC/Compartment visibility via parent documents
        foreach (['document_chunks', 'idp_extractions'] as $table) {
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

        // Chat sessions strictly isolated to owning user within tenant
        DB::statement('ALTER TABLE ai_chat_sessions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ai_chat_sessions FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY ai_chat_sessions_owner_isolation ON ai_chat_sessions
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

        // Chat messages inherit parent session ownership
        DB::statement('ALTER TABLE ai_chat_messages ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ai_chat_messages FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY ai_chat_messages_owner_isolation ON ai_chat_messages
            FOR ALL
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM ai_chat_sessions s WHERE s.id = ai_chat_messages.session_id
                )
            )
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
            )
        SQL);

        foreach (['ai_audit_logs', 'ai_anomaly_alerts'] as $table) {
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

        // Metadata-only duplicate invoice detector for security anomaly sweep without relaxing document/IDP RLS
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION betterdoc_scan_duplicate_invoices(p_tenant_id uuid)
            RETURNS TABLE (
                vendor_name text,
                invoice_number text,
                first_document_id uuid,
                duplicate_document_id uuid
            )
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
                WITH inv AS (
                    SELECT
                        document_id,
                        lower(trim(coalesce(extracted_fields_json->'vendor_name'->>'value', 'unknown'))) AS vendor_name,
                        lower(trim(coalesce(extracted_fields_json->'invoice_number'->>'value', ''))) AS invoice_number,
                        created_at,
                        row_number() OVER (
                            PARTITION BY
                                lower(trim(coalesce(extracted_fields_json->'vendor_name'->>'value', 'unknown'))),
                                lower(trim(coalesce(extracted_fields_json->'invoice_number'->>'value', '')))
                            ORDER BY created_at ASC, document_id ASC
                        ) AS rn,
                        first_value(document_id) OVER (
                            PARTITION BY
                                lower(trim(coalesce(extracted_fields_json->'vendor_name'->>'value', 'unknown'))),
                                lower(trim(coalesce(extracted_fields_json->'invoice_number'->>'value', '')))
                            ORDER BY created_at ASC, document_id ASC
                        ) AS first_document_id
                    FROM idp_extractions
                    WHERE tenant_id = p_tenant_id
                      AND detected_doc_type = 'invoice'
                      AND trim(coalesce(extracted_fields_json->'invoice_number'->>'value', '')) <> ''
                )
                SELECT DISTINCT
                    inv.vendor_name,
                    inv.invoice_number,
                    inv.first_document_id,
                    inv.document_id AS duplicate_document_id
                FROM inv
                WHERE inv.rn > 1
                  AND inv.document_id <> inv.first_document_id;
            $$;
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION betterdoc_document_exists(p_tenant_id uuid, p_doc_id uuid)
            RETURNS boolean
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
                SELECT EXISTS (
                    SELECT 1 FROM documents WHERE tenant_id = p_tenant_id AND id = p_doc_id
                );
            $$;
        SQL);

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'betterdoc_app') THEN
                    REVOKE ALL ON FUNCTION betterdoc_scan_duplicate_invoices(uuid) FROM PUBLIC;
                    GRANT EXECUTE ON FUNCTION betterdoc_scan_duplicate_invoices(uuid) TO betterdoc_app;
                    REVOKE ALL ON FUNCTION betterdoc_document_exists(uuid, uuid) FROM PUBLIC;
                    GRANT EXECUTE ON FUNCTION betterdoc_document_exists(uuid, uuid) TO betterdoc_app;
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

        DB::statement('DROP FUNCTION IF EXISTS betterdoc_document_exists(uuid, uuid)');
        DB::statement('DROP FUNCTION IF EXISTS betterdoc_scan_duplicate_invoices(uuid)');

        foreach (['ai_audit_logs', 'ai_anomaly_alerts'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        DB::statement('DROP POLICY IF EXISTS ai_chat_messages_owner_isolation ON ai_chat_messages');
        DB::statement('ALTER TABLE ai_chat_messages DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP POLICY IF EXISTS ai_chat_sessions_owner_isolation ON ai_chat_sessions');
        DB::statement('ALTER TABLE ai_chat_sessions DISABLE ROW LEVEL SECURITY');

        foreach (['document_chunks', 'idp_extractions'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_mac_access ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
