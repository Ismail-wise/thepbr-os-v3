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
    private array $rewardCapabilities = [
        'rewards.view',
        'rewards.manage',
    ];

    public function up(): void
    {
        $this->backfillRewardCapabilities();

        Schema::create('reward_policy_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('finance_policy_formal_record_version_id');
            $table->uuid('operations_formal_record_version_id');
            $table->uuid('reward_owner_membership_id');
            $table->string('currency', 3);
            $table->string('payment_frequency', 80);
            $table->decimal('minimum_reserve_percent', 7, 4)->default(0);
            $table->unsignedBigInteger('target_cash_buffer_minor_units')->default(0);
            $table->decimal('reinvestment_percent', 7, 4)->default(0);
            $table->unsignedBigInteger('minimum_cash_after_distribution_minor_units')->default(0);
            $table->string('distribution_governance_decision_type', 120);
            $table->boolean('manual_adjustments_allowed')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'reward_policy_versions_id_business_uq');
            $table->unique('formal_record_version_id', 'reward_policy_versions_record_uq');

            foreach ([
                ['formal_record_version_id', 'reward_policy_record_fk'],
                ['finance_policy_formal_record_version_id', 'reward_policy_finance_record_fk'],
                ['operations_formal_record_version_id', 'reward_policy_ops_record_fk'],
            ] as [$column, $name]) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            }
            $table->foreign(['reward_owner_membership_id', 'business_id'], 'reward_policy_owner_fk')
                ->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
        });

        Schema::create('reward_role_compensation_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('operations_formal_record_version_id');
            $table->uuid('operations_role_id');
            $table->uuid('partner_id');
            $table->uuid('membership_id');
            $table->string('compensation_type', 24);
            $table->unsignedBigInteger('market_rate_minor_units')->nullable();
            $table->unsignedBigInteger('amount_minor_units');
            $table->string('frequency', 80);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('governance_decision_type', 120);
            $table->string('status', 24)->default('active');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'reward_role_comp_id_business_uq');
            $table->unique(
                ['formal_record_version_id', 'operations_role_id', 'partner_id'],
                'reward_role_comp_policy_role_partner_uq',
            );
            $table->foreign(['formal_record_version_id', 'business_id'], 'reward_role_comp_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['operations_formal_record_version_id', 'business_id'], 'reward_role_comp_ops_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['operations_role_id', 'business_id'], 'reward_role_comp_role_fk')
                ->references(['id', 'business_id'])->on('operations_roles')->restrictOnDelete();
            $table->foreign(['partner_id', 'business_id'], 'reward_role_comp_partner_fk')
                ->references(['id', 'business_id'])->on('partners')->restrictOnDelete();
            $table->foreign(['membership_id', 'business_id'], 'reward_role_comp_member_fk')
                ->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
        });

        Schema::create('reward_reimbursement_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->string('rule_key', 96);
            $table->uuid('finance_expense_procurement_rule_id');
            $table->unsignedSmallInteger('reimbursement_deadline_days');
            $table->string('governance_decision_type', 120);
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'reward_reimb_id_business_uq');
            $table->unique(['formal_record_version_id', 'rule_key'], 'reward_reimb_record_key_uq');
            $table->foreign(['formal_record_version_id', 'business_id'], 'reward_reimb_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['finance_expense_procurement_rule_id', 'business_id'], 'reward_reimb_fin_rule_fk')
                ->references(['id', 'business_id'])->on('finance_expense_procurement_rules')->restrictOnDelete();
        });

        Schema::create('reward_bonus_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('operations_formal_record_version_id');
            $table->uuid('operations_role_id');
            $table->uuid('operations_kpi_id')->nullable();
            $table->uuid('partner_id')->nullable();
            $table->string('bonus_type', 80);
            $table->text('trigger_description');
            $table->text('formula_text')->nullable();
            $table->unsignedBigInteger('approved_amount_minor_units')->nullable();
            $table->unsignedBigInteger('cap_minor_units')->nullable();
            $table->string('governance_decision_type', 120);
            $table->string('status', 24)->default('active');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'reward_bonus_id_business_uq');
            $table->foreign(['formal_record_version_id', 'business_id'], 'reward_bonus_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['operations_formal_record_version_id', 'business_id'], 'reward_bonus_ops_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['operations_role_id', 'business_id'], 'reward_bonus_role_fk')
                ->references(['id', 'business_id'])->on('operations_roles')->restrictOnDelete();
            $table->foreign(['operations_kpi_id', 'business_id'], 'reward_bonus_kpi_fk')
                ->references(['id', 'business_id'])->on('operations_kpis')->restrictOnDelete();
            $table->foreign(['partner_id', 'business_id'], 'reward_bonus_partner_fk')
                ->references(['id', 'business_id'])->on('partners')->restrictOnDelete();
        });

        Schema::create('reward_loan_repayment_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('partner_id');
            $table->string('loan_reference', 160);
            $table->unsignedBigInteger('scheduled_amount_minor_units');
            $table->string('frequency', 80);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('governance_decision_type', 120);
            $table->string('status', 24)->default('active');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'reward_loan_rule_id_business_uq');
            $table->unique(
                ['formal_record_version_id', 'partner_id', 'loan_reference'],
                'reward_loan_rule_policy_partner_ref_uq',
            );
            $table->foreign(['formal_record_version_id', 'business_id'], 'reward_loan_rule_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['partner_id', 'business_id'], 'reward_loan_rule_partner_fk')
                ->references(['id', 'business_id'])->on('partners')->restrictOnDelete();
        });

        Schema::create('reward_distribution_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->string('distribution_basis', 40);
            $table->boolean('vested_only')->default(true);
            $table->text('record_date_rule')->nullable();
            $table->boolean('unpaid_contribution_restriction')->default(false);
            $table->text('leaver_treatment')->nullable();
            $table->text('special_rule_text')->nullable();
            $table->boolean('manual_adjustment_allowed')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'reward_dist_rule_id_business_uq');
            $table->unique('formal_record_version_id', 'reward_dist_rule_record_uq');
            $table->foreign(['formal_record_version_id', 'business_id'], 'reward_dist_rule_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
        });

        Schema::create('reward_distribution_status_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('distribution_rule_id');
            $table->string('partner_status', 32);
            $table->boolean('eligible');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['distribution_rule_id', 'partner_status'], 'reward_dist_status_rule_uq');
            $table->foreign(['distribution_rule_id', 'business_id'], 'reward_dist_status_parent_fk')
                ->references(['id', 'business_id'])->on('reward_distribution_rules')->restrictOnDelete();
            $table->foreign(['formal_record_version_id', 'business_id'], 'reward_dist_status_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
        });

        Schema::create('reward_distribution_class_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('distribution_rule_id');
            $table->string('share_class_name', 120);
            $table->boolean('eligible');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['distribution_rule_id', 'share_class_name'], 'reward_dist_class_rule_uq');
            $table->foreign(['distribution_rule_id', 'business_id'], 'reward_dist_class_parent_fk')
                ->references(['id', 'business_id'])->on('reward_distribution_rules')->restrictOnDelete();
            $table->foreign(['formal_record_version_id', 'business_id'], 'reward_dist_class_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
        });

        Schema::create('distribution_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('reward_policy_formal_record_version_id');
            $table->uuid('finance_policy_formal_record_version_id');
            $table->uuid('reconciliation_review_id');
            $table->uuid('ownership_register_version_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('record_date');
            $table->string('currency', 3);
            $table->bigInteger('approved_net_profit_minor_units');
            $table->unsignedBigInteger('tax_due_minor_units');
            $table->unsignedBigInteger('debt_due_minor_units');
            $table->unsignedBigInteger('required_reserve_minor_units');
            $table->unsignedBigInteger('reinvestment_minor_units');
            $table->bigInteger('adjustments_minor_units')->default(0);
            $table->unsignedBigInteger('distributable_profit_minor_units');
            $table->bigInteger('cash_available_minor_units');
            $table->string('status', 32)->default('draft');
            $table->unsignedBigInteger('revision')->default(1);
            $table->text('notes')->nullable();
            $table->uuid('created_by_membership_id');
            $table->uuid('finance_verified_by_membership_id')->nullable();
            $table->timestampTz('finance_verified_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'dist_runs_id_business_uq');
            $table->index(['business_id', 'period_end', 'status'], 'dist_runs_period_status_idx');
            foreach ([
                ['reward_policy_formal_record_version_id', 'dist_runs_reward_policy_fk'],
                ['finance_policy_formal_record_version_id', 'dist_runs_fin_policy_fk'],
            ] as [$column, $name]) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            }
            $table->foreign(['reconciliation_review_id', 'business_id'], 'dist_runs_recon_fk')
                ->references(['id', 'business_id'])->on('finance_reconciliation_reviews')->restrictOnDelete();
            $table->foreign('ownership_register_version_id')
                ->references('id')->on('ownership_register_versions')->restrictOnDelete();
            foreach ([
                ['created_by_membership_id', 'dist_runs_creator_fk'],
                ['finance_verified_by_membership_id', 'dist_runs_verifier_fk'],
            ] as [$column, $name]) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
            }
        });

        Schema::create('distribution_run_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('distribution_run_id');
            $table->uuid('ownership_register_position_id');
            $table->uuid('ownership_share_class_id');
            $table->uuid('partner_id');
            $table->decimal('eligible_weight', 28, 8);
            $table->unsignedBigInteger('calculated_amount_minor_units');
            $table->bigInteger('manual_adjustment_minor_units')->default(0);
            $table->text('adjustment_reason')->nullable();
            $table->unsignedBigInteger('final_amount_minor_units');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'dist_lines_id_business_uq');
            $table->unique(
                ['distribution_run_id', 'ownership_register_position_id'],
                'dist_lines_run_position_uq',
            );
            $table->foreign(['distribution_run_id', 'business_id'], 'dist_lines_run_fk')
                ->references(['id', 'business_id'])->on('distribution_runs')->restrictOnDelete();
            $table->foreign('ownership_register_position_id')
                ->references('id')->on('ownership_register_positions')->restrictOnDelete();
            $table->foreign('ownership_share_class_id')
                ->references('id')->on('ownership_register_share_classes')->restrictOnDelete();
            $table->foreign(['partner_id', 'business_id'], 'dist_lines_partner_fk')
                ->references(['id', 'business_id'])->on('partners')->restrictOnDelete();
        });

        Schema::create('distribution_run_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('distribution_run_id');
            $table->unsignedBigInteger('run_revision');
            $table->char('content_hash', 64);
            $table->uuid('formal_record_version_id');
            $table->uuid('proposal_version_id');
            $table->uuid('governance_decision_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'dist_sub_id_business_uq');
            $table->unique('distribution_run_id', 'dist_sub_run_uq');
            $table->unique('proposal_version_id', 'dist_sub_proposal_uq');
            $table->foreign(['distribution_run_id', 'business_id'], 'dist_sub_run_fk')
                ->references(['id', 'business_id'])->on('distribution_runs')->restrictOnDelete();
            $table->foreign(['formal_record_version_id', 'business_id'], 'dist_sub_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['proposal_version_id', 'business_id'], 'dist_sub_proposal_fk')
                ->references(['id', 'business_id'])->on('proposal_versions')->restrictOnDelete();
            $table->foreign(['governance_decision_id', 'business_id'], 'dist_sub_decision_fk')
                ->references(['id', 'business_id'])->on('decisions')->restrictOnDelete();
        });

        Schema::create('distribution_run_payment_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('distribution_run_line_id');
            $table->uuid('finance_payment_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'dist_pay_links_id_business_uq');
            $table->unique('distribution_run_line_id', 'dist_pay_links_line_uq');
            $table->unique('finance_payment_id', 'dist_pay_links_payment_uq');
            $table->foreign(['distribution_run_line_id', 'business_id'], 'dist_pay_links_line_fk')
                ->references(['id', 'business_id'])->on('distribution_run_lines')->restrictOnDelete();
            $table->foreign(['finance_payment_id', 'business_id'], 'dist_pay_links_payment_fk')
                ->references(['id', 'business_id'])->on('finance_payments')->restrictOnDelete();
        });

        Schema::create('reward_payment_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('finance_payment_id');
            $table->uuid('reward_policy_formal_record_version_id');
            $table->string('reward_payment_type', 32);
            $table->uuid('partner_id');
            $table->uuid('role_compensation_rule_id')->nullable();
            $table->uuid('reimbursement_rule_id')->nullable();
            $table->uuid('bonus_rule_id')->nullable();
            $table->uuid('loan_repayment_rule_id')->nullable();
            $table->date('entitlement_source_date')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'reward_pay_links_id_business_uq');
            $table->unique('finance_payment_id', 'reward_pay_links_payment_uq');
            $table->foreign(['finance_payment_id', 'business_id'], 'reward_pay_links_payment_fk')
                ->references(['id', 'business_id'])->on('finance_payments')->restrictOnDelete();
            $table->foreign(['reward_policy_formal_record_version_id', 'business_id'], 'reward_pay_links_policy_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['partner_id', 'business_id'], 'reward_pay_links_partner_fk')
                ->references(['id', 'business_id'])->on('partners')->restrictOnDelete();
            $table->foreign(['role_compensation_rule_id', 'business_id'], 'reward_pay_links_role_rule_fk')
                ->references(['id', 'business_id'])->on('reward_role_compensation_rules')->restrictOnDelete();
            $table->foreign(['reimbursement_rule_id', 'business_id'], 'reward_pay_links_reimb_rule_fk')
                ->references(['id', 'business_id'])->on('reward_reimbursement_rules')->restrictOnDelete();
            $table->foreign(['bonus_rule_id', 'business_id'], 'reward_pay_links_bonus_rule_fk')
                ->references(['id', 'business_id'])->on('reward_bonus_rules')->restrictOnDelete();
            $table->foreign(['loan_repayment_rule_id', 'business_id'], 'reward_pay_links_loan_rule_fk')
                ->references(['id', 'business_id'])->on('reward_loan_repayment_rules')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE reward_policy_versions ADD CONSTRAINT reward_policy_reserve_pct_ck CHECK (minimum_reserve_percent >= 0 AND minimum_reserve_percent <= 100 AND reinvestment_percent >= 0 AND reinvestment_percent <= 100)');
        DB::statement("ALTER TABLE reward_role_compensation_rules ADD CONSTRAINT reward_role_comp_type_ck CHECK (compensation_type IN ('salary','service_fee'))");
        DB::statement('ALTER TABLE reward_role_compensation_rules ADD CONSTRAINT reward_role_comp_dates_ck CHECK (end_date IS NULL OR end_date >= start_date)');
        DB::statement("ALTER TABLE reward_distribution_rules ADD CONSTRAINT reward_dist_basis_ck CHECK (distribution_basis IN ('profit_rights','vested_profit_rights','shares_issued','shares_vested','special_rule'))");
        DB::statement('ALTER TABLE distribution_runs ADD CONSTRAINT dist_runs_period_ck CHECK (period_end >= period_start)');
        DB::statement("ALTER TABLE distribution_runs ADD CONSTRAINT dist_runs_status_ck CHECK (status IN ('draft','governance_pending','approved','payment_scheduled','completed','rejected','cancelled'))");
        DB::statement('ALTER TABLE distribution_run_lines ADD CONSTRAINT dist_lines_weight_ck CHECK (eligible_weight >= 0)');
        DB::statement("ALTER TABLE reward_payment_links ADD CONSTRAINT reward_payment_type_ck CHECK (reward_payment_type IN ('salary_service_fee','reimbursement','bonus','loan_repayment'))");
        DB::statement("ALTER TABLE reward_payment_links ADD CONSTRAINT reward_payment_exact_rule_ck CHECK ((reward_payment_type = 'salary_service_fee' AND role_compensation_rule_id IS NOT NULL AND reimbursement_rule_id IS NULL AND bonus_rule_id IS NULL AND loan_repayment_rule_id IS NULL) OR (reward_payment_type = 'reimbursement' AND role_compensation_rule_id IS NULL AND reimbursement_rule_id IS NOT NULL AND bonus_rule_id IS NULL AND loan_repayment_rule_id IS NULL) OR (reward_payment_type = 'bonus' AND role_compensation_rule_id IS NULL AND reimbursement_rule_id IS NULL AND bonus_rule_id IS NOT NULL AND loan_repayment_rule_id IS NULL) OR (reward_payment_type = 'loan_repayment' AND role_compensation_rule_id IS NULL AND reimbursement_rule_id IS NULL AND bonus_rule_id IS NULL AND loan_repayment_rule_id IS NOT NULL))");
        DB::statement("ALTER TABLE reward_payment_links ADD CONSTRAINT reward_payment_entitlement_date_ck CHECK ((reward_payment_type = 'reimbursement' AND entitlement_source_date IS NOT NULL) OR (reward_payment_type <> 'reimbursement' AND entitlement_source_date IS NULL))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6c_reward_policy_child_mutable()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_record uuid;
    v_frozen timestamptz;
BEGIN
    v_record := CASE WHEN TG_OP = 'DELETE' THEN OLD.formal_record_version_id ELSE NEW.formal_record_version_id END;
    SELECT frozen_at INTO v_frozen FROM formal_record_versions WHERE id = v_record;
    IF v_frozen IS NOT NULL THEN
        RAISE EXCEPTION 'Frozen Reward Policy child history is immutable';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

CREATE TRIGGER reward_policy_header_mutable
BEFORE UPDATE OR DELETE ON reward_policy_versions
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_reward_policy_child_mutable();

CREATE TRIGGER reward_role_comp_mutable
BEFORE INSERT OR UPDATE OR DELETE ON reward_role_compensation_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_reward_policy_child_mutable();
CREATE TRIGGER reward_reimb_mutable
BEFORE INSERT OR UPDATE OR DELETE ON reward_reimbursement_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_reward_policy_child_mutable();
CREATE TRIGGER reward_bonus_mutable
BEFORE INSERT OR UPDATE OR DELETE ON reward_bonus_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_reward_policy_child_mutable();
CREATE TRIGGER reward_loan_mutable
BEFORE INSERT OR UPDATE OR DELETE ON reward_loan_repayment_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_reward_policy_child_mutable();
CREATE TRIGGER reward_dist_rule_mutable
BEFORE INSERT OR UPDATE OR DELETE ON reward_distribution_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_reward_policy_child_mutable();
CREATE TRIGGER reward_dist_status_mutable
BEFORE INSERT OR UPDATE OR DELETE ON reward_distribution_status_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_reward_policy_child_mutable();
CREATE TRIGGER reward_dist_class_mutable
BEFORE INSERT OR UPDATE OR DELETE ON reward_distribution_class_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_reward_policy_child_mutable();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6c_validate_reward_policy()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM formal_record_versions v
        JOIN formal_record_families f ON f.id = v.formal_record_family_id AND f.business_id = v.business_id
        WHERE v.id = NEW.formal_record_version_id
          AND v.business_id = NEW.business_id
          AND f.record_type = 'reward_policy'
    ) THEN
        RAISE EXCEPTION 'Reward Policy version must bind a reward_policy Formal Record';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM formal_record_versions v
        JOIN formal_record_families f ON f.id = v.formal_record_family_id AND f.business_id = v.business_id
        WHERE v.id = NEW.finance_policy_formal_record_version_id
          AND v.business_id = NEW.business_id
          AND f.record_type = 'finance_policy'
    ) THEN
        RAISE EXCEPTION 'Reward Policy must reference Finance Policy';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM formal_record_versions v
        JOIN formal_record_families f ON f.id = v.formal_record_family_id AND f.business_id = v.business_id
        WHERE v.id = NEW.operations_formal_record_version_id
          AND v.business_id = NEW.business_id
          AND f.record_type = 'operations_register'
    ) THEN
        RAISE EXCEPTION 'Reward Policy must reference Operations Register';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER reward_policy_binding
