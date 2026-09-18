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
        Schema::create('business_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('event_type', 160);
            $table->string('aggregate_type', 96);
            $table->uuid('aggregate_id');
            $table->uuid('aggregate_version_id')->nullable();
            $table->string('actor_type', 32)->nullable();
            $table->string('actor_identifier', 191)->nullable();
            $table->string('visibility_resource_type', 96);
            $table->uuid('visibility_resource_id');
            $table->timestampTz('occurred_at');
            $table->uuid('correlation_id')->nullable();
            $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table->index(
                ['business_id', 'occurred_at'],
                'business_events_business_time_idx',
            );
            $table->index(
                ['business_id', 'aggregate_type', 'aggregate_id'],
                'business_events_aggregate_idx',
            );
            $table->index(
                [
                    'business_id',
                    'visibility_resource_type',
                    'visibility_resource_id',
                ],
                'business_events_visibility_idx',
            );
            $table->index(
                ['business_id', 'correlation_id'],
                'business_events_correlation_idx',
            );
        });

        DB::statement(
            "ALTER TABLE business_events
             ADD CONSTRAINT business_events_actor_type_check
             CHECK (
                 actor_type IS NULL
                 OR actor_type IN ('user', 'system', 'service_integration')
             )",
        );

        DB::statement(
            'ALTER TABLE business_events
             ADD CONSTRAINT business_events_actor_pair_check
             CHECK (
                 (actor_type IS NULL AND actor_identifier IS NULL)
                 OR (actor_type IS NOT NULL AND actor_identifier IS NOT NULL)
             )',
        );

        DB::statement(
            "ALTER TABLE business_events
             ADD CONSTRAINT business_events_payload_object_check
             CHECK (jsonb_typeof(payload) = 'object')",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_protect_business_event()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'business events are append-only';
END;
$$;

CREATE TRIGGER business_events_immutable
BEFORE UPDATE OR DELETE
ON business_events
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_business_event();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_events');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_business_event();',
        );
    }
};
