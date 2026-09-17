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
        Schema::create(
            'record_version_state_transitions',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('formal_record_version_id');
                $table->unsignedBigInteger('sequence');
                $table->string('from_state', 40)->nullable();
                $table->string('to_state', 40);
                $table->uuid('transitioned_by_user_id');
                $table->timestampTz('occurred_at');
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['formal_record_version_id', 'sequence'],
                    'record_state_transitions_version_sequence_unique',
                );

                $table->foreign(
                    ['formal_record_version_id', 'business_id'],
                    'record_state_transitions_version_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('formal_record_versions')
                    ->restrictOnDelete();

                $table->foreign('transitioned_by_user_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
            },
        );

        DB::statement(
            'ALTER TABLE record_version_state_transitions
             ADD CONSTRAINT record_state_transitions_sequence_positive
             CHECK (sequence > 0)',
        );

        DB::statement(
            "ALTER TABLE record_version_state_transitions
             ADD CONSTRAINT record_state_transitions_from_valid
             CHECK (
                 from_state IS NULL
                 OR from_state IN (
                     'draft',
                     'ready_for_review',
                     'under_review',
                     'changes_requested',
                     'rejected',
                     'approved',
                     'ready_for_effect',
                     'effective',
                     'superseded',
                     'archived'
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE record_version_state_transitions
             ADD CONSTRAINT record_state_transitions_to_valid
             CHECK (
                 to_state IN (
                     'draft',
                     'ready_for_review',
                     'under_review',
                     'changes_requested',
                     'rejected',
                     'approved',
                     'ready_for_effect',
                     'effective',
                     'superseded',
                     'archived'
                 )
             )",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_record_state_transition()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    previous_sequence bigint;
    previous_state text;
    version_frozen_at timestamptz;
    version_effective_from timestamptz;
    version_effective_until timestamptz;
BEGIN
    SELECT
        frozen_at,
        effective_from,
        effective_until
    INTO
        version_frozen_at,
        version_effective_from,
        version_effective_until
    FROM formal_record_versions
    WHERE id = NEW.formal_record_version_id
      AND business_id = NEW.business_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'formal record version does not exist in this Business';
    END IF;

    SELECT sequence, to_state
    INTO previous_sequence, previous_state
    FROM record_version_state_transitions
    WHERE formal_record_version_id = NEW.formal_record_version_id
    ORDER BY sequence DESC
    LIMIT 1;

    IF NOT FOUND THEN
        IF NEW.sequence <> 1
           OR NEW.from_state IS NOT NULL
           OR NEW.to_state <> 'draft'
        THEN
            RAISE EXCEPTION 'first lifecycle state must be Draft sequence 1';
        END IF;

        RETURN NEW;
    END IF;

    IF NEW.sequence <> previous_sequence + 1 THEN
        RAISE EXCEPTION 'lifecycle transition sequence must be contiguous';
    END IF;

    IF NEW.from_state IS DISTINCT FROM previous_state THEN
        RAISE EXCEPTION 'lifecycle transition from-state must match current state';
    END IF;

    IF NOT (
        (NEW.from_state = 'draft' AND NEW.to_state = 'ready_for_review')
        OR (NEW.from_state = 'ready_for_review' AND NEW.to_state = 'under_review')
        OR (
            NEW.from_state = 'under_review'
            AND NEW.to_state IN ('changes_requested', 'rejected', 'approved')
        )
        OR (NEW.from_state = 'approved' AND NEW.to_state = 'ready_for_effect')
        OR (NEW.from_state = 'ready_for_effect' AND NEW.to_state = 'effective')
        OR (NEW.from_state = 'effective' AND NEW.to_state = 'superseded')
        OR (
            NEW.from_state IN ('rejected', 'superseded')
            AND NEW.to_state = 'archived'
        )
    ) THEN
        RAISE EXCEPTION 'invalid formal record lifecycle transition';
    END IF;

    IF NEW.to_state <> 'draft' AND version_frozen_at IS NULL THEN
        RAISE EXCEPTION 'non-Draft lifecycle states require a frozen version';
    END IF;

    IF NEW.to_state = 'effective' THEN
        IF version_effective_from IS NULL THEN
            RAISE EXCEPTION 'Effective state requires effective_from';
        END IF;

        IF version_effective_from > NEW.occurred_at THEN
            RAISE EXCEPTION 'future-effective version cannot become Effective early';
        END IF;

        IF (
            version_effective_until IS NOT NULL
            AND version_effective_until <= NEW.occurred_at
        ) THEN
            RAISE EXCEPTION 'expired planned effective period cannot become Effective';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER record_state_transitions_validate
BEFORE INSERT
ON record_version_state_transitions
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_record_state_transition();

CREATE OR REPLACE FUNCTION pbr_protect_record_state_transition()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'formal record lifecycle history is append-only';
END;
$$;

CREATE TRIGGER record_state_transitions_append_only
BEFORE UPDATE OR DELETE
ON record_version_state_transitions
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_record_state_transition();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('record_version_state_transitions');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_record_state_transition();',
        );
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_record_state_transition();',
        );
    }
};
