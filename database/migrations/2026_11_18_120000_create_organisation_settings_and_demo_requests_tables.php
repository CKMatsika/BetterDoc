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
        Schema::create('organisation_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->boolean('is_public_default')->default(false);
            $table->string('profile_key', 32)->default('betterdoc');
            $table->string('name', 160);
            $table->string('short_name', 64);
            $table->string('tagline', 255)->default('Document management that works when the internet doesn\'t.');
            $table->string('logo_path', 500)->nullable();
            $table->string('logo_dark_path', 500)->nullable();
            $table->string('favicon_path', 500)->nullable();
            $table->string('primary_color', 16)->default('#0E7C66');
            $table->string('accent_color', 16)->default('#F59E0B');
            $table->string('welcome_title', 200)->default('Secure Document Portal');
            $table->text('welcome_text')->nullable();
            $table->text('notice_text')->nullable();
            $table->boolean('notice_enabled')->default(false);
            $table->string('support_email', 160)->nullable();
            $table->string('support_phone', 64)->nullable();
            $table->string('support_hours', 160)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->boolean('show_powered_by')->default(true);
            $table->string('landing_mode', 16)->nullable(); // portal | demo | off (null inherits LANDING_MODE env)
            $table->timestampsTz();

            $table->unique('tenant_id');
            $table->index('is_public_default');
        });

        Schema::create('demo_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('name', 120);
            $table->string('organisation', 160);
            $table->string('work_email', 160);
            $table->string('phone', 64)->nullable();
            $table->text('message')->nullable();
            $table->string('source_ip', 64)->nullable();
            $table->string('status', 32)->default('new');
            $table->timestampsTz();

            $table->index(['created_at', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'betterdoc_app') THEN
                        GRANT SELECT, INSERT, UPDATE, DELETE ON organisation_settings, demo_requests TO betterdoc_app;
                    END IF;
                END
                $$;
            SQL);

            foreach (['organisation_settings', 'demo_requests'] as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            }

            // Branding row readable publicly when marked public/global or matching current tenant; writes restricted to matching tenant or global admin
            DB::statement(<<<'SQL'
                CREATE POLICY organisation_settings_access ON organisation_settings
                FOR ALL
                USING (
                    tenant_id IS NULL
                    OR is_public_default = true
                    OR tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                )
                WITH CHECK (
                    tenant_id IS NULL
                    OR tenant_id = NULLIF(current_setting('betterdoc.tenant_id', true), '')::uuid
                )
            SQL);

            // Anonymous demo intake may INSERT; viewing/listing demo requests requires SystemAdmin role in PostgreSQL session
            DB::statement(<<<'SQL'
                CREATE POLICY demo_requests_insert_public ON demo_requests
                FOR INSERT
                WITH CHECK (true)
            SQL);

            DB::statement(<<<'SQL'
                CREATE POLICY demo_requests_admin_read ON demo_requests
                FOR SELECT
                USING (
                    EXISTS (
                        SELECT 1
                        FROM model_has_roles mhr
                        JOIN roles r ON r.id = mhr.role_id
                        WHERE mhr.model_id = NULLIF(current_setting('betterdoc.user_id', true), '')::uuid
                          AND r.name = 'SystemAdmin'
                    )
                )
            SQL);

            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION betterdoc_set_public_default_branding(p_setting_id uuid)
                RETURNS void
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = public, pg_temp
                AS $$
                BEGIN
                    UPDATE organisation_settings
                    SET is_public_default = (id = p_setting_id);
                END;
                $$;
            SQL);

            DB::statement(<<<'SQL'
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'betterdoc_app') THEN
                        REVOKE ALL ON FUNCTION betterdoc_set_public_default_branding(uuid) FROM PUBLIC;
                        GRANT EXECUTE ON FUNCTION betterdoc_set_public_default_branding(uuid) TO betterdoc_app;
                    END IF;
                END
                $$;
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS betterdoc_set_public_default_branding(uuid)');
            DB::statement('DROP POLICY IF EXISTS demo_requests_admin_read ON demo_requests');
            DB::statement('DROP POLICY IF EXISTS demo_requests_insert_public ON demo_requests');
            DB::statement('DROP POLICY IF EXISTS organisation_settings_access ON organisation_settings');

            foreach (['demo_requests', 'organisation_settings'] as $table) {
                DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            }
        }

        Schema::dropIfExists('demo_requests');
        Schema::dropIfExists('organisation_settings');
    }
};
