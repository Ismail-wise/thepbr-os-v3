<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Fixed-precision and lifecycle invariants.
         */
        DB::statement(<<<'SQL'
ALTER TABLE ownership_scenarios
ADD CONSTRAINT ownership_scenarios_currency_check
CHECK (currency ~ '^[A-Z]{3}$')
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE ownership_scenarios
ADD CONSTRAINT ownership_scenarios_capacity_check
CHECK (
    share_value_minor_units > 0
    AND authorized_shares >= 0
    AND reserved_unissued_shares >= 0
    AND reserved_unissued_shares <= authorized_shares
)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE ownership_scenarios
ADD CONSTRAINT ownership_scenarios_status_check
CHECK (status IN ('draft','frozen','proposed','retired'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE ownership_scenario_share_classes
ADD CONSTRAINT ownership_scenario_share_classes_rights_check
CHECK (
    voting_right_per_share >= 0
    AND profit_right_per_share >= 0
)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE ownership_scenario_positions
ADD CONSTRAINT ownership_scenario_positions_quantity_check
CHECK (
    shares_issued >= 0
    AND shares_vested >= 0
    AND shares_vested <= shares_issued
    AND voting_rights >= 0
    AND profit_rights >= 0
    AND (
        vesting_period_months IS NULL
        OR vesting_cliff_months IS NULL
        OR vesting_cliff_months <= vesting_period_months
    )
)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE ownership_register_versions
ADD CONSTRAINT ownership_register_versions_currency_check
CHECK (currency ~ '^[A-Z]{3}$')
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE ownership_register_versions
ADD CONSTRAINT ownership_register_versions_capacity_check
CHECK (
    share_value_minor_units > 0
    AND authorized_shares >= 0
    AND issued_shares >= 0
    AND reserved_unissued_shares >= 0
    AND available_shares >= 0
    AND issued_shares + reserved_unissued_shares <= authorized_shares
    AND available_shares =
        authorized_shares - issued_shares - reserved_unissued_shares
)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE ownership_register_versions
ADD CONSTRAINT ownership_register_versions_status_check
CHECK (
    status IN (
        'pending_effect',
        'effective',
        'superseded',
        'archived'
    )
)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE ownership_register_versions
ADD CONSTRAINT ownership_register_versions_effective_date_check
CHECK (
    status = 'pending_effect'
    OR effective_from IS NOT NULL
)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE ownership_register_share_classes
ADD CONSTRAINT ownership_register_share_classes_rights_check
CHECK (
    voting_right_per_share >= 0
    AND profit_right_per_share >= 0
)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE ownership_register_positions
ADD CONSTRAINT ownership_register_positions_quantity_check
CHECK (
    shares_issued >= 0
    AND shares_vested >= 0
    AND shares_vested <= shares_issued
    AND voting_rights >= 0
    AND profit_rights >= 0
    AND (
        vesting_period_months IS NULL
        OR vesting_cliff_months IS NULL
        OR vesting_cliff_months <= vesting_period_months
    )
)
SQL);

        /*
         * Scenario position references must all belong to the same Business
         * and the Share Class must belong to the same Scenario.
         */
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_f5_scenario_position_reference_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    scenario_business uuid;
    partner_business uuid;
    class_business uuid;
    class_scenario uuid;
BEGIN
    SELECT business_id
      INTO scenario_business
      FROM ownership_scenarios
     WHERE id = NEW.ownership_scenario_id;

    SELECT business_id
      INTO partner_business
      FROM partners
     WHERE id = NEW.partner_id;

    SELECT business_id, ownership_scenario_id
      INTO class_business, class_scenario
      FROM ownership_scenario_share_classes
     WHERE id = NEW.share_class_id;

    IF scenario_business IS NULL
       OR partner_business IS NULL
       OR class_business IS NULL THEN
        RAISE EXCEPTION
            'Ownership Scenario position reference does not exist';
    END IF;

    IF NEW.business_id <> scenario_business
       OR NEW.business_id <> partner_business
       OR NEW.business_id <> class_business
       OR NEW.ownership_scenario_id <> class_scenario THEN
        RAISE EXCEPTION
            'Ownership Scenario position Partner/Share Class mismatch';
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER ownership_scenario_positions_reference_guard
BEFORE INSERT OR UPDATE ON ownership_scenario_positions
FOR EACH ROW
EXECUTE FUNCTION thepbr_f5_scenario_position_reference_guard()
SQL);

        /*
         * Register Version identity and governance binding.
         *
         * Pending Effect may exist before the governance identifiers are
         * attached, but governance identifiers are all-or-none.
         *
         * Effective/Superseded/Archived truth must bind the exact:
         * Proposal Version + Decision + Authority Snapshot.
         */
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_f5_register_version_binding_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    register_business uuid;
    scenario_business uuid;
    scenario_status text;

    proposal_business uuid;

    decision_business uuid;
    decision_proposal uuid;
    decision_snapshot uuid;

    snapshot_business uuid;
    snapshot_proposal uuid;

    binding_count integer;
BEGIN
    SELECT business_id
      INTO register_business
      FROM ownership_registers
     WHERE id = NEW.ownership_register_id;

    SELECT business_id, status
      INTO scenario_business, scenario_status
      FROM ownership_scenarios
     WHERE id = NEW.source_ownership_scenario_id;

    IF register_business IS NULL
       OR scenario_business IS NULL THEN
        RAISE EXCEPTION
            'Ownership Register source identity does not exist';
    END IF;

    IF NEW.business_id <> register_business
       OR NEW.business_id <> scenario_business THEN
        RAISE EXCEPTION
            'Ownership Register Version Business mismatch';
    END IF;

    IF scenario_status <> 'frozen' THEN
        RAISE EXCEPTION
            'Only Frozen Ownership Scenario may feed a Register Version';
    END IF;

    binding_count :=
        (CASE WHEN NEW.proposal_version_id IS NULL THEN 0 ELSE 1 END)
        +
        (CASE WHEN NEW.governance_decision_id IS NULL THEN 0 ELSE 1 END)
        +
        (CASE WHEN NEW.authority_snapshot_id IS NULL THEN 0 ELSE 1 END);

    IF binding_count NOT IN (0, 3) THEN
        RAISE EXCEPTION
            'Ownership governance binding must be complete or empty';
    END IF;

    IF binding_count = 3 THEN
        SELECT business_id
          INTO proposal_business
          FROM proposal_versions
         WHERE id = NEW.proposal_version_id;

        SELECT business_id,
               proposal_version_id,
               authority_snapshot_id
          INTO decision_business,
               decision_proposal,
               decision_snapshot
          FROM decisions
         WHERE id = NEW.governance_decision_id;

        SELECT business_id,
               proposal_version_id
          INTO snapshot_business,
               snapshot_proposal
          FROM authority_snapshots
         WHERE id = NEW.authority_snapshot_id;

        IF proposal_business IS NULL
           OR decision_business IS NULL
           OR snapshot_business IS NULL THEN
            RAISE EXCEPTION
                'Ownership governance binding reference does not exist';
        END IF;

        IF proposal_business <> NEW.business_id
           OR decision_business <> NEW.business_id
           OR snapshot_business <> NEW.business_id
           OR decision_proposal <> NEW.proposal_version_id
           OR decision_snapshot <> NEW.authority_snapshot_id
           OR snapshot_proposal <> NEW.proposal_version_id THEN
            RAISE EXCEPTION
                'Ownership governance binding mismatch';
        END IF;
    END IF;

    IF NEW.status IN ('effective', 'superseded', 'archived')
       AND (
           binding_count <> 3
           OR NEW.approved_at IS NULL
           OR NEW.effective_from IS NULL
       ) THEN
        RAISE EXCEPTION
            'Governance binding is required before Ownership Register effectivity';
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER ownership_register_versions_binding_guard
BEFORE INSERT OR UPDATE ON ownership_register_versions
FOR EACH ROW
EXECUTE FUNCTION thepbr_f5_register_version_binding_guard()
SQL);

        /*
         * Register snapshots may only be assembled while their parent Version
         * is Pending Effect. Once Effective, no late child INSERT is allowed.
         */
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_f5_register_share_class_insert_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    parent_business uuid;
    parent_status text;
