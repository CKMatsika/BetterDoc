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
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'sentinel_app') THEN
                    GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO sentinel_app;
                    GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO sentinel_app;
                    REVOKE UPDATE, DELETE, TRUNCATE ON audit_events FROM sentinel_app;
                    GRANT SELECT, INSERT ON audit_events TO sentinel_app;
                END IF;
            END
            $$;
        SQL);

        foreach (['certificate_authorities', 'user_certificates'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                FOR ALL
                USING (
                    tenant_id = NULLIF(current_setting('sentinel.tenant_id', true), '')::uuid
                )
                WITH CHECK (
                    tenant_id = NULLIF(current_setting('sentinel.tenant_id', true), '')::uuid
                )
            SQL);
        }

        foreach (['signature_envelopes', 'signature_verifications'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$table}_mac_isolation ON {$table}
                FOR ALL
                USING (
                    tenant_id = NULLIF(current_setting('sentinel.tenant_id', true), '')::uuid
                    AND EXISTS (
                        SELECT 1 FROM documents d WHERE d.id = {$table}.document_id
                    )
                )
                WITH CHECK (
                    tenant_id = NULLIF(current_setting('sentinel.tenant_id', true), '')::uuid
                )
            SQL);
        }

        DB::statement('ALTER TABLE signature_requests ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE signature_requests FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY signature_requests_envelope_mac ON signature_requests
            FOR ALL
            USING (
                tenant_id = NULLIF(current_setting('sentinel.tenant_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM signature_envelopes se WHERE se.id = signature_requests.envelope_id
                )
            )
            WITH CHECK (
                tenant_id = NULLIF(current_setting('sentinel.tenant_id', true), '')::uuid
            )
        SQL);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'certificate_authorities',
            'user_certificates',
            'signature_envelopes',
            'signature_requests',
            'signature_verifications',
        ] as $table) {
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
