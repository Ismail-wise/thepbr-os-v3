<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private array $capabilities = [
        'exit.view',
        'exit.manage',
    ];

    public function up(): void
    {
        $this->backfillCapabilities();
        $this->extendMembershipAccessLifecycle();
        $this->addOwnershipScenarioTenantKey();
        $this->createExitTables();
        $this->addChecks();
        $this->addGuards();
    }

    private function addOwnershipScenarioTenantKey(): void
    {
        Schema::table('ownership_scenarios', function (Blueprint $table): void {
            $table->unique(
                ['id', 'business_id'],
                'ownership_scenarios_id_business_unique',
            );
        });
    }

    private function extendMembershipAccessLifecycle(): void
    {
        DB::statement(
            'ALTER TABLE memberships DROP CONSTRAINT memberships_access_status_check'
        );

        DB::statement(<<<'SQL'
ALTER TABLE memberships
ADD CONSTRAINT memberships_access_status_check
CHECK (access_status IN ('active','suspended','revoked'))
SQL);

        Schema::create('membership_access_transitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('membership_id');
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->string('reason_code', 80);
            $table->string('source_type', 80)->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('actor_membership_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['id', 'business_id'],
                'membership_access_transition_id_biz_uq',
            );
            $table->index(
                ['business_id', 'membership_id', 'occurred_at'],
                'membership_access_transition_member_time_idx',
            );

            $table->foreign(
                ['membership_id', 'business_id'],
                'membership_access_transition_member_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign(
                ['actor_membership_id', 'business_id'],
                'membership_access_transition_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });
    }

    private function createExitTables(): void
    {
        Schema::create('exit_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('case_number', 40);
            $table->uuid('partner_id');
            $table->uuid('membership_id')->nullable();
            $table->string('trigger', 48);
            $table->text('trigger_detail')->nullable();

            $table->date('notice_date')->nullable();
            $table->date('intended_exit_date')->nullable();
            $table->unsignedInteger('required_notice_days')->nullable();
            $table->text('notice_summary')->nullable();

            $table->uuid('source_ownership_register_version_id')->nullable();
            $table->string('share_treatment', 48)->nullable();
            $table->uuid('buyer_partner_id')->nullable();
            $table->uuid('partner_change_case_id')->nullable();
            $table->uuid('ownership_scenario_id')->nullable();

            $table->string('valuation_method', 160)->nullable();
            $table->unsignedBigInteger('approved_business_value_minor_units')->nullable();
            $table->bigInteger('leaver_adjustment_minor_units')->nullable();
            $table->unsignedBigInteger('final_buyout_value_minor_units')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('leaver_classification', 32)->nullable();
            $table->text('leaver_rule_reference')->nullable();

            $table->unsignedBigInteger('payment_total_minor_units')->nullable();
            $table->text('payment_terms_summary')->nullable();
            $table->unsignedBigInteger('deposit_minor_units')->nullable();
            $table->unsignedBigInteger('installment_minor_units')->nullable();
            $table->unsignedInteger('installment_count')->nullable();
            $table->string('payment_frequency', 40)->nullable();
            $table->date('first_payment_date')->nullable();
            $table->date('final_payment_date')->nullable();
            $table->text('interest_terms')->nullable();
            $table->text('security_terms')->nullable();
            $table->text('late_payment_rule')->nullable();
            $table->string('affordability_status', 32)->default('pending');
            $table->text('alternative_payment_structure')->nullable();

            $table->string('governance_decision_type', 160);
            $table->timestampTz('effective_from')->nullable();
            $table->string('settlement_status', 24)->default('pending');
            $table->timestampTz('settled_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('status', 32)->default('draft');
            $table->uuid('created_by_membership_id');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'exit_cases_id_biz_uq');
            $table->unique(
                ['business_id', 'case_number'],
                'exit_cases_number_biz_uq',
            );
            $table->index(
                ['business_id', 'partner_id', 'status'],
                'exit_cases_partner_status_idx',
            );

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table->foreign(
                ['partner_id', 'business_id'],
                'exit_cases_partner_fk',
            )->references(['id', 'business_id'])
                ->on('partners')
                ->restrictOnDelete();

            $table->foreign(
                ['membership_id', 'business_id'],
                'exit_cases_membership_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign(
                ['source_ownership_register_version_id', 'business_id'],
                'exit_cases_source_register_fk',
            )->references(['id', 'business_id'])
                ->on('ownership_register_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['buyer_partner_id', 'business_id'],
                'exit_cases_buyer_fk',
            )->references(['id', 'business_id'])
                ->on('partners')
                ->restrictOnDelete();

            $table->foreign(
                ['partner_change_case_id', 'business_id'],
                'exit_cases_partner_change_fk',
            )->references(['id', 'business_id'])
                ->on('partner_change_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['ownership_scenario_id', 'business_id'],
                'exit_cases_ownership_scenario_fk',
            )->references(['id', 'business_id'])
                ->on('ownership_scenarios')
                ->restrictOnDelete();

            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'exit_cases_creator_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('exit_case_transitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('exit_case_id');
            $table->unsignedBigInteger('case_revision');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->uuid('actor_membership_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'exit_transition_id_biz_uq');
            $table->unique(
                ['exit_case_id', 'case_revision'],
                'exit_transition_case_revision_uq',
            );

            $table->foreign(
                ['exit_case_id', 'business_id'],
                'exit_transition_case_fk',
            )->references(['id', 'business_id'])
                ->on('exit_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['actor_membership_id', 'business_id'],
                'exit_transition_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });
        Schema::create('exit_share_positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('exit_case_id');
            $table->uuid('source_ownership_register_version_id');
            $table->uuid('partner_id');
            $table->uuid('source_share_class_id');
            $table->string('share_class_name', 160);
            $table->decimal('voting_right_per_share', 28, 8);
            $table->decimal('profit_right_per_share', 28, 8);
            $table->decimal('shares_issued', 28, 8);
            $table->decimal('shares_vested', 28, 8);
            $table->decimal('shares_unvested', 28, 8);
            $table->decimal('voting_rights', 28, 8);
            $table->decimal('profit_rights', 28, 8);
            $table->text('outstanding_obligations_summary')->nullable();
            $table->timestampTz('captured_at');

            $table->unique(
                ['id', 'business_id'],
                'exit_share_position_id_biz_uq',
            );
            $table->unique(
                ['exit_case_id', 'source_share_class_id'],
                'exit_share_position_case_class_uq',
            );

            $table->foreign(
                ['exit_case_id', 'business_id'],
                'exit_share_position_case_fk',
            )->references(['id', 'business_id'])
                ->on('exit_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['source_ownership_register_version_id', 'business_id'],
                'exit_share_position_register_fk',
            )->references(['id', 'business_id'])
                ->on('ownership_register_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['partner_id', 'business_id'],
                'exit_share_position_partner_fk',
            )->references(['id', 'business_id'])
                ->on('partners')
                ->restrictOnDelete();

            $table->foreign(
                ['source_share_class_id', 'business_id'],
                'exit_share_position_class_fk',
            )->references(['id', 'business_id'])
                ->on('ownership_register_share_classes')
                ->restrictOnDelete();
        });

        Schema::create('exit_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('exit_case_id');
            $table->unsignedBigInteger('case_revision');
            $table->string('requirement_type', 32);
            $table->string('requirement_key', 96);
            $table->string('status', 24);
            $table->text('detail')->nullable();
            $table->string('source_type', 80)->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('recorded_by_membership_id');
            $table->timestampTz('recorded_at');

            $table->unique(
                ['id', 'business_id'],
                'exit_requirement_id_biz_uq',
            );
            $table->index(
                ['business_id', 'exit_case_id', 'requirement_key', 'recorded_at'],
                'exit_requirement_lookup_idx',
            );

            $table->foreign(
                ['exit_case_id', 'business_id'],
                'exit_requirement_case_fk',
            )->references(['id', 'business_id'])
                ->on('exit_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['recorded_by_membership_id', 'business_id'],
                'exit_requirement_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('exit_finance_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('exit_case_id');
            $table->uuid('finance_payment_id');
            $table->string('purpose', 40);
            $table->unsignedInteger('installment_sequence')->nullable();
            $table->uuid('linked_by_membership_id');
            $table->timestampTz('linked_at');

            $table->unique(
                ['id', 'business_id'],
                'exit_finance_link_id_biz_uq',
            );
            $table->unique(
                ['exit_case_id', 'finance_payment_id', 'purpose'],
                'exit_finance_link_case_payment_purpose_uq',
            );

            $table->foreign(
                ['exit_case_id', 'business_id'],
                'exit_finance_link_case_fk',
            )->references(['id', 'business_id'])
                ->on('exit_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['finance_payment_id', 'business_id'],
                'exit_finance_link_payment_fk',
            )->references(['id', 'business_id'])
                ->on('finance_payments')
                ->restrictOnDelete();

            $table->foreign(
                ['linked_by_membership_id', 'business_id'],
                'exit_finance_link_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('exit_record_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('exit_case_id');
            $table->uuid('formal_record_version_id');
            $table->unsignedBigInteger('source_case_revision');
            $table->uuid('partner_id');
            $table->string('trigger', 48);
            $table->date('notice_date')->nullable();
            $table->date('intended_exit_date')->nullable();
            $table->uuid('source_ownership_register_version_id')->nullable();
            $table->string('share_treatment', 48)->nullable();
            $table->uuid('buyer_partner_id')->nullable();
            $table->uuid('partner_change_case_id')->nullable();
            $table->uuid('ownership_scenario_id')->nullable();
            $table->string('valuation_method', 160)->nullable();
            $table->unsignedBigInteger('final_buyout_value_minor_units')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('leaver_classification', 32)->nullable();
            $table->unsignedBigInteger('payment_total_minor_units')->nullable();
            $table->string('affordability_status', 32);
            $table->string('governance_decision_type', 160);
            $table->timestampTz('effective_from')->nullable();
            $table->char('package_hash', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'exit_record_id_biz_uq');
            $table->unique(
                'formal_record_version_id',
                'exit_record_formal_uq',
            );

            $table->foreign(
                ['exit_case_id', 'business_id'],
                'exit_record_case_fk',
            )->references(['id', 'business_id'])
                ->on('exit_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'exit_record_formal_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['partner_id', 'business_id'],
                'exit_record_partner_fk',
            )->references(['id', 'business_id'])
                ->on('partners')
                ->restrictOnDelete();

            $table->foreign(
                ['source_ownership_register_version_id', 'business_id'],
                'exit_record_register_fk',
            )->references(['id', 'business_id'])
                ->on('ownership_register_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['buyer_partner_id', 'business_id'],
                'exit_record_buyer_fk',
            )->references(['id', 'business_id'])
                ->on('partners')
                ->restrictOnDelete();

            $table->foreign(
                ['partner_change_case_id', 'business_id'],
                'exit_record_partner_change_fk',
            )->references(['id', 'business_id'])
                ->on('partner_change_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['ownership_scenario_id', 'business_id'],
                'exit_record_ownership_scenario_fk',
            )->references(['id', 'business_id'])
                ->on('ownership_scenarios')
                ->restrictOnDelete();
        });
        Schema::create('exit_governance_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('exit_case_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('proposal_id');
            $table->uuid('proposal_version_id');
            $table->string('decision_type', 160);
            $table->unsignedBigInteger('source_case_revision');
            $table->char('package_hash', 64);
            $table->uuid('decision_id')->nullable();
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('effected_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['id', 'business_id'],
                'exit_submission_id_biz_uq',
            );
            $table->unique(
                ['exit_case_id', 'source_case_revision'],
                'exit_submission_case_revision_uq',
            );
            $table->unique(
                'proposal_version_id',
                'exit_submission_proposal_uq',
            );
            $table->unique(
                'formal_record_version_id',
                'exit_submission_record_uq',
            );

            $table->foreign(
                ['exit_case_id', 'business_id'],
                'exit_submission_case_fk',
            )->references(['id', 'business_id'])
                ->on('exit_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'exit_submission_formal_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['proposal_id', 'business_id'],
                'exit_submission_proposal_root_fk',
            )->references(['id', 'business_id'])
                ->on('proposals')
                ->restrictOnDelete();

            $table->foreign(
                ['proposal_version_id', 'business_id'],
                'exit_submission_proposal_fk',
            )->references(['id', 'business_id'])
                ->on('proposal_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['decision_id', 'business_id'],
                'exit_submission_decision_fk',
            )->references(['id', 'business_id'])
                ->on('decisions')
                ->restrictOnDelete();
        });
    }

    private function addChecks(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE membership_access_transitions
ADD CONSTRAINT membership_access_transition_from_ck
CHECK (from_status IN ('active','suspended','revoked')),
ADD CONSTRAINT membership_access_transition_to_ck
CHECK (to_status IN ('active','suspended','revoked')),
ADD CONSTRAINT membership_access_transition_change_ck
CHECK (from_status <> to_status)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE exit_cases
ADD CONSTRAINT exit_cases_trigger_ck
CHECK (trigger IN (
    'voluntary','retirement','poor_performance','misconduct','incapacity',
    'death','bankruptcy_insolvency','relationship_breakdown',
    'agreement_breach','other'
)),
ADD CONSTRAINT exit_cases_status_ck
CHECK (status IN (
    'draft','notice_recorded','treatment_ready','terms_ready',
    'under_governance','approved','ready_for_effect','effective',
    'settlement_pending','completed','rejected','withdrawn','cancelled'
)),
ADD CONSTRAINT exit_cases_share_treatment_ck
CHECK (
    share_treatment IS NULL OR share_treatment IN (
        'remaining_partners_buy','company_buyback','third_party_sale',
        'partial_buyout','permitted_person_transfer','cancellation',
        'retain_per_agreement','no_shares','other'
    )
),
ADD CONSTRAINT exit_cases_leaver_ck
CHECK (
    leaver_classification IS NULL OR leaver_classification IN (
        'good','bad','neutral','not_applicable','other'
    )
),
ADD CONSTRAINT exit_cases_affordability_ck
CHECK (affordability_status IN (
    'pending','affordable','not_affordable',
    'alternative_approved','not_applicable'
)),
ADD CONSTRAINT exit_cases_settlement_ck
CHECK (settlement_status IN (
    'pending','not_applicable','partial','settled'
)),
ADD CONSTRAINT exit_cases_currency_ck
CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),
ADD CONSTRAINT exit_cases_dates_ck
CHECK (
    notice_date IS NULL OR intended_exit_date IS NULL
    OR intended_exit_date >= notice_date
),
ADD CONSTRAINT exit_cases_installment_ck
CHECK (installment_count IS NULL OR installment_count > 0)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE exit_share_positions
ADD CONSTRAINT exit_share_position_nonnegative_ck
CHECK (
    shares_issued >= 0
    AND shares_vested >= 0
    AND shares_unvested >= 0
    AND shares_vested + shares_unvested = shares_issued
)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE exit_requirements
ADD CONSTRAINT exit_requirement_status_ck
CHECK (status IN ('pending','met','blocked','not_applicable')),
ADD CONSTRAINT exit_requirement_type_ck
CHECK (requirement_type IN (
    'ownership','finance','handover','access','operations',
    'continuity','post_exit','legal','governance','conflict','other'
))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE exit_finance_links
ADD CONSTRAINT exit_finance_link_purpose_ck
CHECK (purpose IN (
    'deposit','installment','final_settlement',
    'loan_settlement','other'
))
SQL);
    }

    private function addGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_exit_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'F7 Exit history is append-only';
END;
$$;

CREATE TRIGGER membership_access_transitions_append_only
BEFORE UPDATE OR DELETE ON membership_access_transitions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_exit_append_only();

CREATE TRIGGER exit_case_transitions_append_only
BEFORE UPDATE OR DELETE ON exit_case_transitions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_exit_append_only();

CREATE TRIGGER exit_share_positions_append_only
BEFORE UPDATE OR DELETE ON exit_share_positions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_exit_append_only();

CREATE TRIGGER exit_requirements_append_only
BEFORE UPDATE OR DELETE ON exit_requirements
FOR EACH ROW EXECUTE FUNCTION pbr_f7_exit_append_only();

CREATE TRIGGER exit_finance_links_append_only
BEFORE UPDATE OR DELETE ON exit_finance_links
FOR EACH ROW EXECUTE FUNCTION pbr_f7_exit_append_only();

CREATE TRIGGER exit_record_versions_append_only
BEFORE UPDATE OR DELETE ON exit_record_versions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_exit_append_only();

CREATE TRIGGER exit_governance_submissions_delete_protect
BEFORE DELETE ON exit_governance_submissions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_exit_append_only();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_validate_exit_share_position()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_case_partner uuid;
    v_case_register uuid;
    v_class_register uuid;
BEGIN
    SELECT partner_id, source_ownership_register_version_id
      INTO v_case_partner, v_case_register
      FROM exit_cases
     WHERE id = NEW.exit_case_id
       AND business_id = NEW.business_id;

    SELECT ownership_register_version_id
      INTO v_class_register
      FROM ownership_register_share_classes
     WHERE id = NEW.source_share_class_id
       AND business_id = NEW.business_id;

    IF v_case_partner IS DISTINCT FROM NEW.partner_id
       OR v_case_register IS DISTINCT FROM NEW.source_ownership_register_version_id
       OR v_class_register IS DISTINCT FROM NEW.source_ownership_register_version_id
    THEN
        RAISE EXCEPTION 'Exit share-position snapshot must bind exact Partner and Ownership Register';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER exit_share_positions_validate
BEFORE INSERT ON exit_share_positions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_validate_exit_share_position();
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_protect_exit_case()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_source_changed boolean;
    v_valid_transition boolean := false;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Exit Case history cannot be deleted';
    END IF;

    IF OLD.status IN ('completed','rejected','withdrawn','cancelled') THEN
        RAISE EXCEPTION 'Terminal Exit Case history is immutable';
    END IF;

    IF
        NEW.business_id IS DISTINCT FROM OLD.business_id
        OR NEW.case_number IS DISTINCT FROM OLD.case_number
        OR NEW.partner_id IS DISTINCT FROM OLD.partner_id
        OR NEW.membership_id IS DISTINCT FROM OLD.membership_id
        OR NEW.trigger IS DISTINCT FROM OLD.trigger
        OR NEW.trigger_detail IS DISTINCT FROM OLD.trigger_detail
        OR NEW.source_ownership_register_version_id
            IS DISTINCT FROM OLD.source_ownership_register_version_id
        OR NEW.created_by_membership_id
            IS DISTINCT FROM OLD.created_by_membership_id
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Exit Case identity and captured source are immutable';
    END IF;

    v_source_changed :=
        NEW.notice_date IS DISTINCT FROM OLD.notice_date
        OR NEW.intended_exit_date IS DISTINCT FROM OLD.intended_exit_date
        OR NEW.required_notice_days IS DISTINCT FROM OLD.required_notice_days
        OR NEW.notice_summary IS DISTINCT FROM OLD.notice_summary
        OR NEW.share_treatment IS DISTINCT FROM OLD.share_treatment
        OR NEW.buyer_partner_id IS DISTINCT FROM OLD.buyer_partner_id
        OR NEW.partner_change_case_id IS DISTINCT FROM OLD.partner_change_case_id
        OR NEW.ownership_scenario_id IS DISTINCT FROM OLD.ownership_scenario_id
        OR NEW.valuation_method IS DISTINCT FROM OLD.valuation_method
        OR NEW.approved_business_value_minor_units
            IS DISTINCT FROM OLD.approved_business_value_minor_units
        OR NEW.leaver_adjustment_minor_units
            IS DISTINCT FROM OLD.leaver_adjustment_minor_units
        OR NEW.final_buyout_value_minor_units
            IS DISTINCT FROM OLD.final_buyout_value_minor_units
        OR NEW.currency IS DISTINCT FROM OLD.currency
        OR NEW.leaver_classification IS DISTINCT FROM OLD.leaver_classification
        OR NEW.leaver_rule_reference IS DISTINCT FROM OLD.leaver_rule_reference
        OR NEW.payment_total_minor_units
            IS DISTINCT FROM OLD.payment_total_minor_units
        OR NEW.payment_terms_summary
            IS DISTINCT FROM OLD.payment_terms_summary
        OR NEW.deposit_minor_units IS DISTINCT FROM OLD.deposit_minor_units
        OR NEW.installment_minor_units IS DISTINCT FROM OLD.installment_minor_units
        OR NEW.installment_count IS DISTINCT FROM OLD.installment_count
        OR NEW.payment_frequency IS DISTINCT FROM OLD.payment_frequency
        OR NEW.first_payment_date IS DISTINCT FROM OLD.first_payment_date
        OR NEW.final_payment_date IS DISTINCT FROM OLD.final_payment_date
        OR NEW.interest_terms IS DISTINCT FROM OLD.interest_terms
        OR NEW.security_terms IS DISTINCT FROM OLD.security_terms
        OR NEW.late_payment_rule IS DISTINCT FROM OLD.late_payment_rule
        OR NEW.affordability_status IS DISTINCT FROM OLD.affordability_status
        OR NEW.alternative_payment_structure
            IS DISTINCT FROM OLD.alternative_payment_structure
        OR NEW.governance_decision_type
            IS DISTINCT FROM OLD.governance_decision_type
        OR NEW.effective_from IS DISTINCT FROM OLD.effective_from;

    IF v_source_changed
       AND (
            OLD.status NOT IN (
                'draft','notice_recorded','treatment_ready','terms_ready'
            )
            OR NEW.status NOT IN (
                'draft','notice_recorded','treatment_ready','terms_ready'
            )
       )
    THEN
        RAISE EXCEPTION 'Exit Case terms are immutable after Governance submission';
    END IF;

    IF OLD.status IS DISTINCT FROM NEW.status THEN
        v_valid_transition := CASE OLD.status
            WHEN 'draft' THEN NEW.status IN (
                'notice_recorded','withdrawn','cancelled'
            )
            WHEN 'notice_recorded' THEN NEW.status IN (
                'treatment_ready','withdrawn','cancelled'
            )
            WHEN 'treatment_ready' THEN NEW.status IN (
                'terms_ready','withdrawn','cancelled'
            )
            WHEN 'terms_ready' THEN NEW.status IN (
                'under_governance','withdrawn','cancelled'
            )
            WHEN 'under_governance' THEN NEW.status IN (
                'approved','rejected'
            )
            WHEN 'approved' THEN NEW.status = 'ready_for_effect'
            WHEN 'ready_for_effect' THEN NEW.status = 'effective'
            WHEN 'effective' THEN NEW.status IN (
                'settlement_pending','completed'
            )
            WHEN 'settlement_pending' THEN NEW.status = 'completed'
            ELSE false
        END;

        IF NOT v_valid_transition THEN
            RAISE EXCEPTION 'Invalid Exit Case status transition';
        END IF;
    END IF;

    IF OLD.completed_at IS DISTINCT FROM NEW.completed_at
       AND NEW.status <> 'completed'
    THEN
        RAISE EXCEPTION 'Exit completion timestamp requires Completed status';
    END IF;

    IF
        OLD.settlement_status IS DISTINCT FROM NEW.settlement_status
        OR OLD.settled_at IS DISTINCT FROM NEW.settled_at
    THEN
        IF OLD.status NOT IN ('effective','settlement_pending')
           OR NEW.status NOT IN (
                'effective','settlement_pending','completed'
           )
        THEN
            RAISE EXCEPTION 'Exit settlement updates require an Effective Exit';
        END IF;
    END IF;

    IF
        OLD.status IS DISTINCT FROM NEW.status
        OR v_source_changed
        OR OLD.settlement_status IS DISTINCT FROM NEW.settlement_status
        OR OLD.settled_at IS DISTINCT FROM NEW.settled_at
        OR OLD.completed_at IS DISTINCT FROM NEW.completed_at
    THEN
        IF NEW.revision <> OLD.revision + 1 THEN
            RAISE EXCEPTION 'Exit Case change must advance revision exactly once';
        END IF;
    ELSIF NEW.revision IS DISTINCT FROM OLD.revision THEN
        RAISE EXCEPTION 'Exit Case revision cannot change without a lifecycle change';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER exit_cases_history_guard
BEFORE UPDATE OR DELETE ON exit_cases
FOR EACH ROW EXECUTE FUNCTION pbr_f7_protect_exit_case();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_validate_exit_submission()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_proposal_version uuid;
    v_decision_type text;
    v_status text;
    v_outcome text;
BEGIN
    IF NEW.decision_id IS NOT NULL THEN
        SELECT
            proposal_version_id,
            decision_type,
            status,
            outcome
        INTO
            v_proposal_version,
            v_decision_type,
            v_status,
            v_outcome
        FROM decisions
        WHERE id = NEW.decision_id
          AND business_id = NEW.business_id;

        IF NOT FOUND
           OR v_proposal_version IS DISTINCT FROM NEW.proposal_version_id
           OR v_decision_type IS DISTINCT FROM NEW.decision_type
           OR v_status <> 'decided'
        THEN
            RAISE EXCEPTION 'Exit Governance Decision must bind the exact frozen Proposal and Decision Type';
        END IF;

        IF NEW.authorized_at IS NOT NULL AND v_outcome <> 'approved' THEN
            RAISE EXCEPTION 'Only an approved Exit Governance Decision may authorize effectivity';
        END IF;

        IF NEW.effected_at IS NOT NULL
           AND (
                NEW.authorized_at IS NULL
                OR v_outcome <> 'approved'
           )
        THEN
            RAISE EXCEPTION 'Effective Exit requires an authorized approved Governance Decision';
        END IF;
    ELSIF NEW.authorized_at IS NOT NULL OR NEW.effected_at IS NOT NULL THEN
        RAISE EXCEPTION 'Exit Governance authorization requires an exact Decision';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER exit_governance_submissions_validate
BEFORE INSERT OR UPDATE ON exit_governance_submissions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_validate_exit_submission();

CREATE OR REPLACE FUNCTION pbr_f7_protect_exit_submission()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF
        NEW.business_id IS DISTINCT FROM OLD.business_id
        OR NEW.exit_case_id IS DISTINCT FROM OLD.exit_case_id
        OR NEW.formal_record_version_id
            IS DISTINCT FROM OLD.formal_record_version_id
        OR NEW.proposal_id IS DISTINCT FROM OLD.proposal_id
        OR NEW.proposal_version_id IS DISTINCT FROM OLD.proposal_version_id
        OR NEW.decision_type IS DISTINCT FROM OLD.decision_type
        OR NEW.source_case_revision IS DISTINCT FROM OLD.source_case_revision
        OR NEW.package_hash IS DISTINCT FROM OLD.package_hash
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Exit Governance frozen bindings are immutable';
    END IF;

    IF OLD.decision_id IS NOT NULL
       AND NEW.decision_id IS DISTINCT FROM OLD.decision_id
    THEN
        RAISE EXCEPTION 'Exit Governance Decision binding is immutable';
    END IF;

    IF OLD.authorized_at IS NOT NULL
       AND NEW.authorized_at IS DISTINCT FROM OLD.authorized_at
    THEN
        RAISE EXCEPTION 'Exit Governance authorization history is immutable';
    END IF;

    IF OLD.effected_at IS NOT NULL THEN
        RAISE EXCEPTION 'Effective Exit Governance binding is immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER exit_governance_submissions_history_guard
BEFORE UPDATE ON exit_governance_submissions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_protect_exit_submission();
SQL);
    }

    private function backfillCapabilities(): void
    {
        $now = now();
        $permissionIds = [];

        foreach ($this->capabilities as $key) {
            $id = DB::table('permissions')
                ->where('key', $key)
                ->value('id');

            if ($id === null) {
                $id = (string) Str::uuid7();

                DB::table('permissions')->insert([
                    'id' => $id,
                    'key' => $key,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $permissionIds[$key] = (string) $id;
        }

        $matrix = [
            'Workspace Owner' => $this->capabilities,
            'Managing Partner / CEO' => $this->capabilities,
            'Finance Owner' => ['exit.view'],
            'Governance Secretary / PBR Administrator' => $this->capabilities,
            'Auditor / Viewer' => ['exit.view'],
            'External Accountant / Legal Advisor' => ['exit.view'],
        ];

        $profiles = DB::table('permission_profiles')
            ->whereIn('name', array_keys($matrix))
            ->get(['id', 'business_id', 'name']);

        foreach ($profiles as $profile) {
            foreach ($matrix[$profile->name] as $capability) {
                DB::table('permission_profile_permissions')
                    ->insertOrIgnore([
                        'business_id' => $profile->business_id,
                        'permission_profile_id' => $profile->id,
                        'permission_id' => $permissionIds[$capability],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
            }
        }
    }

    public function down(): void
    {
        if (
            DB::table('memberships')
                ->where('access_status', '<>', 'active')
                ->exists()
        ) {
            throw new RuntimeException(
                'Cannot roll back F7 Membership access lifecycle while suspended/revoked history exists.'
            );
        }

        foreach ([
            ['exit_governance_submissions', 'exit_governance_submissions_history_guard'],
            ['exit_governance_submissions', 'exit_governance_submissions_validate'],
            ['exit_governance_submissions', 'exit_governance_submissions_delete_protect'],
            ['exit_cases', 'exit_cases_history_guard'],
            ['exit_share_positions', 'exit_share_positions_validate'],
            ['exit_record_versions', 'exit_record_versions_append_only'],
            ['exit_finance_links', 'exit_finance_links_append_only'],
            ['exit_requirements', 'exit_requirements_append_only'],
            ['exit_share_positions', 'exit_share_positions_append_only'],
            ['exit_case_transitions', 'exit_case_transitions_append_only'],
            ['membership_access_transitions', 'membership_access_transitions_append_only'],
        ] as [$table, $trigger]) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }

        foreach ([
            'pbr_f7_protect_exit_submission',
            'pbr_f7_validate_exit_submission',
            'pbr_f7_protect_exit_case',
            'pbr_f7_validate_exit_share_position',
            'pbr_f7_exit_append_only',
        ] as $function) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
        }

        Schema::dropIfExists('exit_governance_submissions');
        Schema::dropIfExists('exit_record_versions');
        Schema::dropIfExists('exit_finance_links');
        Schema::dropIfExists('exit_requirements');
        Schema::dropIfExists('exit_share_positions');
        Schema::dropIfExists('exit_case_transitions');
        Schema::dropIfExists('exit_cases');
        Schema::dropIfExists('membership_access_transitions');

        Schema::table('ownership_scenarios', function (Blueprint $table): void {
            $table->dropUnique('ownership_scenarios_id_business_unique');
        });

        DB::statement(
            'ALTER TABLE memberships DROP CONSTRAINT memberships_access_status_check'
        );

        DB::statement(<<<'SQL'
ALTER TABLE memberships
ADD CONSTRAINT memberships_access_status_check
CHECK (access_status IN ('active'))
SQL);
    }
};