BEGIN
    SELECT business_id, status
      INTO parent_business, parent_status
      FROM ownership_register_versions
     WHERE id = NEW.ownership_register_version_id;

    IF parent_business IS NULL THEN
        RAISE EXCEPTION
            'Ownership Register Version parent does not exist';
    END IF;

    IF NEW.business_id <> parent_business THEN
        RAISE EXCEPTION
            'Ownership Register Share Class Business mismatch';
    END IF;

    IF parent_status <> 'pending_effect' THEN
        RAISE EXCEPTION
            'Effective Ownership Register snapshot cannot accept new content';
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER ownership_register_share_classes_insert_guard
BEFORE INSERT ON ownership_register_share_classes
FOR EACH ROW
EXECUTE FUNCTION thepbr_f5_register_share_class_insert_guard()
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_f5_register_position_insert_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    parent_business uuid;
    parent_status text;
    partner_business uuid;
    class_business uuid;
    class_version uuid;
BEGIN
    SELECT business_id, status
      INTO parent_business, parent_status
      FROM ownership_register_versions
     WHERE id = NEW.ownership_register_version_id;

    SELECT business_id
      INTO partner_business
      FROM partners
     WHERE id = NEW.partner_id;

    SELECT business_id, ownership_register_version_id
      INTO class_business, class_version
      FROM ownership_register_share_classes
     WHERE id = NEW.share_class_id;

    IF parent_business IS NULL
       OR partner_business IS NULL
       OR class_business IS NULL THEN
        RAISE EXCEPTION
            'Ownership Register position reference does not exist';
    END IF;

    IF parent_status <> 'pending_effect' THEN
        RAISE EXCEPTION
            'Effective Ownership Register snapshot cannot accept new content';
    END IF;

    IF NEW.business_id <> parent_business
       OR NEW.business_id <> partner_business
       OR NEW.business_id <> class_business
       OR NEW.ownership_register_version_id <> class_version THEN
        RAISE EXCEPTION
            'Ownership Register position Partner/Share Class mismatch';
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER ownership_register_positions_insert_guard
BEFORE INSERT ON ownership_register_positions
FOR EACH ROW
EXECUTE FUNCTION thepbr_f5_register_position_insert_guard()
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_f5_register_source_insert_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    parent_business uuid;
    parent_status text;
