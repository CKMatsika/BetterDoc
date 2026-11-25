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
        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table): void {
                $table->id();
                $table->uuidMorphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        Schema::table('documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('documents', 'external_system')) {
                $table->string('external_system', 48)->nullable()->after('hr_restricted_from_subject');
            }
            if (! Schema::hasColumn('documents', 'external_reference')) {
                $table->string('external_reference', 128)->nullable()->after('external_system');
            }
            if (! Schema::hasColumn('documents', 'policy_number')) {
                $table->string('policy_number', 96)->nullable()->after('external_reference');
            }
            if (! Schema::hasColumn('documents', 'claim_number')) {
                $table->string('claim_number', 96)->nullable()->after('policy_number');
            }

            $table->index(['tenant_id', 'policy_number'], 'documents_tenant_policy_idx');
            $table->index(['tenant_id', 'claim_number'], 'documents_tenant_claim_idx');
            $table->index(['tenant_id', 'external_system', 'external_reference'], 'documents_tenant_ext_ref_idx');
        });

        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('system_type', 48); // insurance_core | xero | netsuite | sage_pastel | belina | custom_webhook
            $table->string('transport', 32)->default('rest_api'); // rest_api | webhook | csv_batch | local_bridge
            $table->string('base_url', 500)->nullable();
            $table->text('credentials_encrypted')->nullable();
            $table->jsonb('field_mapping_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'system_type']);
        });

        Schema::create('integration_webhooks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('integration_connection_id')->nullable()->constrained('integration_connections')->nullOnDelete();
            $table->string('name', 120);
            $table->string('target_url', 500);
            $table->text('secret_encrypted');
            $table->jsonb('events_json');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_dispatched_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('integration_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('integration_webhook_id')->constrained('integration_webhooks')->cascadeOnDelete();
            $table->string('event_type', 80);
            $table->jsonb('payload_json');
            $table->string('status', 32)->default('pending'); // pending | delivered | failed | blocked_airgap
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('response_excerpt', 500)->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('external_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('integration_connection_id')->nullable()->constrained('integration_connections')->nullOnDelete();
            $table->string('external_system', 48);
            $table->string('entity_type', 48);
            $table->string('external_id', 160);
            $table->uuid('internal_id');
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'external_system', 'entity_type', 'external_id'], 'ext_mappings_unique_ext');
            $table->index(['tenant_id', 'entity_type', 'internal_id'], 'ext_mappings_internal_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'betterdoc_app') THEN
                        GRANT SELECT, INSERT, UPDATE, DELETE ON personal_access_tokens, integration_connections, integration_webhooks, integration_deliveries, external_mappings TO betterdoc_app;
                        GRANT USAGE, SELECT ON SEQUENCE personal_access_tokens_id_seq TO betterdoc_app;
                    END IF;
                END
                $$;
            SQL);

            foreach (['integration_connections', 'integration_webhooks', 'integration_deliveries', 'external_mappings'] as $table) {
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
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            foreach (['external_mappings', 'integration_deliveries', 'integration_webhooks', 'integration_connections'] as $table) {
                DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
                DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            }
        }

        Schema::dropIfExists('external_mappings');
        Schema::dropIfExists('integration_deliveries');
        Schema::dropIfExists('integration_webhooks');
        Schema::dropIfExists('integration_connections');

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex('documents_tenant_policy_idx');
            $table->dropIndex('documents_tenant_claim_idx');
            $table->dropIndex('documents_tenant_ext_ref_idx');
            $table->dropColumn(['external_system', 'external_reference', 'policy_number', 'claim_number']);
        });

        Schema::dropIfExists('personal_access_tokens');
    }
};
