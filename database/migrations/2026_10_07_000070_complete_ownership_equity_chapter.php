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
        Schema::table('ownership_scenarios', function (Blueprint $table): void {
            $table->char('source_accepted_register_hash', 64)->nullable();
            $table->uuid('source_contribution_decision_record_id')->nullable();
            $table->uuid('source_contribution_setup_id')->nullable();
            $table->unsignedBigInteger('source_contribution_setup_revision')->nullable();
            $table->timestampTz('share_rights_reviewed_at')->nullable();
            $table->timestampTz('capacity_reviewed_at')->nullable();

            $table->foreign('source_contribution_decision_record_id', 'ownership_scenarios_source_decision_fk')
                ->references('id')->on('contribution_decision_records')->restrictOnDelete();
            $table->foreign('source_contribution_setup_id', 'ownership_scenarios_source_setup_fk')
                ->references('id')->on('contribution_setups')->restrictOnDelete();
        });

        Schema::table('ownership_scenario_positions', function (Blueprint $table): void {
            $table->boolean('vesting_applies')->nullable();
        });

        Schema::table('ownership_register_versions', function (Blueprint $table): void {
            $table->char('source_accepted_register_hash', 64)->nullable();
            $table->uuid('source_contribution_decision_record_id')->nullable();

            $table->foreign('source_contribution_decision_record_id', 'ownership_register_versions_source_decision_fk')
                ->references('id')->on('contribution_decision_records')->restrictOnDelete();
        });

        Schema::table('ownership_register_positions', function (Blueprint $table): void {
            $table->boolean('vesting_applies')->nullable();
        });

        Schema::create('ownership_scenario_issuance_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('ownership_scenario_id');
            $table->string('approval_rule', 500);
            $table->decimal('approval_threshold_percent', 5, 2);
            $table->boolean('preemption_right');
            $table->string('valuation_method', 500);
            $table->boolean('dilution_acknowledged');
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'ownership_scenario_issuance_rules_id_business_uq');
            $table->unique('ownership_scenario_id', 'ownership_scenario_issuance_rules_scenario_uq');
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('ownership_scenario_id')->references('id')->on('ownership_scenarios')->restrictOnDelete();
        });

        Schema::create('ownership_register_issuance_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('ownership_register_version_id');
            $table->string('approval_rule', 500);
            $table->decimal('approval_threshold_percent', 5, 2);
            $table->boolean('preemption_right');
            $table->string('valuation_method', 500);
            $table->boolean('dilution_acknowledged');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'ownership_register_issuance_rules_id_business_uq');
            $table->unique('ownership_register_version_id', 'ownership_register_issuance_rules_version_uq');
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('ownership_register_version_id')->references('id')->on('ownership_register_versions')->restrictOnDelete();
        });

        Schema::create('ownership_decision_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('contract_version', 80);
            $table->uuid('ownership_register_version_id');
            $table->uuid('source_ownership_scenario_id');
            $table->uuid('proposal_version_id');
            $table->uuid('governance_decision_id');
            $table->uuid('authority_snapshot_id');
            $table->uuid('formal_record_version_id');
            $table->char('content_hash', 64);
            $table->char('source_accepted_register_hash', 64)->nullable();
            $table->uuid('decision_owner_membership_id');
            $table->date('review_date');
            $table->text('decision_summary');
            $table->jsonb('evidence_references')->default(DB::raw("'[]'::jsonb"));
            $table->uuid('created_by_membership_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'ownership_decision_records_id_business_uq');
            $table->unique('ownership_register_version_id', 'ownership_decision_records_register_uq');
            $table->index(['business_id', 'created_at'], 'ownership_decision_records_business_created_ix');

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('ownership_register_version_id')->references('id')->on('ownership_register_versions')->restrictOnDelete();
            $table->foreign('source_ownership_scenario_id')->references('id')->on('ownership_scenarios')->restrictOnDelete();
            $table->foreign('proposal_version_id')->references('id')->on('proposal_versions')->restrictOnDelete();
            $table->foreign('governance_decision_id')->references('id')->on('decisions')->restrictOnDelete();
            $table->foreign('authority_snapshot_id')->references('id')->on('authority_snapshots')->restrictOnDelete();
            $table->foreign('formal_record_version_id')->references('id')->on('formal_record_versions')->restrictOnDelete();
            $table->foreign('decision_owner_membership_id')->references('id')->on('memberships')->restrictOnDelete();
            $table->foreign('created_by_membership_id')->references('id')->on('memberships')->restrictOnDelete();
        });

        Schema::create('ownership_action_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('ownership_decision_record_id');
            $table->uuid('action_id');
            $table->string('suggestion_key', 120)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'ownership_action_links_id_business_uq');
            $table->unique('action_id', 'ownership_action_links_action_uq');
            $table->index(['business_id', 'ownership_decision_record_id'], 'ownership_action_links_record_ix');

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('ownership_decision_record_id')->references('id')->on('ownership_decision_records')->restrictOnDelete();
            $table->foreign('action_id')->references('id')->on('actions')->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE ownership_scenarios
             ADD CONSTRAINT ownership_scenarios_source_hash_ck
             CHECK (
                source_accepted_register_hash IS NULL
                OR source_accepted_register_hash ~ '^[0-9a-f]{64}$'
             )",
        );

        DB::statement(
            "ALTER TABLE ownership_register_versions
             ADD CONSTRAINT ownership_register_versions_source_hash_ck
             CHECK (
                source_accepted_register_hash IS NULL
                OR source_accepted_register_hash ~ '^[0-9a-f]{64}$'
             )",
        );

        foreach ([
            'ownership_scenario_issuance_rules',
            'ownership_register_issuance_rules',
        ] as $table) {
            DB::statement(sprintf(
                'ALTER TABLE %s
                 ADD CONSTRAINT %s_threshold_ck
                 CHECK (
                    approval_threshold_percent > 0
                    AND approval_threshold_percent <= 100
                 )',
                $table,
                $table,
            ));
        }

        DB::statement(
            "ALTER TABLE ownership_decision_records
             ADD CONSTRAINT ownership_decision_records_hash_ck
             CHECK (content_hash ~ '^[0-9a-f]{64}$')",
        );
        DB::statement(
            "ALTER TABLE ownership_decision_records
             ADD CONSTRAINT ownership_decision_records_source_hash_ck
             CHECK (
                source_accepted_register_hash IS NULL
                OR source_accepted_register_hash ~ '^[0-9a-f]{64}$'
             )",
        );
        DB::statement(
            "ALTER TABLE ownership_decision_records
             ADD CONSTRAINT ownership_decision_records_summary_ck
             CHECK (btrim(decision_summary) <> '')",
        );
        DB::statement(
            "ALTER TABLE ownership_decision_records
             ADD CONSTRAINT ownership_decision_records_evidence_ck
             CHECK (jsonb_typeof(evidence_references) = 'array')",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_c3_ownership_scenario_source_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    source_business uuid;
    source_hash text;
    source_setup uuid;
    source_setup_revision bigint;
BEGIN
    IF TG_OP = 'UPDATE' AND (
        OLD.source_accepted_register_hash IS DISTINCT FROM NEW.source_accepted_register_hash
        OR OLD.source_contribution_decision_record_id IS DISTINCT FROM NEW.source_contribution_decision_record_id
        OR OLD.source_contribution_setup_id IS DISTINCT FROM NEW.source_contribution_setup_id
        OR OLD.source_contribution_setup_revision IS DISTINCT FROM NEW.source_contribution_setup_revision
    ) THEN
        RAISE EXCEPTION 'Ownership Scenario Contribution provenance is immutable';
    END IF;

    IF NEW.source_accepted_register_hash IS NULL THEN
        IF NEW.source_contribution_decision_record_id IS NOT NULL
           OR NEW.source_contribution_setup_id IS NOT NULL
           OR NEW.source_contribution_setup_revision IS NOT NULL THEN
            RAISE EXCEPTION 'Ownership Scenario source binding must be complete';
        END IF;
        RETURN NEW;
    END IF;

    IF NEW.source_contribution_decision_record_id IS NULL
       OR NEW.source_contribution_setup_id IS NULL
       OR NEW.source_contribution_setup_revision IS NULL THEN
        RAISE EXCEPTION 'Ownership Scenario source binding must be complete';
    END IF;

    SELECT business_id,
           accepted_register_hash,
           contribution_setup_id,
           contribution_setup_revision
      INTO source_business,
           source_hash,
           source_setup,
           source_setup_revision
      FROM contribution_decision_records
     WHERE id = NEW.source_contribution_decision_record_id;

    IF source_business IS NULL
       OR source_business <> NEW.business_id
       OR source_hash <> NEW.source_accepted_register_hash
       OR source_setup <> NEW.source_contribution_setup_id
       OR source_setup_revision <> NEW.source_contribution_setup_revision THEN
        RAISE EXCEPTION 'Ownership Scenario source binding does not match the current Contribution Decision';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER ownership_scenarios_chapter3_source_guard
BEFORE INSERT OR UPDATE ON ownership_scenarios
FOR EACH ROW
EXECUTE FUNCTION thepbr_c3_ownership_scenario_source_guard();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_c3_scenario_issuance_rule_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    parent_business uuid;
    parent_status text;
BEGIN
    SELECT business_id, status
      INTO parent_business, parent_status
      FROM ownership_scenarios
     WHERE id = COALESCE(NEW.ownership_scenario_id, OLD.ownership_scenario_id);

    IF parent_business IS NULL THEN
        RAISE EXCEPTION 'Ownership Scenario for issuance rule does not exist';
    END IF;

    IF TG_OP <> 'DELETE' AND NEW.business_id <> parent_business THEN
        RAISE EXCEPTION 'Ownership issuance rule Business mismatch';
    END IF;

    IF parent_status <> 'draft' THEN
        RAISE EXCEPTION 'Only Draft Ownership Scenario issuance rule may change';
    END IF;

    IF TG_OP = 'UPDATE' AND (
        OLD.business_id IS DISTINCT FROM NEW.business_id
        OR OLD.ownership_scenario_id IS DISTINCT FROM NEW.ownership_scenario_id
    ) THEN
        RAISE EXCEPTION 'Ownership issuance rule source binding is immutable';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;

CREATE TRIGGER ownership_scenario_issuance_rules_guard
BEFORE INSERT OR UPDATE OR DELETE ON ownership_scenario_issuance_rules
FOR EACH ROW
EXECUTE FUNCTION thepbr_c3_scenario_issuance_rule_guard();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_c3_register_issuance_rule_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    parent_business uuid;
    parent_status text;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Ownership Register issuance rule snapshot is immutable';
    END IF;

    SELECT business_id, status
      INTO parent_business, parent_status
      FROM ownership_register_versions
     WHERE id = NEW.ownership_register_version_id;

    IF parent_business IS NULL
       OR parent_business <> NEW.business_id THEN
        RAISE EXCEPTION 'Ownership Register issuance rule Business mismatch';
    END IF;

    IF parent_status <> 'pending_effect' THEN
        RAISE EXCEPTION 'Ownership Register issuance rule may only be captured before effect';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER ownership_register_issuance_rules_guard
BEFORE INSERT OR UPDATE OR DELETE ON ownership_register_issuance_rules
FOR EACH ROW
EXECUTE FUNCTION thepbr_c3_register_issuance_rule_guard();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_c3_register_provenance_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    source_business uuid;
    source_hash text;
BEGIN
    IF TG_OP = 'UPDATE' AND (
        OLD.source_accepted_register_hash IS DISTINCT FROM NEW.source_accepted_register_hash
        OR OLD.source_contribution_decision_record_id IS DISTINCT FROM NEW.source_contribution_decision_record_id
    ) THEN
        RAISE EXCEPTION 'Ownership Register source provenance is immutable';
    END IF;

    IF NEW.source_contribution_decision_record_id IS NOT NULL THEN
        SELECT business_id, accepted_register_hash
          INTO source_business, source_hash
          FROM contribution_decision_records
         WHERE id = NEW.source_contribution_decision_record_id;

        IF source_business IS NULL
           OR source_business <> NEW.business_id
           OR source_hash IS DISTINCT FROM NEW.source_accepted_register_hash THEN
            RAISE EXCEPTION 'Ownership Register Contribution source binding mismatch';
        END IF;
    ELSIF NEW.source_accepted_register_hash IS NOT NULL THEN
        RAISE EXCEPTION 'Ownership Register source Decision binding is required';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER ownership_register_versions_chapter3_source_guard
BEFORE INSERT OR UPDATE ON ownership_register_versions
FOR EACH ROW
EXECUTE FUNCTION thepbr_c3_register_provenance_guard();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_c3_ownership_decision_record_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    register_business uuid;
    register_status text;
    register_scenario uuid;
    register_proposal uuid;
    register_decision uuid;
    register_snapshot uuid;
    register_source_hash text;
    submission_record uuid;
    submission_hash text;
    decision_business uuid;
    decision_status text;
    decision_outcome text;
    decision_snapshot uuid;
    owner_business uuid;
    owner_status text;
    creator_business uuid;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Ownership Decision Record is immutable';
    END IF;

    SELECT business_id,
           status,
           source_ownership_scenario_id,
           proposal_version_id,
           governance_decision_id,
           authority_snapshot_id,
           source_accepted_register_hash
      INTO register_business,
           register_status,
           register_scenario,
           register_proposal,
           register_decision,
           register_snapshot,
           register_source_hash
      FROM ownership_register_versions
     WHERE id = NEW.ownership_register_version_id;

    SELECT formal_record_version_id, content_hash
      INTO submission_record, submission_hash
      FROM ownership_governance_submissions
     WHERE business_id = NEW.business_id
       AND ownership_scenario_id = NEW.source_ownership_scenario_id
       AND proposal_version_id = NEW.proposal_version_id;

    SELECT business_id, status, outcome, authority_snapshot_id
      INTO decision_business, decision_status, decision_outcome, decision_snapshot
      FROM decisions
     WHERE id = NEW.governance_decision_id;

    SELECT business_id, access_status
      INTO owner_business, owner_status
      FROM memberships
     WHERE id = NEW.decision_owner_membership_id;

    SELECT business_id
      INTO creator_business
      FROM memberships
     WHERE id = NEW.created_by_membership_id;

    IF register_business IS NULL
       OR register_business <> NEW.business_id
       OR register_status <> 'effective'
       OR register_scenario <> NEW.source_ownership_scenario_id
       OR register_proposal <> NEW.proposal_version_id
       OR register_decision <> NEW.governance_decision_id
       OR register_snapshot <> NEW.authority_snapshot_id
       OR submission_record <> NEW.formal_record_version_id
       OR submission_hash <> NEW.content_hash
       OR decision_business <> NEW.business_id
       OR decision_status <> 'decided'
       OR decision_outcome <> 'approved'
       OR decision_snapshot <> NEW.authority_snapshot_id
       OR owner_business <> NEW.business_id
       OR owner_status <> 'active'
       OR creator_business <> NEW.business_id
       OR register_source_hash IS DISTINCT FROM NEW.source_accepted_register_hash THEN
        RAISE EXCEPTION 'Ownership Decision Record source binding mismatch';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER ownership_decision_records_guard
BEFORE INSERT OR UPDATE OR DELETE ON ownership_decision_records
FOR EACH ROW
EXECUTE FUNCTION thepbr_c3_ownership_decision_record_guard();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_c3_ownership_action_link_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    record_business uuid;
    record_decision uuid;
    record_formal uuid;
    action_business uuid;
    action_decision uuid;
    action_formal uuid;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Ownership Action link history is immutable';
    END IF;

    SELECT business_id, governance_decision_id, formal_record_version_id
      INTO record_business, record_decision, record_formal
      FROM ownership_decision_records
     WHERE id = NEW.ownership_decision_record_id;

    SELECT business_id, decision_id, formal_record_version_id
      INTO action_business, action_decision, action_formal
      FROM actions
     WHERE id = NEW.action_id;

    IF record_business IS NULL
       OR action_business IS NULL
       OR record_business <> NEW.business_id
       OR action_business <> NEW.business_id
       OR action_decision IS DISTINCT FROM record_decision
       OR action_formal IS DISTINCT FROM record_formal THEN
        RAISE EXCEPTION 'Ownership Action link source binding mismatch';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER ownership_action_links_guard
BEFORE INSERT OR UPDATE OR DELETE ON ownership_action_links
FOR EACH ROW
EXECUTE FUNCTION thepbr_c3_ownership_action_link_guard();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS ownership_action_links_guard ON ownership_action_links');
        DB::statement('DROP FUNCTION IF EXISTS thepbr_c3_ownership_action_link_guard()');
        DB::statement('DROP TRIGGER IF EXISTS ownership_decision_records_guard ON ownership_decision_records');
        DB::statement('DROP FUNCTION IF EXISTS thepbr_c3_ownership_decision_record_guard()');
        DB::statement('DROP TRIGGER IF EXISTS ownership_register_versions_chapter3_source_guard ON ownership_register_versions');
        DB::statement('DROP FUNCTION IF EXISTS thepbr_c3_register_provenance_guard()');
        DB::statement('DROP TRIGGER IF EXISTS ownership_register_issuance_rules_guard ON ownership_register_issuance_rules');
        DB::statement('DROP FUNCTION IF EXISTS thepbr_c3_register_issuance_rule_guard()');
        DB::statement('DROP TRIGGER IF EXISTS ownership_scenario_issuance_rules_guard ON ownership_scenario_issuance_rules');
        DB::statement('DROP FUNCTION IF EXISTS thepbr_c3_scenario_issuance_rule_guard()');
        DB::statement('DROP TRIGGER IF EXISTS ownership_scenarios_chapter3_source_guard ON ownership_scenarios');
        DB::statement('DROP FUNCTION IF EXISTS thepbr_c3_ownership_scenario_source_guard()');

        Schema::dropIfExists('ownership_action_links');
        Schema::dropIfExists('ownership_decision_records');
        Schema::dropIfExists('ownership_register_issuance_rules');
        Schema::dropIfExists('ownership_scenario_issuance_rules');

        Schema::table('ownership_register_positions', function (Blueprint $table): void {
            $table->dropColumn('vesting_applies');
        });

        Schema::table('ownership_register_versions', function (Blueprint $table): void {
            $table->dropForeign('ownership_register_versions_source_decision_fk');
            $table->dropColumn([
                'source_accepted_register_hash',
                'source_contribution_decision_record_id',
            ]);
        });

        Schema::table('ownership_scenario_positions', function (Blueprint $table): void {
            $table->dropColumn('vesting_applies');
        });

        Schema::table('ownership_scenarios', function (Blueprint $table): void {
            $table->dropForeign('ownership_scenarios_source_decision_fk');
            $table->dropForeign('ownership_scenarios_source_setup_fk');
            $table->dropColumn([
                'source_accepted_register_hash',
                'source_contribution_decision_record_id',
                'source_contribution_setup_id',
                'source_contribution_setup_revision',
                'share_rights_reviewed_at',
                'capacity_reviewed_at',
            ]);
        });
    }
};