BEFORE INSERT OR UPDATE ON reward_policy_versions
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_validate_reward_policy();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6c_validate_reward_payment_link()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_rule_record uuid;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Reward Payment classification history is immutable';
    END IF;

    IF TG_OP = 'UPDATE' AND NEW IS DISTINCT FROM OLD THEN
        RAISE EXCEPTION 'Reward Payment classification is immutable';
    END IF;

    v_rule_record := CASE NEW.reward_payment_type
        WHEN 'salary_service_fee' THEN (
            SELECT formal_record_version_id
              FROM reward_role_compensation_rules
             WHERE id = NEW.role_compensation_rule_id
               AND business_id = NEW.business_id
        )
        WHEN 'reimbursement' THEN (
            SELECT formal_record_version_id
              FROM reward_reimbursement_rules
             WHERE id = NEW.reimbursement_rule_id
               AND business_id = NEW.business_id
        )
        WHEN 'bonus' THEN (
            SELECT formal_record_version_id
              FROM reward_bonus_rules
             WHERE id = NEW.bonus_rule_id
               AND business_id = NEW.business_id
        )
        WHEN 'loan_repayment' THEN (
            SELECT formal_record_version_id
              FROM reward_loan_repayment_rules
             WHERE id = NEW.loan_repayment_rule_id
               AND business_id = NEW.business_id
        )
        ELSE NULL
    END;

    IF v_rule_record IS NULL
       OR v_rule_record <> NEW.reward_policy_formal_record_version_id THEN
        RAISE EXCEPTION 'Reward Payment must bind the exact rule from its Reward Policy version';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM formal_record_versions v
          JOIN formal_record_families f
            ON f.id = v.formal_record_family_id
           AND f.business_id = v.business_id
         WHERE v.id = NEW.reward_policy_formal_record_version_id
           AND v.business_id = NEW.business_id
           AND f.record_type = 'reward_policy'
    ) THEN
        RAISE EXCEPTION 'Reward Payment must bind a Reward Policy Formal Record';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER reward_payment_link_binding
