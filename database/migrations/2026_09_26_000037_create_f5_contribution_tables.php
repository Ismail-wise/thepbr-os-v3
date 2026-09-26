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
        Schema::create('contributions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('partner_id');

            $table->string('contribution_type', 40);
            $table->string('status', 24)->default('proposed');
            $table->char('currency', 3);

            $table->string('description', 300);
            $table->decimal('proposed_value', 20, 2);
            $table->decimal('reviewed_value', 20, 2)->nullable();
            $table->decimal('approved_value', 20, 2)->nullable();
            $table->decimal('accepted_value', 20, 2)->nullable();

            $table->string('valuation_method', 200)->nullable();
            $table->text('conditions')->nullable();

            $table->date('committed_date')->nullable();
            $table->date('due_date')->nullable();

            $table->uuid('approval_decision_id')->nullable();
            $table->uuid('acceptance_decision_id')->nullable();

            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();

            $table->unique(
                ['id', 'business_id'],
                'contributions_id_business_unique',
            );

            $table->index(
                ['business_id', 'partner_id', 'status'],
                'contributions_business_partner_status_index',
            );

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table->foreign(
                ['partner_id', 'business_id'],
                'contributions_partner_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('partners')
                ->restrictOnDelete();

            $table->foreign(
                ['approval_decision_id', 'business_id'],
                'contributions_approval_decision_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('decisions')
                ->restrictOnDelete();

            $table->foreign(
                ['acceptance_decision_id', 'business_id'],
                'contributions_acceptance_decision_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('decisions')
                ->restrictOnDelete();
        });

        Schema::create(
            'cash_contribution_details',
            function (Blueprint $table): void {
                $table->uuid('contribution_id')->primary();
                $table->uuid('business_id');
                $table->decimal('amount_committed', 20, 2);
                $table->decimal('amount_received', 20, 2)->default(0);
                $table->date('payment_date')->nullable();

                $table->foreign(
                    ['contribution_id', 'business_id'],
                    'cash_contribution_parent_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('contributions')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'time_skill_contribution_details',
            function (Blueprint $table): void {
                $table->uuid('contribution_id')->primary();
                $table->uuid('business_id');
                $table->string('role_work', 300);
                $table->decimal('hours_per_month', 10, 2);
                $table->decimal('fair_market_rate', 20, 2);
                $table->unsignedInteger('number_of_months');
                $table->decimal(
                    'cash_compensation_received',
                    20,
                    2,
                )->default(0);
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->text('performance_condition')->nullable();
                $table->text('vesting_rule')->nullable();

                $table->foreign(
                    ['contribution_id', 'business_id'],
                    'time_skill_contribution_parent_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('contributions')
                    ->restrictOnDelete();
            },
        );

        DB::statement(
            'ALTER TABLE time_skill_contribution_details
             ADD COLUMN calculated_sweat_value numeric(20,2)
             GENERATED ALWAYS AS (
                 GREATEST(
                     ROUND(
                         hours_per_month
                         * fair_market_rate
                         * number_of_months,
                         2
                     )
                     - cash_compensation_received,
                     0
                 )
             ) STORED',
        );

        Schema::create(
            'asset_contribution_details',
            function (Blueprint $table): void {
                $table->uuid('contribution_id')->primary();
                $table->uuid('business_id');
                $table->string('asset_description', 300);
                $table->boolean('ownership_transferred');
                $table->string('usage_period', 160)->nullable();
                $table->decimal('market_value', 20, 2)->nullable();
                $table->decimal(
                    'fair_rental_use_value',
                    20,
                    2,
                )->nullable();
                $table->string('valuation_method', 200)->nullable();

                $table->foreign(
                    ['contribution_id', 'business_id'],
                    'asset_contribution_parent_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('contributions')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'intangible_contribution_details',
            function (Blueprint $table): void {
                $table->uuid('contribution_id')->primary();
                $table->uuid('business_id');
                $table->string('intangible_kind', 120);
                $table->text('intangible_description');
                $table->string('legal_beneficial_owner', 300);
                $table->string('contribution_form', 160);
                $table->string('contribution_period', 160)->nullable();
                $table->string('valuation_method', 200);

                $table->foreign(
                    ['contribution_id', 'business_id'],
                    'intangible_contribution_parent_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('contributions')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'contribution_delivery_events',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('contribution_id');
                $table->decimal('delivered_value', 20, 2);
                $table->timestampTz('delivered_at');
                $table->text('notes')->nullable();
                $table->uuid('recorded_by_membership_id');
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['id', 'business_id'],
                    'contribution_delivery_id_business_unique',
                );

                $table->foreign(
                    ['contribution_id', 'business_id'],
                    'contribution_delivery_parent_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('contributions')
                    ->restrictOnDelete();

                $table->foreign(
                    ['recorded_by_membership_id', 'business_id'],
                    'contribution_delivery_member_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'contribution_status_transitions',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('contribution_id');
                $table->string('from_status', 24)->nullable();
                $table->string('to_status', 24);
                $table->uuid('changed_by_membership_id');
                $table->text('note')->nullable();
                $table->timestampTz('changed_at');

                $table->foreign(
                    ['contribution_id', 'business_id'],
                    'contribution_transition_parent_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('contributions')
                    ->restrictOnDelete();

                $table->foreign(
                    ['changed_by_membership_id', 'business_id'],
                    'contribution_transition_member_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'contribution_governance_submissions',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('contribution_id');
                $table->unsignedBigInteger('contribution_revision');
                $table->string('phase', 24);
                $table->decimal(
                    'proposed_accepted_value',
                    20,
                    2,
                )->nullable();

                $table->uuid('formal_record_family_id');
                $table->uuid('formal_record_version_id');
                $table->uuid('proposal_id');
                $table->uuid('proposal_version_id');

                $table->char('content_hash', 64);
                $table->uuid('created_by_membership_id');
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    [
                        'contribution_id',
                        'contribution_revision',
                        'phase',
                    ],
                    'contribution_submission_revision_phase_unique',
                );

                $table->unique(
                    ['formal_record_version_id'],
                    'contribution_submission_record_unique',
                );

                $table->unique(
                    ['proposal_version_id'],
                    'contribution_submission_proposal_unique',
                );

                $table->foreign(
                    ['contribution_id', 'business_id'],
                    'contribution_submission_parent_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('contributions')
                    ->restrictOnDelete();

                $table->foreign(
                    ['formal_record_family_id', 'business_id'],
                    'contribution_submission_family_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('formal_record_families')
                    ->restrictOnDelete();

                $table->foreign(
                    ['formal_record_version_id', 'business_id'],
                    'contribution_submission_record_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('formal_record_versions')
                    ->restrictOnDelete();

                $table->foreign(
                    ['proposal_id', 'business_id'],
                    'contribution_submission_proposal_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('proposals')
                    ->restrictOnDelete();

                $table->foreign(
                    ['proposal_version_id', 'business_id'],
                    'contribution_submission_proposal_version_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('proposal_versions')
                    ->restrictOnDelete();

                $table->foreign(
                    ['created_by_membership_id', 'business_id'],
                    'contribution_submission_member_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        DB::statement(
            "ALTER TABLE contributions
             ADD CONSTRAINT contributions_type_check
             CHECK (
                 contribution_type IN (
                     'cash',
                     'time_skill',
                     'property_asset',
                     'ip_intangible'
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE contributions
             ADD CONSTRAINT contributions_status_check
             CHECK (
                 status IN (
                     'proposed',
                     'reviewed',
                     'approved',
                     'delivered',
                     'accepted',
                     'rejected',
                     'cancelled',
                     'defaulted'
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE contributions
             ADD CONSTRAINT contributions_currency_check
             CHECK (currency ~ '^[A-Z]{3}$')",
        );

        DB::statement(
            'ALTER TABLE contributions
             ADD CONSTRAINT contributions_values_nonnegative
             CHECK (
                 proposed_value >= 0
                 AND (
                     reviewed_value IS NULL
                     OR reviewed_value >= 0
                 )
                 AND (
                     approved_value IS NULL
                     OR approved_value >= 0
                 )
                 AND (
                     accepted_value IS NULL
                     OR accepted_value >= 0
                 )
             )',
        );

        DB::statement(
            "ALTER TABLE contributions
             ADD CONSTRAINT contributions_reviewed_value_required
             CHECK (
                 status NOT IN (
                     'reviewed',
                     'approved',
                     'delivered',
                     'accepted'
                 )
                 OR reviewed_value IS NOT NULL
             )",
        );

        DB::statement(
            "ALTER TABLE contributions
             ADD CONSTRAINT contributions_approved_value_required
             CHECK (
                 status NOT IN ('approved','delivered','accepted')
                 OR (
                     approved_value IS NOT NULL
                     AND approval_decision_id IS NOT NULL
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE contributions
             ADD CONSTRAINT contributions_accepted_value_exact_state
             CHECK (
                 (
                     status = 'accepted'
                     AND accepted_value IS NOT NULL
                     AND acceptance_decision_id IS NOT NULL
                 )
                 OR
                 (
                     status <> 'accepted'
                     AND accepted_value IS NULL
                     AND acceptance_decision_id IS NULL
                 )
             )",
        );

        DB::statement(
            'ALTER TABLE contributions
             ADD CONSTRAINT contributions_revision_positive
             CHECK (revision > 0)',
        );

        DB::statement(
            'ALTER TABLE cash_contribution_details
             ADD CONSTRAINT cash_contribution_values_nonnegative
             CHECK (
                 amount_committed >= 0
                 AND amount_received >= 0
             )',
        );

        DB::statement(
            'ALTER TABLE time_skill_contribution_details
             ADD CONSTRAINT time_skill_values_positive
             CHECK (
                 hours_per_month > 0
                 AND fair_market_rate >= 0
                 AND number_of_months > 0
                 AND cash_compensation_received >= 0
             )',
        );

        DB::statement(
            'ALTER TABLE asset_contribution_details
             ADD CONSTRAINT asset_contribution_values_nonnegative
             CHECK (
                 (
                     market_value IS NULL
                     OR market_value >= 0
                 )
                 AND (
                     fair_rental_use_value IS NULL
                     OR fair_rental_use_value >= 0
                 )
             )',
        );

        DB::statement(
            'ALTER TABLE contribution_delivery_events
             ADD CONSTRAINT contribution_delivery_value_positive
             CHECK (delivered_value > 0)',
        );

        DB::statement(
            "ALTER TABLE contribution_status_transitions
             ADD CONSTRAINT contribution_transition_from_check
             CHECK (
                 from_status IS NULL
                 OR from_status IN (
                     'proposed',
                     'reviewed',
                     'approved',
                     'delivered',
                     'accepted',
                     'rejected',
                     'cancelled',
                     'defaulted'
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE contribution_status_transitions
             ADD CONSTRAINT contribution_transition_to_check
             CHECK (
                 to_status IN (
                     'proposed',
                     'reviewed',
                     'approved',
                     'delivered',
                     'accepted',
                     'rejected',
                     'cancelled',
                     'defaulted'
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE contribution_governance_submissions
             ADD CONSTRAINT contribution_submission_phase_check
             CHECK (phase IN ('approval','acceptance'))",
        );

        DB::statement(
            "ALTER TABLE contribution_governance_submissions
             ADD CONSTRAINT contribution_submission_acceptance_value_check
             CHECK (
                 (
                     phase = 'approval'
                     AND proposed_accepted_value IS NULL
                 )
                 OR
                 (
                     phase = 'acceptance'
                     AND proposed_accepted_value IS NOT NULL
                     AND proposed_accepted_value >= 0
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE contribution_governance_submissions
             ADD CONSTRAINT contribution_submission_hash_check
             CHECK (content_hash ~ '^[0-9a-f]{64}$')",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_protect_contribution_lifecycle()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Contribution history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.partner_id IS DISTINCT FROM OLD.partner_id
       OR NEW.contribution_type IS DISTINCT FROM OLD.contribution_type
    THEN
        RAISE EXCEPTION 'Contribution identity cannot be rewritten';
    END IF;

    IF OLD.status IN (
        'accepted',
        'rejected',
        'cancelled',
        'defaulted'
    ) THEN
        RAISE EXCEPTION 'Terminal Contribution history is immutable';
    END IF;

    IF NEW.revision <= OLD.revision THEN
        RAISE EXCEPTION 'Contribution revision must increase';
    END IF;

    IF NEW.status IS DISTINCT FROM OLD.status THEN
        IF NOT (
            (OLD.status = 'proposed'
                AND NEW.status IN ('reviewed','rejected','cancelled'))
            OR
            (OLD.status = 'reviewed'
                AND NEW.status IN ('approved','rejected','cancelled'))
            OR
            (OLD.status = 'approved'
                AND NEW.status IN ('delivered','cancelled','defaulted'))
            OR
            (OLD.status = 'delivered'
                AND NEW.status IN ('accepted','defaulted'))
        ) THEN
            RAISE EXCEPTION
                'Invalid Contribution lifecycle transition: % -> %',
                OLD.status,
                NEW.status;
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER contributions_protect_lifecycle
BEFORE UPDATE OR DELETE ON contributions
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_contribution_lifecycle();

CREATE OR REPLACE FUNCTION pbr_protect_contribution_append_only()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Contribution history row is append-only';
END;
$$;

CREATE TRIGGER contribution_delivery_events_immutable
BEFORE UPDATE OR DELETE ON contribution_delivery_events
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_contribution_append_only();

CREATE TRIGGER contribution_status_transitions_immutable
BEFORE UPDATE OR DELETE ON contribution_status_transitions
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_contribution_append_only();

CREATE TRIGGER contribution_governance_submissions_immutable
BEFORE UPDATE OR DELETE ON contribution_governance_submissions
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_contribution_append_only();

CREATE OR REPLACE FUNCTION pbr_touch_contribution_evidence_revision()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    target_uuid uuid;
    target_business_uuid uuid;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.target_type <> 'contribution' THEN
            RETURN NEW;
        END IF;

        target_uuid := NEW.target_id;
        target_business_uuid := NEW.business_id;

        UPDATE contributions
        SET revision = revision + 1,
            updated_at = now()
        WHERE id = target_uuid
          AND business_id = target_business_uuid;

        IF NOT FOUND THEN
            RAISE EXCEPTION
                'Contribution Evidence target does not exist';
        END IF;

        RETURN NEW;
    END IF;

    IF TG_OP = 'DELETE' THEN
        IF OLD.target_type <> 'contribution' THEN
            RETURN OLD;
        END IF;

        UPDATE contributions
        SET revision = revision + 1,
            updated_at = now()
        WHERE id = OLD.target_id
          AND business_id = OLD.business_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION
                'Contribution Evidence target does not exist';
        END IF;

        RETURN OLD;
    END IF;

    IF OLD.target_type = 'contribution' THEN
        UPDATE contributions
        SET revision = revision + 1,
            updated_at = now()
        WHERE id = OLD.target_id
          AND business_id = OLD.business_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION
                'Original Contribution Evidence target does not exist';
        END IF;
    END IF;

    IF NEW.target_type = 'contribution'
       AND (
           OLD.target_type <> 'contribution'
           OR NEW.target_id IS DISTINCT FROM OLD.target_id
           OR NEW.business_id IS DISTINCT FROM OLD.business_id
       )
    THEN
        UPDATE contributions
        SET revision = revision + 1,
            updated_at = now()
        WHERE id = NEW.target_id
          AND business_id = NEW.business_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION
                'New Contribution Evidence target does not exist';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER contribution_evidence_links_touch_revision
AFTER INSERT OR UPDATE OR DELETE ON evidence_links
FOR EACH ROW
EXECUTE FUNCTION pbr_touch_contribution_evidence_revision();

CREATE OR REPLACE FUNCTION pbr_protect_contribution_detail_history()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    contribution_state text;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION
            'Contribution detail row cannot be deleted';
    END IF;

    IF NEW.contribution_id IS DISTINCT FROM OLD.contribution_id
       OR NEW.business_id IS DISTINCT FROM OLD.business_id
    THEN
        RAISE EXCEPTION
            'Contribution detail identity cannot be rewritten';
    END IF;

    SELECT status
    INTO contribution_state
    FROM contributions
    WHERE id = NEW.contribution_id
      AND business_id = NEW.business_id
    FOR UPDATE;

    IF contribution_state IS NULL THEN
        RAISE EXCEPTION
            'Contribution detail parent does not exist';
    END IF;

    IF contribution_state IN (
        'accepted',
        'rejected',
        'cancelled',
        'defaulted'
    ) THEN
        RAISE EXCEPTION
            'Terminal Contribution detail history is immutable';
    END IF;

    UPDATE contributions
    SET revision = revision + 1,
        updated_at = now()
    WHERE id = NEW.contribution_id
      AND business_id = NEW.business_id;

    RETURN NEW;
END;
$$;

CREATE TRIGGER cash_contribution_details_protect_history
BEFORE UPDATE OR DELETE ON cash_contribution_details
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_contribution_detail_history();

CREATE TRIGGER time_skill_contribution_details_protect_history
BEFORE UPDATE OR DELETE ON time_skill_contribution_details
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_contribution_detail_history();

CREATE TRIGGER asset_contribution_details_protect_history
BEFORE UPDATE OR DELETE ON asset_contribution_details
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_contribution_detail_history();

CREATE TRIGGER intangible_contribution_details_protect_history
BEFORE UPDATE OR DELETE ON intangible_contribution_details
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_contribution_detail_history();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS contribution_evidence_links_touch_revision
             ON evidence_links;
             DROP FUNCTION IF EXISTS
             pbr_touch_contribution_evidence_revision();',
        );

        /*
         * Drop tables before their shared trigger functions.
         *
         * PostgreSQL trigger objects depend on these functions. Dropping the
         * owning tables first removes those triggers explicitly through table
         * teardown, avoiding CASCADE and preserving predictable rollback scope.
         */
        Schema::dropIfExists('contribution_governance_submissions');
        Schema::dropIfExists('contribution_status_transitions');
        Schema::dropIfExists('contribution_delivery_events');
        Schema::dropIfExists('intangible_contribution_details');
        Schema::dropIfExists('asset_contribution_details');
        Schema::dropIfExists('time_skill_contribution_details');
        Schema::dropIfExists('cash_contribution_details');
        Schema::dropIfExists('contributions');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_contribution_detail_history();',
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_contribution_append_only();',
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_contribution_lifecycle();',
        );
    }
};
