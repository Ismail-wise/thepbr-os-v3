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
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('actor_type', 32);
            $table->string('actor_identifier', 191);
            $table->string('action', 128);
            $table->string('target_type', 96);
            $table->uuid('target_id');
            $table->uuid('target_version_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->uuid('correlation_id')->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table->index(
                ['business_id', 'occurred_at'],
                'audit_events_business_time_idx',
            );
            $table->index(
                ['business_id', 'target_type', 'target_id'],
                'audit_events_target_idx',
            );
            $table->index(
                ['business_id', 'correlation_id'],
                'audit_events_correlation_idx',
            );
        });

        DB::statement(
            "ALTER TABLE audit_events
             ADD CONSTRAINT audit_events_actor_type_check
             CHECK (actor_type IN ('user', 'system', 'service_integration'))",
        );

        DB::statement(
            "ALTER TABLE audit_events
             ADD CONSTRAINT audit_events_metadata_object_check
             CHECK (jsonb_typeof(metadata) = 'object')",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_protect_audit_event()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'audit events are append-only';
END;
$$;

CREATE TRIGGER audit_events_immutable
BEFORE UPDATE OR DELETE
ON audit_events
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_audit_event();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_audit_event();',
        );
    }
};
