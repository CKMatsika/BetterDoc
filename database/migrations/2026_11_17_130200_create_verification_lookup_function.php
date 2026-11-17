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
            CREATE OR REPLACE FUNCTION sentinel_verify_token_lookup(p_token char(32))
            RETURNS jsonb
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                v_env RECORD;
                v_doc RECORD;
                v_ver RECORD;
                v_signers jsonb;
            BEGIN
                SELECT * INTO v_env
                FROM signature_envelopes
                WHERE verification_token = p_token
                LIMIT 1;

                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                SELECT id, tenant_id, doc_number, title, classification_rank INTO v_doc
                FROM documents
                WHERE id = v_env.document_id
                LIMIT 1;

                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                SELECT overall_status, signatures_count, verified_at, details_json INTO v_ver
                FROM signature_verifications
                WHERE document_version_id = COALESCE(v_env.sealed_version_id, v_env.working_version_id, v_env.source_version_id)
                LIMIT 1;

                SELECT COALESCE(jsonb_agg(jsonb_build_object(
                    'sign_order', sr.sign_order,
                    'signer', trim(COALESCE(u.rank_title || ' ', '') || u.name),
                    'tier', sr.tier,
                    'status', sr.status,
                    'cert_serial', sr.cert_serial,
                    'tsa_token_serial', sr.tsa_token_serial,
                    'signed_at', sr.signed_at,
                    'sha256', sr.document_sha256_at_sign
                ) ORDER BY sr.sign_order), '[]'::jsonb)
                INTO v_signers
                FROM signature_requests sr
                JOIN users u ON u.id = sr.signer_id
                WHERE sr.envelope_id = v_env.id;

                RETURN jsonb_build_object(
                    'envelope', jsonb_build_object(
                        'status', v_env.status,
                        'mode', v_env.mode,
                        'completed_at', v_env.completed_at,
                        'title', v_env.title
                    ),
                    'document', jsonb_build_object(
                        'id', v_doc.id,
                        'tenant_id', v_doc.tenant_id,
                        'doc_number', v_doc.doc_number,
                        'title', v_doc.title,
                        'classification_rank', v_doc.classification_rank
                    ),
                    'verification', jsonb_build_object(
                        'overall_status', COALESCE(v_ver.overall_status, 'unsigned'),
                        'signatures_count', COALESCE(v_ver.signatures_count, 0),
                        'verified_at', v_ver.verified_at,
                        'details', COALESCE(v_ver.details_json, '{}'::jsonb)
                    ),
                    'signers', v_signers
                );
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'sentinel_app') THEN
                    REVOKE ALL ON FUNCTION sentinel_verify_token_lookup(char(32)) FROM PUBLIC;
                    GRANT EXECUTE ON FUNCTION sentinel_verify_token_lookup(char(32)) TO sentinel_app;
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

        DB::statement('DROP FUNCTION IF EXISTS sentinel_verify_token_lookup(char(32))');
    }
};