BEFORE INSERT OR UPDATE OR DELETE ON reward_payment_links
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_validate_reward_payment_link();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6c_validate_role_compensation()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_policy_ops uuid;
BEGIN
    SELECT operations_formal_record_version_id
      INTO v_policy_ops
      FROM reward_policy_versions
     WHERE formal_record_version_id = NEW.formal_record_version_id
       AND business_id = NEW.business_id;

    IF v_policy_ops IS NULL OR v_policy_ops <> NEW.operations_formal_record_version_id THEN
        RAISE EXCEPTION 'Role Compensation Operations source must match Reward Policy';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM operations_roles r
        JOIN operations_role_assignments a
          ON a.operations_role_id = r.id
         AND a.business_id = r.business_id
        WHERE r.id = NEW.operations_role_id
          AND r.business_id = NEW.business_id
          AND r.formal_record_version_id = NEW.operations_formal_record_version_id
          AND a.membership_id = NEW.membership_id
          AND a.assignment_type = 'primary'
    ) THEN
        RAISE EXCEPTION 'Role Compensation must reference the Primary owner of the exact Operations Role';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM partner_membership_links p
        WHERE p.business_id = NEW.business_id
          AND p.partner_id = NEW.partner_id
          AND p.membership_id = NEW.membership_id
    ) THEN
        RAISE EXCEPTION 'Role Compensation Partner must match the linked Membership';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER reward_role_comp_binding
