<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TABLE audit_events (
                    seq_id BIGINT GENERATED ALWAYS AS IDENTITY,
                    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
                    occurred_at TIMESTAMPTZ NOT NULL,
                    actor_user_id UUID NULL REFERENCES users(id) ON DELETE RESTRICT,
                    actor_ip INET NULL,
                    actor_device_id VARCHAR(128) NULL,
                    event_type VARCHAR(96) NOT NULL,
                    resource_type VARCHAR(64) NOT NULL,
                    resource_id VARCHAR(64) NULL,
                    classification_rank SMALLINT NOT NULL DEFAULT 0,
                    before_json JSONB NULL,
                    after_json JSONB NULL,
                    prev_hash CHAR(64) NOT NULL,
                    entry_hash CHAR(64) NOT NULL,
                    PRIMARY KEY (seq_id, occurred_at)
                ) PARTITION BY RANGE (occurred_at)
            SQL);

            DB::statement('CREATE INDEX audit_events_tenant_seq_idx ON audit_events (tenant_id, seq_id DESC)');
            DB::statement('CREATE INDEX audit_events_event_type_idx ON audit_events (tenant_id, event_type, occurred_at DESC)');
            DB::statement('CREATE INDEX audit_events_resource_idx ON audit_events (tenant_id, resource_type, resource_id)');

            $start = Carbon::now('UTC')->startOfMonth()->subMonth();
            for ($i = 0; $i < 14; $i++) {
                $from = $start->copy()->addMonths($i);
                $to = $from->copy()->addMonth();
                $suffix = $from->format('Y_m');
                $fromStr = $from->format('Y-m-d H:i:s+00');
                $toStr = $to->format('Y-m-d H:i:s+00');

                DB::statement(sprintf(
                    "CREATE TABLE IF NOT EXISTS audit_events_%s PARTITION OF audit_events FOR VALUES FROM ('%s') TO ('%s')",
                    $suffix,
                    $fromStr,
                    $toStr
                ));
            }

            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_audit_mutation()
                RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_events is append-only; % operations are strictly forbidden', TG_OP
                        USING ERRCODE = '42501';
                END;
                $$ LANGUAGE plpgsql;
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_audit_events_immutable
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW
                EXECUTE FUNCTION prevent_audit_mutation();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_audit_events_immutable_stmt
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH STATEMENT
                EXECUTE FUNCTION prevent_audit_mutation();
            SQL);
        } else {
            Schema::create('audit_events', function (Blueprint $table): void {
                $table->id('seq_id');
                $table->foreignUuid('tenant_id')->constrained('tenants');
                $table->timestampTz('occurred_at');
                $table->foreignUuid('actor_user_id')->nullable()->constrained('users');
                $table->string('actor_ip', 45)->nullable();
                $table->string('actor_device_id', 128)->nullable();
                $table->string('event_type', 96);
                $table->string('resource_type', 64);
                $table->string('resource_id', 64)->nullable();
                $table->unsignedSmallInteger('classification_rank')->default(0);
                $table->json('before_json')->nullable();
                $table->json('after_json')->nullable();
                $table->char('prev_hash', 64);
                $table->char('entry_hash', 64);
            });
        }

        Schema::create('audit_checkpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->unsignedBigInteger('first_seq_id');
            $table->unsignedBigInteger('last_seq_id');
            $table->unsignedInteger('event_count');
            $table->char('head_hash', 64);
            $table->text('signature_hmac');
            $table->timestamps();

            $table->index(['tenant_id', 'last_seq_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_checkpoints');
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_audit_events_immutable_stmt ON audit_events');
            DB::statement('DROP TRIGGER IF EXISTS trg_audit_events_immutable ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS prevent_audit_mutation()');
            DB::statement('DROP TABLE IF EXISTS audit_events CASCADE');
        } else {
            Schema::dropIfExists('audit_events');
        }
    }
};
