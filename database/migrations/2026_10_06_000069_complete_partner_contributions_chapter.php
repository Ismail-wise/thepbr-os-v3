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
            'contribution_setups',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id')->unique();
                $table->string(
                    'contract_version',
                    80,
                );
                $table->date('valuation_date');
                $table->char('currency', 3);
                $table->date('period_start');
                $table->date('period_end');
                $table->uuid(
                    'valuation_owner_membership_id',
                );
                $table->unsignedBigInteger(
                    'revision',
                )->default(1);
                $table->uuid(
                    'updated_by_membership_id',
                );
                $table->timestampsTz();

                $table->unique(
                    ['id', 'business_id'],
                    'contribution_setups_id_business_unique',
                );

                $table->foreign('business_id')
                    ->references('id')
                    ->on('businesses')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'valuation_owner_membership_id',
                        'business_id',
                    ],
                    'contribution_setups_owner_business_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                    ])
                    ->on('memberships')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'updated_by_membership_id',
                        'business_id',
                    ],
                    'contribution_setups_updater_business_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                    ])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'contribution_setup_approvers',
            function (Blueprint $table): void {
                $table->uuid(
                    'contribution_setup_id',
                );
                $table->uuid('business_id');
                $table->uuid('membership_id');
                $table->timestampTz(
                    'created_at',
                )->useCurrent();

                $table->primary(
                    [
                        'contribution_setup_id',
                        'membership_id',
                    ],
                    'contribution_setup_approvers_primary',
                );

                $table->foreign(
                    [
                        'contribution_setup_id',
                        'business_id',
                    ],
                    'contribution_setup_approvers_setup_business_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                    ])
                    ->on('contribution_setups')
                    ->cascadeOnDelete();

                $table->foreign(
                    [
                        'membership_id',
                        'business_id',
                    ],
                    'contribution_setup_approvers_member_business_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                    ])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        Schema::table(
            'cash_contribution_details',
            function (Blueprint $table): void {
                $table->boolean(
                    'amount_received_recorded',
                )->default(true);
            },
        );

        Schema::table(
            'asset_contribution_details',
            function (Blueprint $table): void {
                $table->string(
                    'asset_owner',
                    300,
                )->nullable();
            },
        );

        Schema::table(
            'contribution_delivery_events',
            function (Blueprint $table): void {
                $table->string(
                    'delivery_extent',
                    24,
                )->default('full');
                $table->text(
                    'delivered_scope',
                )->nullable();
                $table->text(
                    'adjustment_basis',
                )->nullable();
            },
        );

        Schema::create(
            'contribution_decision_records',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->string(
                    'contract_version',
                    80,
                );
                $table->uuid(
                    'contribution_setup_id',
                );
                $table->unsignedBigInteger(
                    'contribution_setup_revision',
                );
                $table->char('currency', 3);
                $table->unsignedInteger(
                    'accepted_contribution_count',
                );
                $table->decimal(
                    'accepted_total',
                    20,
                    2,
                );
                $table->char(
                    'accepted_register_hash',
                    64,
                );
                $table->uuid(
                    'decision_owner_membership_id',
                );
                $table->date('effective_date');
                $table->date('review_date');
                $table->text('decision_summary');
                $table->jsonb(
                    'evidence_references',
                )->default(
                    DB::raw("'[]'::jsonb"),
                );
                $table->uuid(
                    'created_by_membership_id',
                );
                $table->timestampTz(
                    'created_at',
                )->useCurrent();

                $table->unique(
                    ['id', 'business_id'],
                    'contribution_decision_records_id_business_unique',
                );

                $table->unique(
                    [
                        'business_id',
                        'accepted_register_hash',
                    ],
                    'contribution_decision_records_source_unique',
                );

                $table->foreign('business_id')
                    ->references('id')
                    ->on('businesses')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'contribution_setup_id',
                        'business_id',
                    ],
                    'contribution_decision_records_setup_business_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                    ])
                    ->on('contribution_setups')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'decision_owner_membership_id',
                        'business_id',
                    ],
                    'contribution_decision_records_owner_business_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                    ])
                    ->on('memberships')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'created_by_membership_id',
                        'business_id',
                    ],
                    'contribution_decision_records_creator_business_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                    ])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'contribution_decision_record_sources',
            function (Blueprint $table): void {
                $table->uuid(
                    'contribution_decision_record_id',
                );
                $table->uuid('business_id');
                $table->uuid('contribution_id');
                $table->unsignedBigInteger(
                    'contribution_revision',
                );
                $table->uuid(
                    'acceptance_decision_id',
                );
                $table->uuid(
                    'formal_record_version_id',
                );
                $table->uuid(
                    'proposal_version_id',
                );
                $table->decimal(
                    'accepted_value',
                    20,
                    2,
                );
                $table->timestampTz(
                    'accepted_at',
                );
                $table->jsonb(
                    'evidence_snapshot',
                )->default(
                    DB::raw("'[]'::jsonb"),
                );

                $table->primary(
                    [
                        'contribution_decision_record_id',
                        'contribution_id',
                    ],
                    'contribution_decision_record_sources_primary',
                );

                $table->foreign(
                    [
                        'contribution_decision_record_id',
                        'business_id',
                    ],
                    'contribution_decision_sources_record_business_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                    ])
                    ->on(
                        'contribution_decision_records',
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'contribution_id',
                        'business_id',
                    ],
                    'contribution_decision_sources_contribution_business_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                    ])
                    ->on('contributions')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'acceptance_decision_id',
                        'business_id',
                    ],
                    'contribution_decision_sources_decision_business_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                    ])
                    ->on('decisions')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'formal_record_version_id',
                        'business_id',
                    ],
                    'contribution_decision_sources_formal_business_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                    ])
                    ->on(
                        'formal_record_versions',
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'proposal_version_id',
                        'business_id',
                    ],
                    'contribution_decision_sources_proposal_business_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                    ])
                    ->on(
                        'proposal_versions',
                    )
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'contribution_action_links',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid(
                    'contribution_decision_record_id',
                );
                $table->uuid('action_id');
                $table->string(
                    'suggestion_key',
                    120,
                )->nullable();
                $table->timestampTz(
                    'created_at',
                )->useCurrent();

                $table->unique(
                    'action_id',
                    'contribution_action_links_action_unique',
                );

                $table->foreign(
                    [
                        'contribution_decision_record_id',
                        'business_id',
                    ],
                    'contribution_action_links_record_business_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                    ])
                    ->on(
                        'contribution_decision_records',
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'action_id',
                        'business_id',
                    ],
                    'contribution_action_links_action_business_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                    ])
                    ->on('actions')
                    ->restrictOnDelete();
            },
        );

        DB::statement(
            "ALTER TABLE contribution_setups
             ADD CONSTRAINT contribution_setups_currency_check
             CHECK (currency ~ '^[A-Z]{3}$')",
        );

        DB::statement(
            'ALTER TABLE contribution_setups
             ADD CONSTRAINT contribution_setups_period_check
             CHECK (
                 period_start <= period_end
                 AND revision > 0
             )',
        );

        DB::statement(
            "ALTER TABLE contribution_delivery_events
             ADD CONSTRAINT contribution_delivery_extent_check
             CHECK (
                 delivery_extent IN (
                     'full',
                     'partial'
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE contribution_decision_records
             ADD CONSTRAINT contribution_decision_records_currency_check
             CHECK (currency ~ '^[A-Z]{3}$')",
        );

        DB::statement(
            "ALTER TABLE contribution_decision_records
             ADD CONSTRAINT contribution_decision_records_hash_check
             CHECK (
                 accepted_register_hash
                 ~ '^[0-9a-f]{64}$'
             )",
        );

        DB::statement(
            'ALTER TABLE contribution_decision_records
             ADD CONSTRAINT contribution_decision_records_dates_check
             CHECK (
                 effective_date <= review_date
                 AND accepted_total >= 0
             )',
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_protect_contribution_decision_record()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Contribution Decision Record is immutable';
END;
$$;

CREATE TRIGGER contribution_decision_records_immutable
BEFORE UPDATE OR DELETE ON contribution_decision_records
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_contribution_decision_record();

CREATE TRIGGER contribution_decision_record_sources_immutable
BEFORE UPDATE OR DELETE ON contribution_decision_record_sources
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_contribution_decision_record();

CREATE OR REPLACE FUNCTION pbr_validate_contribution_action_link()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    action_decision uuid;
    action_formal uuid;
    source_match boolean;
BEGIN
    SELECT
        decision_id,
        formal_record_version_id
    INTO
        action_decision,
        action_formal
    FROM actions
    WHERE id = NEW.action_id
      AND business_id = NEW.business_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'Contribution Action requires an existing Action';
    END IF;

    SELECT EXISTS(
        SELECT 1
        FROM contribution_decision_record_sources source
        WHERE source.contribution_decision_record_id =
                NEW.contribution_decision_record_id
          AND source.business_id = NEW.business_id
          AND source.acceptance_decision_id =
                action_decision
          AND source.formal_record_version_id =
                action_formal
    )
    INTO source_match;

    IF source_match IS DISTINCT FROM TRUE THEN
        RAISE EXCEPTION
            'Contribution Action must pin an Accepted Contribution source recorded by the Decision Record';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER contribution_action_links_validate
BEFORE INSERT ON contribution_action_links
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_contribution_action_link();

CREATE OR REPLACE FUNCTION pbr_protect_contribution_action_link()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Contribution Action links are immutable';
END;
$$;

CREATE TRIGGER contribution_action_links_immutable
BEFORE UPDATE OR DELETE ON contribution_action_links
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_contribution_action_link();
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
    recorded_contribution_source boolean;
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
          AND decision_record.business_id =
              NEW.business_id;

        IF
            decision_status_value
                IS DISTINCT FROM 'decided'
            OR decision_outcome_value
                IS DISTINCT FROM 'approved'
        THEN
            RAISE EXCEPTION
                'Action Decision source must be Approved';
        END IF;
    END IF;

    IF NEW.formal_record_version_id IS NOT NULL THEN
        SELECT transition.to_state
        INTO record_state_value
        FROM record_version_state_transitions transition
        WHERE transition.formal_record_version_id =
                NEW.formal_record_version_id
          AND transition.business_id =
                NEW.business_id
        ORDER BY transition.sequence DESC
        LIMIT 1;

        recorded_capital_source := FALSE;
        recorded_contribution_source := FALSE;

        IF
            NEW.decision_id IS NOT NULL
            AND record_state_value = 'approved'
        THEN
            SELECT EXISTS(
                SELECT 1
                FROM capital_decision_records record
                WHERE record.business_id =
                        NEW.business_id
                  AND record.formal_record_version_id =
                        NEW.formal_record_version_id
                  AND record.governance_decision_id =
                        NEW.decision_id
            )
            INTO recorded_capital_source;

            SELECT EXISTS(
                SELECT 1
                FROM contribution_decision_record_sources source
                WHERE source.business_id =
                        NEW.business_id
                  AND source.formal_record_version_id =
                        NEW.formal_record_version_id
                  AND source.acceptance_decision_id =
                        NEW.decision_id
            )
            INTO recorded_contribution_source;
        END IF;

        IF
            record_state_value
                IS DISTINCT FROM 'effective'
            AND recorded_capital_source
                IS DISTINCT FROM TRUE
            AND recorded_contribution_source
                IS DISTINCT FROM TRUE
        THEN
            RAISE EXCEPTION
                'Action Formal Record source must be Effective or an exact recorded Approved decision source';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'contribution_action_links',
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS
             pbr_validate_contribution_action_link();
             DROP FUNCTION IF EXISTS
             pbr_protect_contribution_action_link();',
        );

        Schema::dropIfExists(
            'contribution_decision_record_sources',
        );
        Schema::dropIfExists(
            'contribution_decision_records',
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS
             pbr_protect_contribution_decision_record();',
        );

        Schema::table(
            'contribution_delivery_events',
            function (Blueprint $table): void {
                $table->dropColumn([
                    'delivery_extent',
                    'delivered_scope',
                    'adjustment_basis',
                ]);
            },
        );

        Schema::table(
            'asset_contribution_details',
            function (Blueprint $table): void {
                $table->dropColumn(
                    'asset_owner',
                );
            },
        );

        Schema::table(
            'cash_contribution_details',
            function (Blueprint $table): void {
                $table->dropColumn(
                    'amount_received_recorded',
                );
            },
        );

        Schema::dropIfExists(
            'contribution_setup_approvers',
        );
        Schema::dropIfExists(
            'contribution_setups',
        );

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
            RAISE EXCEPTION
                'Action Decision source must be Approved';
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
            RAISE EXCEPTION
                'Action Formal Record source must be Effective or an exact recorded Approved Capital Decision';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;
SQL);
    }
};