BEGIN
    SELECT business_id, status
      INTO parent_business, parent_status
      FROM ownership_register_versions
     WHERE id = NEW.ownership_register_version_id;

    IF parent_business IS NULL THEN
        RAISE EXCEPTION
            'Ownership Register Version parent does not exist';
    END IF;

    IF NEW.business_id <> parent_business THEN
        RAISE EXCEPTION
            'Ownership Register Contribution source Business mismatch';
    END IF;

    IF parent_status <> 'pending_effect' THEN
        RAISE EXCEPTION
            'Effective Ownership Register snapshot cannot accept new content';
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER ownership_register_contribution_sources_insert_guard
BEFORE INSERT ON ownership_register_contribution_sources
FOR EACH ROW
EXECUTE FUNCTION thepbr_f5_register_source_insert_guard()
SQL);
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS
             ownership_register_contribution_sources_insert_guard
             ON ownership_register_contribution_sources',
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS
             thepbr_f5_register_source_insert_guard()',
        );

        DB::statement(
            'DROP TRIGGER IF EXISTS
             ownership_register_positions_insert_guard
             ON ownership_register_positions',
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS
             thepbr_f5_register_position_insert_guard()',
        );

        DB::statement(
            'DROP TRIGGER IF EXISTS
             ownership_register_share_classes_insert_guard
             ON ownership_register_share_classes',
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS
             thepbr_f5_register_share_class_insert_guard()',
        );

        DB::statement(
            'DROP TRIGGER IF EXISTS
             ownership_register_versions_binding_guard
             ON ownership_register_versions',
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS
             thepbr_f5_register_version_binding_guard()',
        );

        DB::statement(
            'DROP TRIGGER IF EXISTS
             ownership_scenario_positions_reference_guard
             ON ownership_scenario_positions',
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS
             thepbr_f5_scenario_position_reference_guard()',
        );

        foreach ([
            [
                'ownership_register_positions',
                'ownership_register_positions_quantity_check',
            ],
            [
                'ownership_register_share_classes',
                'ownership_register_share_classes_rights_check',
            ],
            [
                'ownership_register_versions',
                'ownership_register_versions_effective_date_check',
            ],
            [
                'ownership_register_versions',
                'ownership_register_versions_status_check',
            ],
            [
                'ownership_register_versions',
                'ownership_register_versions_capacity_check',
            ],
            [
                'ownership_register_versions',
                'ownership_register_versions_currency_check',
            ],
            [
                'ownership_scenario_positions',
                'ownership_scenario_positions_quantity_check',
            ],
            [
                'ownership_scenario_share_classes',
                'ownership_scenario_share_classes_rights_check',
            ],
            [
                'ownership_scenarios',
                'ownership_scenarios_status_check',
            ],
            [
                'ownership_scenarios',
                'ownership_scenarios_capacity_check',
            ],
            [
                'ownership_scenarios',
                'ownership_scenarios_currency_check',
            ],
        ] as [$table, $constraint]) {
            DB::statement(sprintf(
                'ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s',
                $table,
                $constraint,
            ));
        }
    }
};