BEFORE INSERT OR UPDATE ON reward_role_compensation_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_validate_role_compensation();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6c_validate_distribution_run()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_recon record;
    v_ownership record;
BEGIN
    SELECT * INTO v_recon
      FROM finance_reconciliation_reviews
     WHERE id = NEW.reconciliation_review_id
       AND business_id = NEW.business_id
       AND status = 'completed';

    IF v_recon IS NULL THEN
        RAISE EXCEPTION 'Distribution Run requires a completed same-Business Finance Reconciliation';
    END IF;

    IF v_recon.finance_policy_formal_record_version_id <> NEW.finance_policy_formal_record_version_id
       OR v_recon.period_start <> NEW.period_start
       OR v_recon.period_end <> NEW.period_end
       OR v_recon.currency <> NEW.currency
       OR v_recon.approved_net_profit_minor_units <> NEW.approved_net_profit_minor_units
       OR v_recon.tax_due_minor_units <> NEW.tax_due_minor_units
       OR v_recon.debt_due_minor_units <> NEW.debt_due_minor_units
       OR v_recon.cash_available_minor_units <> NEW.cash_available_minor_units THEN
        RAISE EXCEPTION 'Distribution Run finance snapshot must match its completed Reconciliation';
    END IF;

    SELECT * INTO v_ownership
      FROM ownership_register_versions
     WHERE id = NEW.ownership_register_version_id
       AND business_id = NEW.business_id
       AND effective_from::date <= NEW.record_date
       AND (
           effective_until IS NULL
           OR effective_until::date > NEW.record_date
       )
       AND status IN ('effective','superseded');

    IF v_ownership IS NULL THEN
        RAISE EXCEPTION 'Distribution Run requires the historical Effective Ownership Register at record date';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER dist_run_binding
