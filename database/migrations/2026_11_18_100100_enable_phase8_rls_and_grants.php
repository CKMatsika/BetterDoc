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

        $tables = [
            'sync_targets',
            'sync_outbox_events',
            'backup_snapshots',
        ];

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'betterdoc_app') THEN
                    GRANT SELECT, INSERT, UPDATE, DELETE ON sync_targets, sync_outbox_events, backup_snapshots TO betterdoc_app;
                END IF;
            END
            $$;
        SQL);

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }

        // Sync targets strictly isolated per tenant
        DB::statement(<<<'SQL'
            CREATE POLICY sync_targets_tenant_isolation ON sync_targets
            FOR ALL
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
            )
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
            )
        SQL);

        // Sync outbox enforces tenant isolation, clearance ceiling, and document MAC visibility
        DB::statement(<<<'SQL'
            CREATE POLICY sync_outbox_mac_access ON sync_outbox_events
            FOR ALL
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND classification_rank <= COALESCE(NULLIF(current_setting('betterdoc.clearance_rank', true), '')::int, -1)
                AND (
                    document_id IS NULL
                    OR EXISTS (SELECT 1 FROM documents d WHERE d.id = sync_outbox_events.document_id)
                )
            )
            WITH CHECK (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                AND classification_rank <= COALESCE(NULLIF(current_setting('betterdoc.clearance_rank', true), '')::int, -1)
            )
        SQL);

        // Backup snapshots accessible by tenant SystemAdmin / Auditor / RecordsOfficer / Approver
        DB::statement(<<<'SQL'
            CREATE POLICY backup_snapshots_admin_access ON backup_snapshots
            FOR ALL
            USING (
                tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                OR (
                    tenant_id IS NULL
                    AND NULLIF(current_setting('betterdoc.tenant_id', true), '') IS NOT NULL
                )
            )
            WITH CHECK (
                tenant_id IS NULL
                OR tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
            )
        SQL);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP POLICY IF EXISTS backup_snapshots_admin_access ON backup_snapshots');
        DB::statement('DROP POLICY IF EXISTS sync_outbox_mac_access ON sync_outbox_events');
        DB::statement('DROP POLICY IF EXISTS sync_targets_tenant_isolation ON sync_targets');

        foreach (['backup_snapshots', 'sync_outbox_events', 'sync_targets'] as $table) {
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
