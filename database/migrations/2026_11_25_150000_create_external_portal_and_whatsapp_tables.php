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
        Schema::create('external_portal_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('created_by');
            $table->uuid('cabinet_id');
            $table->uuid('classification_level_id');
            $table->uuid('document_id')->nullable();
            $table->uuid('vendor_id')->nullable();
            $table->string('request_type', 32)->default('upload'); // upload, esign, upload_and_esign
            $table->string('title', 180);
            $table->text('instructions')->nullable();
            $table->string('recipient_name', 160);
            $table->string('recipient_email', 160)->nullable();
            $table->string('recipient_phone', 48)->nullable();
            $table->string('policy_number', 64)->nullable();
            $table->string('claim_number', 64)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('otp_hash', 64)->nullable();
            $table->string('status', 32)->default('active'); // active, completed, expired, revoked
            $table->unsignedInteger('max_uploads')->default(5);
            $table->unsignedInteger('uploads_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_by_name', 160)->nullable();
            $table->string('signer_id_number', 64)->nullable();
            $table->string('signature_hash', 64)->nullable();
            $table->string('signer_ip', 64)->nullable();
            $table->jsonb('uploaded_doc_ids_json')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('cabinet_id')->references('id')->on('cabinets')->cascadeOnDelete();
            $table->foreign('classification_level_id')->references('id')->on('clearance_levels')->cascadeOnDelete();
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('whatsapp_channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('created_by');
            $table->string('display_name', 160);
            $table->string('phone_number_id', 64)->unique();
            $table->string('business_account_id', 64)->nullable();
            $table->string('verify_token_hash', 64);
            $table->text('app_secret_encrypted')->nullable();
            $table->text('access_token_encrypted')->nullable();
            $table->uuid('default_cabinet_id');
            $table->uuid('default_classification_level_id');
            $table->boolean('auto_idp_invoices')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('default_cabinet_id')->references('id')->on('cabinets')->cascadeOnDelete();
            $table->foreign('default_classification_level_id')->references('id')->on('clearance_levels')->cascadeOnDelete();
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('whatsapp_channel_id');
            $table->string('direction', 16); // inbound, outbound
            $table->string('wa_message_id', 128)->nullable();
            $table->string('from_phone', 48);
            $table->string('to_phone', 48)->nullable();
            $table->string('sender_name', 160)->nullable();
            $table->string('message_type', 32)->default('text'); // text, image, document, approval_alert
            $table->text('body_text')->nullable();
            $table->uuid('document_id')->nullable();
            $table->string('status', 32)->default('received'); // received, ingested, sent, blocked_airgap, failed
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('whatsapp_channel_id')->references('id')->on('whatsapp_channels')->cascadeOnDelete();
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'betterdoc_app') THEN
                        GRANT SELECT, INSERT, UPDATE, DELETE ON external_portal_requests, whatsapp_channels, whatsapp_messages TO betterdoc_app;
                    END IF;
                END
                $$;
            SQL);

            foreach (['external_portal_requests', 'whatsapp_channels', 'whatsapp_messages'] as $table) {
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

            // SECURITY DEFINER lookups for unauthenticated magic-link and Meta webhook routing
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION resolve_external_portal_request(p_token_hash TEXT)
                RETURNS TABLE (request_id UUID, tenant_id UUID)
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = public
                AS $$
                BEGIN
                    RETURN QUERY
                    SELECT r.id, r.tenant_id
                    FROM external_portal_requests r
                    WHERE r.token_hash = p_token_hash
                    LIMIT 1;
                END;
                $$;
            SQL);

            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION resolve_whatsapp_channel(p_phone_number_id TEXT, p_verify_token_hash TEXT)
                RETURNS TABLE (channel_id UUID, tenant_id UUID)
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = public
                AS $$
                BEGIN
                    IF p_phone_number_id IS NOT NULL AND p_phone_number_id <> '' THEN
                        RETURN QUERY
                        SELECT c.id, c.tenant_id
                        FROM whatsapp_channels c
                        WHERE c.phone_number_id = p_phone_number_id
                          AND c.is_active = TRUE
                        LIMIT 1;
                    ELSIF p_verify_token_hash IS NOT NULL AND p_verify_token_hash <> '' THEN
                        RETURN QUERY
                        SELECT c.id, c.tenant_id
                        FROM whatsapp_channels c
                        WHERE c.verify_token_hash = p_verify_token_hash
                          AND c.is_active = TRUE
                        LIMIT 1;
                    END IF;
                END;
                $$;
            SQL);

            DB::statement(<<<'SQL'
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'betterdoc_app') THEN
                        GRANT EXECUTE ON FUNCTION resolve_external_portal_request(TEXT) TO betterdoc_app;
                        GRANT EXECUTE ON FUNCTION resolve_whatsapp_channel(TEXT, TEXT) TO betterdoc_app;
                    END IF;
                END
                $$;
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS resolve_whatsapp_channel(TEXT, TEXT)');
            DB::statement('DROP FUNCTION IF EXISTS resolve_external_portal_request(TEXT)');
            foreach (['whatsapp_messages', 'whatsapp_channels', 'external_portal_requests'] as $table) {
                DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            }
        }

        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_channels');
        Schema::dropIfExists('external_portal_requests');
    }
};