BEFORE INSERT OR UPDATE ON distribution_runs
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_validate_distribution_run();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6c_validate_distribution_line()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_run record;
    v_pos record;
    v_class record;
BEGIN
    SELECT * INTO v_run
      FROM distribution_runs
     WHERE id = NEW.distribution_run_id
       AND business_id = NEW.business_id;

    SELECT * INTO v_pos
      FROM ownership_register_positions
     WHERE id = NEW.ownership_register_position_id
       AND business_id = NEW.business_id;

    SELECT * INTO v_class
      FROM ownership_register_share_classes
     WHERE id = NEW.ownership_share_class_id
       AND business_id = NEW.business_id;

    IF v_run IS NULL OR v_pos IS NULL OR v_class IS NULL
       OR v_pos.ownership_register_version_id <> v_run.ownership_register_version_id
       OR v_class.ownership_register_version_id <> v_run.ownership_register_version_id
       OR v_pos.share_class_id <> v_class.id
       OR v_pos.partner_id <> NEW.partner_id THEN
        RAISE EXCEPTION 'Distribution line must reference exact same-Business Ownership Position/Class source';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER dist_line_binding
BEFORE INSERT OR UPDATE ON distribution_run_lines
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_validate_distribution_line();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6c_validate_distribution_submission()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_record_hash text;
    v_record_frozen timestamptz;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Distribution submission history is immutable';
    END IF;

    SELECT v.content_hash, v.frozen_at
      INTO v_record_hash, v_record_frozen
      FROM formal_record_versions v
      JOIN formal_record_families f
        ON f.id = v.formal_record_family_id
       AND f.business_id = v.business_id
     WHERE v.id = NEW.formal_record_version_id
       AND v.business_id = NEW.business_id
       AND f.record_type = 'distribution_run'
       AND f.subject_type = 'distribution_run'
       AND f.subject_id = NEW.distribution_run_id::text;

    IF v_record_hash IS NULL
       OR v_record_frozen IS NULL
       OR v_record_hash <> NEW.content_hash THEN
        RAISE EXCEPTION 'Distribution submission must bind the exact frozen Distribution Formal Record snapshot';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM proposal_versions pv
          JOIN proposal_version_records pvr
            ON pvr.proposal_version_id = pv.id
           AND pvr.business_id = pv.business_id
         WHERE pv.id = NEW.proposal_version_id
           AND pv.business_id = NEW.business_id
           AND pv.frozen_at IS NOT NULL
           AND pvr.formal_record_version_id = NEW.formal_record_version_id
    ) THEN
        RAISE EXCEPTION 'Distribution submission must bind the exact Frozen Proposal Version and Formal Record Version';
    END IF;

    IF TG_OP = 'INSERT' AND NOT EXISTS (
        SELECT 1
          FROM distribution_runs r
         WHERE r.id = NEW.distribution_run_id
           AND r.business_id = NEW.business_id
           AND r.revision = NEW.run_revision
    ) THEN
        RAISE EXCEPTION 'Distribution submission revision does not match the frozen Run revision';
    END IF;

    IF TG_OP = 'UPDATE' AND (
        NEW.business_id IS DISTINCT FROM OLD.business_id OR
        NEW.distribution_run_id IS DISTINCT FROM OLD.distribution_run_id OR
        NEW.run_revision IS DISTINCT FROM OLD.run_revision OR
        NEW.content_hash IS DISTINCT FROM OLD.content_hash OR
        NEW.formal_record_version_id IS DISTINCT FROM OLD.formal_record_version_id OR
        NEW.proposal_version_id IS DISTINCT FROM OLD.proposal_version_id
    ) THEN
        RAISE EXCEPTION 'Distribution submission frozen identity is immutable';
    END IF;

    IF TG_OP = 'UPDATE'
       AND OLD.governance_decision_id IS NOT NULL
       AND (
           NEW.governance_decision_id IS DISTINCT FROM OLD.governance_decision_id OR
           NEW.approved_at IS DISTINCT FROM OLD.approved_at
       ) THEN
        RAISE EXCEPTION 'Approved Distribution Governance binding is immutable';
    END IF;

    IF NEW.governance_decision_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
          FROM distribution_runs r
          JOIN reward_policy_versions rp
            ON rp.formal_record_version_id = r.reward_policy_formal_record_version_id
           AND rp.business_id = r.business_id
          JOIN decisions d
            ON d.id = NEW.governance_decision_id
           AND d.business_id = NEW.business_id
         WHERE r.id = NEW.distribution_run_id
           AND r.business_id = NEW.business_id
           AND d.proposal_version_id = NEW.proposal_version_id
           AND d.decision_type = rp.distribution_governance_decision_type
           AND d.decision_amount = (r.distributable_profit_minor_units::numeric / 100)
           AND d.status = 'decided'
           AND d.outcome = 'approved'
    ) THEN
        RAISE EXCEPTION 'Distribution Governance Decision must approve the exact Proposal, decision type and amount';
    END IF;

    IF (NEW.governance_decision_id IS NULL) <> (NEW.approved_at IS NULL) THEN
        RAISE EXCEPTION 'Distribution Governance Decision and approval timestamp must be recorded together';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER dist_submission_binding
