<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 64);
            $table->uuid('subject_user_id');
            $table->string('actor_label', 160);
            $table->string('source', 64);
            $table->text('reason');
            $table->jsonb('metadata');
            $table->timestampTz('occurred_at');

            $table
                ->foreign('subject_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index(['subject_user_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        DB::statement(
            "ALTER TABLE security_events
             ADD CONSTRAINT security_events_type_check
             CHECK (event_type IN (
                'account.provisioned',
                'account.status_changed',
                'account.password_changed'
             ))"
        );

        DB::statement(
            "ALTER TABLE security_events
             ADD CONSTRAINT security_events_actor_label_check
             CHECK (btrim(actor_label) <> '')"
        );

        DB::statement(
            "ALTER TABLE security_events
             ADD CONSTRAINT security_events_source_check
             CHECK (btrim(source) <> '')"
        );

        DB::statement(
            "ALTER TABLE security_events
             ADD CONSTRAINT security_events_reason_check
             CHECK (btrim(reason) <> '')"
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_prevent_security_event_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'security_events is append-only (% not allowed)', TG_OP;
END;
$$
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER security_events_prevent_update_delete
BEFORE UPDATE OR DELETE ON security_events
FOR EACH ROW
EXECUTE FUNCTION thepbr_prevent_security_event_mutation()
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER security_events_prevent_truncate
BEFORE TRUNCATE ON security_events
FOR EACH STATEMENT
EXECUTE FUNCTION thepbr_prevent_security_event_mutation()
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS thepbr_prevent_security_event_mutation()'
        );
    }
};
