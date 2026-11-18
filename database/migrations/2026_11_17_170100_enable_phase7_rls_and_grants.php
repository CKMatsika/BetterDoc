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

        $tenantOnlyTables = [
            'vendors',
            'purchase_orders',
            'purchase_order_lines',
            'goods_receipts',
            'goods_receipt_lines',
            'contract_clauses',
            'contract_obligations',
            'compliance_packs',
        ];

        foreach ($tenantOnlyTables as $table) {
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

        // AP Invoices & Contracts enforce parent document MAC clearance & compartment isolation
        foreach (['ap_invoices', 'contracts'] as $table) {
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

        DB::statement('ALTER TABLE ap_invoice_lines ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ap_invoice_lines FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY ap_invoice_lines_mac_access ON ap_invoice_lines
            FOR ALL
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM ap_invoices inv WHERE inv.id = ap_invoice_lines.ap_invoice_id
                )
            )
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
            )
        SQL);

        // HR employee directories visible to employee self OR RecordsOfficer/Approver/SystemAdmin/Auditor roles
        DB::statement('ALTER TABLE hr_employee_files ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE hr_employee_files FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY hr_employee_files_access ON hr_employee_files
            FOR ALL
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND (
                    subject_user_id = NULLIF(current_setting('betterdoc.user_id', true), '')::uuid
                    OR EXISTS (
                        SELECT 1
                        FROM model_has_roles mhr
                        JOIN roles r ON r.id = mhr.role_id
                        WHERE mhr.model_id = NULLIF(current_setting('betterdoc.user_id', true), '')::uuid
                          AND mhr.tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                          AND r.name IN ('SystemAdmin', 'RecordsOfficer', 'Approver', 'Auditor')
                    )
                )
            )
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
            )
        SQL);

        // Tenant-wide duplicate AP invoice check (metadata-only boolean/id check across clearances)
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION betterdoc_find_ap_duplicate_invoice(
                p_tenant_id uuid,
                p_vendor_id uuid,
                p_invoice_number text,
                p_exclude_id uuid
            )
            RETURNS TABLE (id uuid, invoice_number varchar, total_cents bigint)
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
                SELECT id, invoice_number, total_cents
                FROM ap_invoices
                WHERE tenant_id = p_tenant_id
                  AND vendor_id = p_vendor_id
                  AND lower(trim(invoice_number)) = lower(trim(p_invoice_number))
                  AND (p_exclude_id IS NULL OR id <> p_exclude_id)
                LIMIT 1;
            $$;
        SQL);

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'betterdoc_app') THEN
                    REVOKE ALL ON FUNCTION betterdoc_find_ap_duplicate_invoice(uuid, uuid, text, uuid) FROM PUBLIC;
                    GRANT EXECUTE ON FUNCTION betterdoc_find_ap_duplicate_invoice(uuid, uuid, text, uuid) TO betterdoc_app;
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

        DB::statement('DROP FUNCTION IF EXISTS betterdoc_find_ap_duplicate_invoice(uuid, uuid, text, uuid)');

        DB::statement('DROP POLICY IF EXISTS hr_employee_files_access ON hr_employee_files');
        DB::statement('ALTER TABLE hr_employee_files DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP POLICY IF EXISTS ap_invoice_lines_mac_access ON ap_invoice_lines');
        DB::statement('ALTER TABLE ap_invoice_lines DISABLE ROW LEVEL SECURITY');

        foreach (['ap_invoices', 'contracts'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_mac_access ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        $tenantOnlyTables = [
            'vendors',
            'purchase_orders',
            'purchase_order_lines',
            'goods_receipts',
            'goods_receipt_lines',
            'contract_clauses',
            'contract_obligations',
            'compliance_packs',
        ];
        foreach ($tenantOnlyTables as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