BEFORE INSERT OR UPDATE OR DELETE ON distribution_run_submissions
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_validate_distribution_submission();

CREATE OR REPLACE FUNCTION pbr_f6c_validate_distribution_payment_link()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_line record;
    v_run record;
    v_payment record;
    v_expected_decision_type text;
    v_payment_decision_type text;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Distribution Payment Link history is immutable';
    END IF;

    IF TG_OP = 'UPDATE' AND NEW IS DISTINCT FROM OLD THEN
        RAISE EXCEPTION 'Distribution Payment Link identity is immutable';
    END IF;

    SELECT * INTO v_line
      FROM distribution_run_lines
     WHERE id = NEW.distribution_run_line_id
       AND business_id = NEW.business_id;

    SELECT r.* INTO v_run
      FROM distribution_runs r
     WHERE r.id = v_line.distribution_run_id
       AND r.business_id = NEW.business_id;

    SELECT p.* INTO v_payment
      FROM finance_payments p
     WHERE p.id = NEW.finance_payment_id
       AND p.business_id = NEW.business_id;

    IF v_line IS NULL OR v_run IS NULL OR v_payment IS NULL THEN
        RAISE EXCEPTION 'Distribution Payment Link source is unavailable';
    END IF;

    IF v_run.status <> 'approved'
       OR NOT EXISTS (
            SELECT 1
              FROM distribution_run_submissions s
             WHERE s.distribution_run_id = v_run.id
               AND s.business_id = NEW.business_id
               AND s.governance_decision_id IS NOT NULL
               AND s.approved_at IS NOT NULL
       ) THEN
        RAISE EXCEPTION 'Distribution Payment Link requires an approved governed Distribution Run';
    END IF;

    SELECT distribution_governance_decision_type
      INTO v_expected_decision_type
      FROM reward_policy_versions
     WHERE formal_record_version_id = v_run.reward_policy_formal_record_version_id
       AND business_id = NEW.business_id;

    SELECT governance_decision_type
      INTO v_payment_decision_type
      FROM finance_payment_authority_rules
     WHERE id = v_payment.payment_authority_rule_id
       AND business_id = NEW.business_id;

    IF v_payment.status <> 'authorized'
       OR v_payment.transaction_type <> 'profit_distribution'
       OR v_payment.finance_policy_formal_record_version_id <> v_run.finance_policy_formal_record_version_id
       OR v_payment.currency <> v_run.currency
       OR v_payment.amount_minor_units <> v_line.final_amount_minor_units
       OR v_payment_decision_type IS DISTINCT FROM v_expected_decision_type THEN
        RAISE EXCEPTION 'Distribution Payment must match the governed Run line and exact Finance Payment Authority rule';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER dist_payment_link_binding
