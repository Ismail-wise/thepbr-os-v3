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
        Schema::create('capital_action_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('capital_decision_record_id');
            $table->uuid('action_id');
            $table->string('suggestion_key', 80)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('action_id', 'capital_action_links_action_uq');

            $table->foreign(
                ['capital_decision_record_id', 'business_id'],
                'capital_action_links_decision_record_fk',
            )->references(['id', 'business_id'])
                ->on('capital_decision_records')
                ->restrictOnDelete();

            $table->foreign(
                ['action_id', 'business_id'],
                'capital_action_links_action_fk',
            )->references(['id', 'business_id'])
                ->on('actions')
                ->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE capital_action_links
             ADD CONSTRAINT capital_action_links_suggestion_ck
             CHECK (
                 suggestion_key IS NULL
                 OR suggestion_key IN (
                     'review_approved_decision',
                     'prepare_scope_reduction',
                     'plan_delayed_items',
                     'prepare_borrowing_review',
                     'prepare_capital_call_process',
                     'complete_required_signature'
                 )
             )",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_capital_action_link()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    record_decision uuid;
    record_formal uuid;
    action_decision uuid;
    action_formal uuid;
BEGIN
    SELECT governance_decision_id, formal_record_version_id
      INTO record_decision, record_formal
      FROM capital_decision_records
     WHERE id = NEW.capital_decision_record_id
       AND business_id = NEW.business_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Capital Action requires a recorded Capital Decision';
    END IF;

    SELECT decision_id, formal_record_version_id
      INTO action_decision, action_formal
      FROM actions
     WHERE id = NEW.action_id
       AND business_id = NEW.business_id;

    IF NOT FOUND
       OR action_decision IS DISTINCT FROM record_decision
       OR action_formal IS DISTINCT FROM record_formal
    THEN
        RAISE EXCEPTION 'Capital Action must pin the exact recorded Governance Decision and Formal Record Version';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER capital_action_links_validate
BEFORE INSERT ON capital_action_links
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_capital_action_link();

CREATE OR REPLACE FUNCTION pbr_protect_capital_action_link()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Capital Action links are immutable';
END;
$$;

CREATE TRIGGER capital_action_links_immutable
BEFORE UPDATE OR DELETE ON capital_action_links
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_capital_action_link();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_action()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    decision_status_value text;
    decision_outcome_value text;
    record_state_value text;
    recorded_capital_source boolean;
BEGIN
    IF NEW.decision_id IS NOT NULL THEN
        SELECT
            decision_record.status,
            decision_record.outcome
        INTO
            decision_status_value,
            decision_outcome_value
        FROM decisions decision_record
        WHERE decision_record.id = NEW.decision_id
          AND decision_record.business_id = NEW.business_id;

        IF
            decision_status_value IS DISTINCT FROM 'decided'
            OR decision_outcome_value IS DISTINCT FROM 'approved'
        THEN
            RAISE EXCEPTION 'Action Decision source must be Approved';
        END IF;
    END IF;

    IF NEW.formal_record_version_id IS NOT NULL THEN
        SELECT transition.to_state
        INTO record_state_value
        FROM record_version_state_transitions transition
        WHERE transition.formal_record_version_id =
            NEW.formal_record_version_id
          AND transition.business_id = NEW.business_id
        ORDER BY transition.sequence DESC
        LIMIT 1;

        recorded_capital_source := FALSE;

        IF
            NEW.decision_id IS NOT NULL
            AND record_state_value = 'approved'
        THEN
            SELECT EXISTS(
                SELECT 1
                FROM capital_decision_records record
                WHERE record.business_id = NEW.business_id
                  AND record.formal_record_version_id =
                      NEW.formal_record_version_id
                  AND record.governance_decision_id =
                      NEW.decision_id
            )
            INTO recorded_capital_source;
        END IF;

        IF
            record_state_value IS DISTINCT FROM 'effective'
            AND recorded_capital_source IS DISTINCT FROM TRUE
        THEN
            RAISE EXCEPTION 'Action Formal Record source must be Effective or an exact recorded Approved Capital Decision';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('capital_action_links');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_validate_capital_action_link();');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_protect_capital_action_link();');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_action()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    decision_status_value text;
    decision_outcome_value text;
    record_state_value text;
BEGIN
    IF NEW.decision_id IS NOT NULL THEN
        SELECT
            decision_record.status,
            decision_record.outcome
        INTO
            decision_status_value,
            decision_outcome_value
        FROM decisions decision_record
        WHERE decision_record.id = NEW.decision_id
          AND decision_record.business_id = NEW.business_id;

        IF
            decision_status_value IS DISTINCT FROM 'decided'
            OR decision_outcome_value IS DISTINCT FROM 'approved'
        THEN
            RAISE EXCEPTION 'Action Decision source must be Approved';
        END IF;
    END IF;

    IF NEW.formal_record_version_id IS NOT NULL THEN
        SELECT transition.to_state
        INTO record_state_value
        FROM record_version_state_transitions transition
        WHERE transition.formal_record_version_id =
            NEW.formal_record_version_id
          AND transition.business_id = NEW.business_id
        ORDER BY transition.sequence DESC
        LIMIT 1;

        IF record_state_value IS DISTINCT FROM 'effective' THEN
            RAISE EXCEPTION 'Action Formal Record source must be Effective';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;
SQL);
    }
};