BEFORE INSERT OR UPDATE OR DELETE ON distribution_run_payment_links
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_validate_distribution_payment_link();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6c_protect_distribution_run()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_record uuid;
    v_state text;
BEGIN
    IF OLD.finance_verified_at IS NOT NULL AND (
        NEW.business_id IS DISTINCT FROM OLD.business_id OR
        NEW.reward_policy_formal_record_version_id IS DISTINCT FROM OLD.reward_policy_formal_record_version_id OR
        NEW.finance_policy_formal_record_version_id IS DISTINCT FROM OLD.finance_policy_formal_record_version_id OR
        NEW.reconciliation_review_id IS DISTINCT FROM OLD.reconciliation_review_id OR
        NEW.ownership_register_version_id IS DISTINCT FROM OLD.ownership_register_version_id OR
        NEW.period_start IS DISTINCT FROM OLD.period_start OR
        NEW.period_end IS DISTINCT FROM OLD.period_end OR
        NEW.record_date IS DISTINCT FROM OLD.record_date OR
        NEW.currency IS DISTINCT FROM OLD.currency OR
        NEW.approved_net_profit_minor_units IS DISTINCT FROM OLD.approved_net_profit_minor_units OR
        NEW.tax_due_minor_units IS DISTINCT FROM OLD.tax_due_minor_units OR
        NEW.debt_due_minor_units IS DISTINCT FROM OLD.debt_due_minor_units OR
        NEW.required_reserve_minor_units IS DISTINCT FROM OLD.required_reserve_minor_units OR
        NEW.reinvestment_minor_units IS DISTINCT FROM OLD.reinvestment_minor_units OR
        NEW.adjustments_minor_units IS DISTINCT FROM OLD.adjustments_minor_units OR
        NEW.distributable_profit_minor_units IS DISTINCT FROM OLD.distributable_profit_minor_units
    ) THEN
        RAISE EXCEPTION 'Finance-verified Distribution Run snapshot is immutable';
    END IF;

    IF OLD.status = 'completed' AND NEW IS DISTINCT FROM OLD THEN
        RAISE EXCEPTION 'Completed Distribution Run is immutable';
    END IF;

    IF NEW.status = 'completed' AND OLD.status <> 'completed' THEN
        IF NOT EXISTS (
            SELECT 1
              FROM distribution_run_lines l
             WHERE l.distribution_run_id = NEW.id
               AND l.business_id = NEW.business_id
        ) THEN
            RAISE EXCEPTION 'Distribution completion requires at least one Distribution line';
        END IF;

        IF EXISTS (
            SELECT 1
            FROM distribution_run_lines l
            LEFT JOIN distribution_run_payment_links link
              ON link.distribution_run_line_id = l.id
             AND link.business_id = l.business_id
            LEFT JOIN finance_payments p
              ON p.id = link.finance_payment_id
             AND p.business_id = link.business_id
            WHERE l.distribution_run_id = NEW.id
              AND l.business_id = NEW.business_id
              AND (p.id IS NULL OR p.status <> 'completed')
        ) THEN
            RAISE EXCEPTION 'Distribution completion requires every payment line completed';
        END IF;

        SELECT formal_record_version_id INTO v_record
          FROM distribution_run_submissions
         WHERE distribution_run_id = NEW.id
           AND business_id = NEW.business_id;

        SELECT to_state INTO v_state
          FROM record_version_state_transitions
         WHERE formal_record_version_id = v_record
         ORDER BY sequence DESC
         LIMIT 1;

        IF v_record IS NULL OR v_state <> 'effective' THEN
            RAISE EXCEPTION 'Distribution completion requires its governed Formal Record to be Effective';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER dist_run_history
BEFORE UPDATE ON distribution_runs
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_protect_distribution_run();

CREATE OR REPLACE FUNCTION pbr_f6c_protect_distribution_line_history()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_verified timestamptz;
BEGIN
    SELECT finance_verified_at INTO v_verified
      FROM distribution_runs
     WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.distribution_run_id ELSE NEW.distribution_run_id END;

    IF v_verified IS NOT NULL THEN
        RAISE EXCEPTION 'Finance-verified Distribution lines are immutable';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

CREATE TRIGGER dist_line_history
BEFORE INSERT OR UPDATE OR DELETE ON distribution_run_lines
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_protect_distribution_line_history();
SQL);
    }

    private function backfillRewardCapabilities(): void
    {
        $now = now();
        $permissionIds = [];

        foreach ($this->rewardCapabilities as $key) {
            $id = DB::table('permissions')->where('key', $key)->value('id');

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
            'Workspace Owner' => $this->rewardCapabilities,
            'Partner' => ['rewards.view'],
            'Managing Partner / CEO' => $this->rewardCapabilities,
            'Finance Owner' => ['rewards.view'],
            'Governance Secretary / PBR Administrator' => ['rewards.view'],
            'Auditor / Viewer' => ['rewards.view'],
        ];

        $profiles = DB::table('permission_profiles')
            ->whereIn('name', array_keys($matrix))
            ->get(['id', 'business_id', 'name']);

        foreach ($profiles as $profile) {
            foreach ($matrix[$profile->name] as $capability) {
                DB::table('permission_profile_permissions')->insertOrIgnore([
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
        foreach ([
            ['distribution_run_lines', 'dist_line_history'],
            ['distribution_runs', 'dist_run_history'],
            ['distribution_run_payment_links', 'dist_payment_link_binding'],
            ['distribution_run_submissions', 'dist_submission_binding'],
            ['distribution_run_lines', 'dist_line_binding'],
            ['distribution_runs', 'dist_run_binding'],
            ['reward_role_compensation_rules', 'reward_role_comp_binding'],
            ['reward_payment_links', 'reward_payment_link_binding'],
            ['reward_policy_versions', 'reward_policy_binding'],
            ['reward_policy_versions', 'reward_policy_header_mutable'],
            ['reward_distribution_class_rules', 'reward_dist_class_mutable'],
            ['reward_distribution_status_rules', 'reward_dist_status_mutable'],
            ['reward_distribution_rules', 'reward_dist_rule_mutable'],
            ['reward_loan_repayment_rules', 'reward_loan_mutable'],
            ['reward_bonus_rules', 'reward_bonus_mutable'],
            ['reward_reimbursement_rules', 'reward_reimb_mutable'],
            ['reward_role_compensation_rules', 'reward_role_comp_mutable'],
        ] as [$table, $trigger]) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }

        foreach ([
            'pbr_f6c_protect_distribution_line_history',
            'pbr_f6c_protect_distribution_run',
            'pbr_f6c_validate_distribution_payment_link',
            'pbr_f6c_validate_distribution_submission',
            'pbr_f6c_validate_distribution_line',
            'pbr_f6c_validate_distribution_run',
            'pbr_f6c_validate_role_compensation',
            'pbr_f6c_validate_reward_payment_link',
            'pbr_f6c_validate_reward_policy',
            'pbr_f6c_reward_policy_child_mutable',
        ] as $function) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
        }

        Schema::dropIfExists('reward_payment_links');
        Schema::dropIfExists('distribution_run_payment_links');
        Schema::dropIfExists('distribution_run_submissions');
        Schema::dropIfExists('distribution_run_lines');
        Schema::dropIfExists('distribution_runs');
        Schema::dropIfExists('reward_distribution_class_rules');
        Schema::dropIfExists('reward_distribution_status_rules');
        Schema::dropIfExists('reward_distribution_rules');
        Schema::dropIfExists('reward_loan_repayment_rules');
        Schema::dropIfExists('reward_bonus_rules');
        Schema::dropIfExists('reward_reimbursement_rules');
        Schema::dropIfExists('reward_role_compensation_rules');
        Schema::dropIfExists('reward_policy_versions');
    }
};
