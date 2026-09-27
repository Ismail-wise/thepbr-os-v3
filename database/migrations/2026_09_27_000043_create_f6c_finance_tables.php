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
    private array $financeCapabilities = [
        'finance.view',
        'finance.manage',
        'finance.pay',
    ];

    public function up(): void
    {
        $this->backfillFinanceCapabilities();

        Schema::create('finance_policy_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('operations_formal_record_version_id');
            $table->uuid('finance_owner_membership_id');
            $table->uuid('control_owner_membership_id');
            $table->uuid('bookkeeping_owner_membership_id');
            $table->string('accounting_method', 80);
            $table->string('fiscal_period', 120);
            $table->string('base_currency', 3);
            $table->text('cash_handling_rules')->nullable();
            $table->text('monthly_closing_rules')->nullable();
            $table->text('tax_coordination_rules')->nullable();
            $table->text('audit_review_rules')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'fin_policy_versions_id_business_uq');
            $table->unique('formal_record_version_id', 'fin_policy_versions_record_uq');

            $table->foreign(['formal_record_version_id', 'business_id'], 'fin_policy_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['operations_formal_record_version_id', 'business_id'], 'fin_policy_ops_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            foreach ([
                'finance_owner_membership_id' => 'fin_policy_fin_owner_fk',
                'control_owner_membership_id' => 'fin_policy_control_owner_fk',
                'bookkeeping_owner_membership_id' => 'fin_policy_book_owner_fk',
            ] as $column => $name) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
            }
        });

        Schema::create('finance_bank_account_references', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->string('bank_name', 160);
            $table->string('account_name', 160);
            $table->string('account_reference', 160);
            $table->string('currency', 3);
            $table->string('account_purpose', 200);
            $table->string('status', 24)->default('active');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'fin_bank_refs_id_business_uq');
            $table->unique(
                ['formal_record_version_id', 'bank_name', 'account_reference'],
                'fin_bank_refs_record_reference_uq',
            );
            $table->foreign(['formal_record_version_id', 'business_id'], 'fin_bank_refs_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
        });

        Schema::create('finance_bank_access_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('bank_account_reference_id');
            $table->uuid('membership_id');
            $table->string('access_level', 80);
            $table->boolean('is_signatory')->default(false);
            $table->boolean('is_backup_access')->default(false);
            $table->unsignedBigInteger('payment_limit_minor_units')->nullable();
            $table->date('last_access_review_date')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'fin_bank_access_id_business_uq');
            $table->unique(
                ['bank_account_reference_id', 'membership_id'],
                'fin_bank_access_bank_member_uq',
            );
            $table->foreign(['bank_account_reference_id', 'business_id'], 'fin_bank_access_bank_fk')
                ->references(['id', 'business_id'])->on('finance_bank_account_references')->restrictOnDelete();
            $table->foreign(['membership_id', 'business_id'], 'fin_bank_access_member_fk')
                ->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['formal_record_version_id', 'business_id'], 'fin_bank_access_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
        });

        Schema::create('finance_payment_authority_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->unsignedInteger('sequence');
            $table->string('rule_key', 96);
            $table->string('transaction_type', 96);
            $table->unsignedBigInteger('amount_min_minor_units')->nullable();
            $table->unsignedBigInteger('amount_max_minor_units')->nullable();
            $table->string('requester_operations_role_key', 96);
            $table->string('governance_decision_type', 120);
            $table->string('payer_access_level', 80);
            $table->boolean('evidence_required')->default(true);
            $table->boolean('strict_three_way_separation')->default(true);
            $table->boolean('compensating_review_allowed')->default(false);
            $table->boolean('related_party_review_required')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'fin_pay_rules_id_business_uq');
            $table->unique(['formal_record_version_id', 'sequence'], 'fin_pay_rules_record_seq_uq');
            $table->unique(['formal_record_version_id', 'rule_key'], 'fin_pay_rules_record_key_uq');
            $table->foreign(['formal_record_version_id', 'business_id'], 'fin_pay_rules_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
        });

        Schema::create('finance_expense_procurement_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->unsignedInteger('sequence');
            $table->string('rule_key', 96);
            $table->string('control_type', 24);
            $table->string('category', 120);
            $table->unsignedBigInteger('amount_min_minor_units')->nullable();
            $table->unsignedBigInteger('amount_max_minor_units')->nullable();
            $table->boolean('receipt_required')->default(true);
            $table->unsignedSmallInteger('quotation_count')->default(0);
            $table->boolean('supplier_approval_required')->default(false);
            $table->boolean('purchase_order_required')->default(false);
            $table->boolean('invoice_match_required')->default(false);
            $table->boolean('prohibited')->default(false);
            $table->text('rule_text')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'fin_exp_rules_id_business_uq');
            $table->unique(['formal_record_version_id', 'rule_key'], 'fin_exp_rules_record_key_uq');
            $table->foreign(['formal_record_version_id', 'business_id'], 'fin_exp_rules_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
        });

        Schema::create('finance_reconciliation_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('finance_policy_formal_record_version_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('currency', 3);
            $table->bigInteger('opening_cash_minor_units')->default(0);
            $table->bigInteger('inflows_minor_units')->default(0);
            $table->bigInteger('outflows_minor_units')->default(0);
            $table->bigInteger('closing_cash_minor_units')->default(0);
            $table->bigInteger('approved_net_profit_minor_units')->default(0);
            $table->unsignedBigInteger('tax_due_minor_units')->default(0);
            $table->unsignedBigInteger('debt_due_minor_units')->default(0);
            $table->bigInteger('cash_available_minor_units')->default(0);
            $table->unsignedInteger('unreconciled_items_count')->default(0);
            $table->string('status', 24)->default('open');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->uuid('created_by_membership_id');
            $table->uuid('reviewed_by_membership_id')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'fin_recon_id_business_uq');
            $table->foreign(['finance_policy_formal_record_version_id', 'business_id'], 'fin_recon_policy_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['created_by_membership_id', 'business_id'], 'fin_recon_creator_fk')
                ->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['reviewed_by_membership_id', 'business_id'], 'fin_recon_reviewer_fk')
                ->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
        });

        Schema::create('finance_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('finance_policy_formal_record_version_id');
            $table->uuid('payment_authority_rule_id');
            $table->uuid('operations_formal_record_version_id');
            $table->uuid('requester_operations_role_id');
            $table->uuid('requester_membership_id');
            $table->uuid('bank_account_reference_id');
            $table->string('transaction_type', 96);
            $table->unsignedBigInteger('amount_minor_units');
            $table->string('currency', 3);
            $table->string('payee_reference', 200);
            $table->text('description')->nullable();
            $table->boolean('related_party')->default(false);
            $table->string('status', 32)->default('draft');
            $table->unsignedBigInteger('revision')->default(1);
            $table->uuid('finance_verified_by_membership_id')->nullable();
            $table->timestampTz('finance_verified_at')->nullable();
            $table->uuid('payer_membership_id')->nullable();
            $table->uuid('reconciliation_review_id')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->string('payment_reference', 200)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('created_by_membership_id');
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'fin_payments_id_business_uq');
            $table->index(['business_id', 'status', 'created_at'], 'fin_payments_status_idx');

            $table->foreign(['finance_policy_formal_record_version_id', 'business_id'], 'fin_pay_policy_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['payment_authority_rule_id', 'business_id'], 'fin_pay_rule_fk')
                ->references(['id', 'business_id'])->on('finance_payment_authority_rules')->restrictOnDelete();
            $table->foreign(['operations_formal_record_version_id', 'business_id'], 'fin_pay_ops_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['requester_operations_role_id', 'business_id'], 'fin_pay_requester_role_fk')
                ->references(['id', 'business_id'])->on('operations_roles')->restrictOnDelete();
            $table->foreign(['requester_membership_id', 'business_id'], 'fin_pay_requester_fk')
                ->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['bank_account_reference_id', 'business_id'], 'fin_pay_bank_fk')
                ->references(['id', 'business_id'])->on('finance_bank_account_references')->restrictOnDelete();
            $table->foreign(['reconciliation_review_id', 'business_id'], 'fin_pay_recon_fk')
                ->references(['id', 'business_id'])->on('finance_reconciliation_reviews')->restrictOnDelete();
            foreach ([
                'finance_verified_by_membership_id' => 'fin_pay_verifier_fk',
                'payer_membership_id' => 'fin_pay_payer_fk',
                'created_by_membership_id' => 'fin_pay_creator_fk',
            ] as $column => $name) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
            }
        });

        Schema::create('finance_payment_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('finance_payment_id');
            $table->unsignedBigInteger('payment_revision');
            $table->char('content_hash', 64);
            $table->uuid('formal_record_version_id');
            $table->uuid('proposal_version_id');
            $table->uuid('governance_decision_id')->nullable();
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'fin_pay_sub_id_business_uq');
            $table->unique('finance_payment_id', 'fin_pay_sub_payment_uq');
            $table->unique('proposal_version_id', 'fin_pay_sub_proposal_uq');
            $table->foreign(['finance_payment_id', 'business_id'], 'fin_pay_sub_payment_fk')
                ->references(['id', 'business_id'])->on('finance_payments')->restrictOnDelete();
            $table->foreign(['formal_record_version_id', 'business_id'], 'fin_pay_sub_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['proposal_version_id', 'business_id'], 'fin_pay_sub_proposal_fk')
                ->references(['id', 'business_id'])->on('proposal_versions')->restrictOnDelete();
            $table->foreign(['governance_decision_id', 'business_id'], 'fin_pay_sub_decision_fk')
                ->references(['id', 'business_id'])->on('decisions')->restrictOnDelete();
        });

        Schema::create('finance_payment_evidence_refs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('finance_payment_id');
            $table->uuid('evidence_id');
            $table->string('purpose', 32);
            $table->uuid('linked_by_membership_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'fin_pay_evidence_id_business_uq');
            $table->unique(
                ['finance_payment_id', 'evidence_id', 'purpose'],
                'fin_pay_evidence_payment_evidence_purpose_uq',
            );
            $table->foreign(['finance_payment_id', 'business_id'], 'fin_pay_evidence_payment_fk')
                ->references(['id', 'business_id'])->on('finance_payments')->restrictOnDelete();
            $table->foreign(['evidence_id', 'business_id'], 'fin_pay_evidence_evidence_fk')
                ->references(['id', 'business_id'])->on('evidence')->restrictOnDelete();
            $table->foreign(['linked_by_membership_id', 'business_id'], 'fin_pay_evidence_member_fk')
                ->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
        });

        Schema::create('finance_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('finance_policy_formal_record_version_id');
            $table->uuid('finance_payment_id')->nullable();
            $table->uuid('reconciliation_review_id')->nullable();
            $table->string('exception_type', 80);
            $table->string('severity', 24)->default('medium');
            $table->text('reason');
            $table->boolean('requires_compensating_review')->default(false);
            $table->string('status', 32)->default('open');
            $table->uuid('opened_by_membership_id');
            $table->timestampTz('opened_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'fin_exceptions_id_business_uq');
            $table->foreign(['finance_policy_formal_record_version_id', 'business_id'], 'fin_ex_policy_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['finance_payment_id', 'business_id'], 'fin_ex_payment_fk')
                ->references(['id', 'business_id'])->on('finance_payments')->restrictOnDelete();
            $table->foreign(['reconciliation_review_id', 'business_id'], 'fin_ex_recon_fk')
                ->references(['id', 'business_id'])->on('finance_reconciliation_reviews')->restrictOnDelete();
            $table->foreign(['opened_by_membership_id', 'business_id'], 'fin_ex_opened_by_fk')
                ->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
        });

        Schema::create('finance_exception_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('finance_exception_id');
            $table->uuid('reviewer_membership_id');
            $table->string('result', 24);
            $table->text('note')->nullable();
            $table->timestampTz('reviewed_at');

            $table->unique(['id', 'business_id'], 'fin_ex_reviews_id_business_uq');
            $table->unique('finance_exception_id', 'fin_ex_reviews_exception_uq');
            $table->foreign(['finance_exception_id', 'business_id'], 'fin_ex_reviews_exception_fk')
                ->references(['id', 'business_id'])->on('finance_exceptions')->restrictOnDelete();
            $table->foreign(['reviewer_membership_id', 'business_id'], 'fin_ex_reviews_reviewer_fk')
                ->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE finance_bank_account_references ADD CONSTRAINT fin_bank_status_ck CHECK (status IN ('active','inactive','closed'))");
        DB::statement("ALTER TABLE finance_bank_access_assignments ADD CONSTRAINT fin_bank_access_status_ck CHECK (status IN ('active','inactive'))");
        DB::statement('ALTER TABLE finance_payment_authority_rules ADD CONSTRAINT fin_pay_rule_range_ck CHECK (amount_min_minor_units IS NULL OR amount_max_minor_units IS NULL OR amount_max_minor_units >= amount_min_minor_units)');
        DB::statement("ALTER TABLE finance_expense_procurement_rules ADD CONSTRAINT fin_exp_control_type_ck CHECK (control_type IN ('expense','reimbursement','procurement','cash'))");
        DB::statement('ALTER TABLE finance_expense_procurement_rules ADD CONSTRAINT fin_exp_range_ck CHECK (amount_min_minor_units IS NULL OR amount_max_minor_units IS NULL OR amount_max_minor_units >= amount_min_minor_units)');
        DB::statement('ALTER TABLE finance_reconciliation_reviews ADD CONSTRAINT fin_recon_period_ck CHECK (period_end >= period_start)');
        DB::statement("ALTER TABLE finance_reconciliation_reviews ADD CONSTRAINT fin_recon_status_ck CHECK (status IN ('open','completed','exception'))");
        DB::statement("ALTER TABLE finance_payments ADD CONSTRAINT fin_payment_status_ck CHECK (status IN ('draft','governance_pending','authorized','paid','completed','rejected','cancelled'))");
        DB::statement("ALTER TABLE finance_payment_evidence_refs ADD CONSTRAINT fin_payment_evidence_purpose_ck CHECK (purpose IN ('request_support','payment_proof'))");
        DB::statement("ALTER TABLE finance_exceptions ADD CONSTRAINT fin_exception_status_ck CHECK (status IN ('open','compensating_review','cleared','blocked','resolved'))");
        DB::statement("ALTER TABLE finance_exception_reviews ADD CONSTRAINT fin_exception_review_result_ck CHECK (result IN ('cleared','blocked'))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6c_finance_policy_child_mutable()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_record uuid;
    v_frozen timestamptz;
BEGIN
    v_record := CASE WHEN TG_OP = 'DELETE' THEN OLD.formal_record_version_id ELSE NEW.formal_record_version_id END;
    SELECT frozen_at INTO v_frozen FROM formal_record_versions WHERE id = v_record;
    IF v_frozen IS NOT NULL THEN
        RAISE EXCEPTION 'Frozen Finance Policy child history is immutable';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

CREATE TRIGGER fin_policy_header_mutable
BEFORE UPDATE OR DELETE ON finance_policy_versions
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_finance_policy_child_mutable();

CREATE TRIGGER fin_bank_refs_mutable
BEFORE INSERT OR UPDATE OR DELETE ON finance_bank_account_references
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_finance_policy_child_mutable();

CREATE TRIGGER fin_bank_access_mutable
BEFORE INSERT OR UPDATE OR DELETE ON finance_bank_access_assignments
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_finance_policy_child_mutable();

CREATE TRIGGER fin_pay_rules_mutable
BEFORE INSERT OR UPDATE OR DELETE ON finance_payment_authority_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_finance_policy_child_mutable();

CREATE TRIGGER fin_exp_rules_mutable
BEFORE INSERT OR UPDATE OR DELETE ON finance_expense_procurement_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_finance_policy_child_mutable();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6c_validate_finance_policy_version()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM formal_record_versions v
        JOIN formal_record_families f
          ON f.id = v.formal_record_family_id
         AND f.business_id = v.business_id
        WHERE v.id = NEW.formal_record_version_id
          AND v.business_id = NEW.business_id
          AND f.record_type = 'finance_policy'
    ) THEN
        RAISE EXCEPTION 'Finance Policy version must bind a finance_policy Formal Record';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM formal_record_versions v
        JOIN formal_record_families f
          ON f.id = v.formal_record_family_id
         AND f.business_id = v.business_id
        WHERE v.id = NEW.operations_formal_record_version_id
          AND v.business_id = NEW.business_id
          AND f.record_type = 'operations_register'
    ) THEN
        RAISE EXCEPTION 'Finance Policy must reference an Operations Register version';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER fin_policy_version_binding
BEFORE INSERT OR UPDATE ON finance_policy_versions
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_validate_finance_policy_version();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6c_validate_finance_payment()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_policy_record uuid;
    v_ops_record uuid;
    v_role_key text;
BEGIN
    SELECT formal_record_version_id, requester_operations_role_key
      INTO v_policy_record, v_role_key
      FROM finance_payment_authority_rules
     WHERE id = NEW.payment_authority_rule_id
       AND business_id = NEW.business_id;

    IF v_policy_record IS NULL
       OR v_policy_record <> NEW.finance_policy_formal_record_version_id THEN
        RAISE EXCEPTION 'Finance Payment authority rule must belong to the bound Finance Policy';
    END IF;

    SELECT operations_formal_record_version_id
      INTO v_ops_record
      FROM finance_policy_versions
     WHERE formal_record_version_id = NEW.finance_policy_formal_record_version_id
       AND business_id = NEW.business_id;

    IF v_ops_record IS NULL OR v_ops_record <> NEW.operations_formal_record_version_id THEN
        RAISE EXCEPTION 'Finance Payment Operations source does not match Finance Policy';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM operations_roles r
        JOIN operations_role_assignments a
          ON a.operations_role_id = r.id
         AND a.business_id = r.business_id
        WHERE r.id = NEW.requester_operations_role_id
          AND r.business_id = NEW.business_id
          AND r.formal_record_version_id = NEW.operations_formal_record_version_id
          AND r.role_key = v_role_key
          AND a.membership_id = NEW.requester_membership_id
          AND a.assignment_type = 'primary'
    ) THEN
        RAISE EXCEPTION 'Finance Payment requester must be the Primary owner of the required Operations Role';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM finance_bank_account_references b
        WHERE b.id = NEW.bank_account_reference_id
          AND b.business_id = NEW.business_id
          AND b.formal_record_version_id = NEW.finance_policy_formal_record_version_id
          AND b.status = 'active'
    ) THEN
        RAISE EXCEPTION 'Finance Payment bank reference must be active in the bound Finance Policy';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER fin_payment_binding
BEFORE INSERT OR UPDATE ON finance_payments
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_validate_finance_payment();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6c_validate_finance_payment_submission()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_record_hash text;
    v_record_frozen timestamptz;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Finance Payment submission history is immutable';
    END IF;

    SELECT v.content_hash, v.frozen_at
      INTO v_record_hash, v_record_frozen
      FROM formal_record_versions v
      JOIN formal_record_families f
        ON f.id = v.formal_record_family_id
       AND f.business_id = v.business_id
     WHERE v.id = NEW.formal_record_version_id
       AND v.business_id = NEW.business_id
       AND f.record_type = 'finance_payment'
       AND f.subject_type = 'finance_payment'
       AND f.subject_id = NEW.finance_payment_id::text;

    IF v_record_hash IS NULL
       OR v_record_frozen IS NULL
       OR v_record_hash <> NEW.content_hash THEN
        RAISE EXCEPTION 'Finance Payment submission must bind the exact frozen Finance Payment Formal Record snapshot';
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
        RAISE EXCEPTION 'Finance Payment submission must bind the exact Frozen Proposal Version and Formal Record Version';
    END IF;

    IF TG_OP = 'INSERT' AND NOT EXISTS (
        SELECT 1
          FROM finance_payments p
         WHERE p.id = NEW.finance_payment_id
           AND p.business_id = NEW.business_id
           AND p.revision = NEW.payment_revision
    ) THEN
        RAISE EXCEPTION 'Finance Payment submission revision does not match the frozen Payment revision';
    END IF;

    IF TG_OP = 'UPDATE' AND (
        NEW.business_id IS DISTINCT FROM OLD.business_id OR
        NEW.finance_payment_id IS DISTINCT FROM OLD.finance_payment_id OR
        NEW.payment_revision IS DISTINCT FROM OLD.payment_revision OR
        NEW.content_hash IS DISTINCT FROM OLD.content_hash OR
        NEW.formal_record_version_id IS DISTINCT FROM OLD.formal_record_version_id OR
        NEW.proposal_version_id IS DISTINCT FROM OLD.proposal_version_id
    ) THEN
        RAISE EXCEPTION 'Finance Payment submission frozen identity is immutable';
    END IF;

    IF TG_OP = 'UPDATE'
       AND OLD.governance_decision_id IS NOT NULL
       AND (
           NEW.governance_decision_id IS DISTINCT FROM OLD.governance_decision_id OR
           NEW.authorized_at IS DISTINCT FROM OLD.authorized_at
       ) THEN
        RAISE EXCEPTION 'Authorized Finance Payment Governance binding is immutable';
    END IF;

    IF NEW.governance_decision_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
          FROM finance_payments p
          JOIN finance_payment_authority_rules r
            ON r.id = p.payment_authority_rule_id
           AND r.business_id = p.business_id
          JOIN decisions d
            ON d.id = NEW.governance_decision_id
           AND d.business_id = NEW.business_id
         WHERE p.id = NEW.finance_payment_id
           AND p.business_id = NEW.business_id
           AND d.proposal_version_id = NEW.proposal_version_id
           AND d.decision_type = r.governance_decision_type
           AND d.decision_amount = (p.amount_minor_units::numeric / 100)
           AND d.status = 'decided'
           AND d.outcome = 'approved'
    ) THEN
        RAISE EXCEPTION 'Finance Payment submission Governance Decision must approve the exact Proposal, decision type and amount';
    END IF;

    IF (NEW.governance_decision_id IS NULL) <> (NEW.authorized_at IS NULL) THEN
        RAISE EXCEPTION 'Finance Payment Governance Decision and authorization timestamp must be recorded together';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER fin_payment_submission_binding
BEFORE INSERT OR UPDATE OR DELETE ON finance_payment_submissions
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_validate_finance_payment_submission();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6c_protect_finance_payment()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_access_level text;
    v_strict boolean;
    v_limit bigint;
BEGIN
    IF OLD.finance_verified_at IS NOT NULL AND (
        NEW.business_id IS DISTINCT FROM OLD.business_id OR
        NEW.finance_policy_formal_record_version_id IS DISTINCT FROM OLD.finance_policy_formal_record_version_id OR
        NEW.payment_authority_rule_id IS DISTINCT FROM OLD.payment_authority_rule_id OR
        NEW.operations_formal_record_version_id IS DISTINCT FROM OLD.operations_formal_record_version_id OR
        NEW.requester_operations_role_id IS DISTINCT FROM OLD.requester_operations_role_id OR
        NEW.requester_membership_id IS DISTINCT FROM OLD.requester_membership_id OR
        NEW.bank_account_reference_id IS DISTINCT FROM OLD.bank_account_reference_id OR
        NEW.transaction_type IS DISTINCT FROM OLD.transaction_type OR
        NEW.amount_minor_units IS DISTINCT FROM OLD.amount_minor_units OR
        NEW.currency IS DISTINCT FROM OLD.currency OR
        NEW.payee_reference IS DISTINCT FROM OLD.payee_reference OR
        NEW.related_party IS DISTINCT FROM OLD.related_party
    ) THEN
        RAISE EXCEPTION 'Finance-verified Payment snapshot is immutable';
    END IF;

    IF OLD.status = 'completed' AND NEW IS DISTINCT FROM OLD THEN
        RAISE EXCEPTION 'Completed Finance Payment is immutable';
    END IF;

    IF NEW.payer_membership_id IS NOT NULL THEN
        SELECT payer_access_level, strict_three_way_separation
          INTO v_access_level, v_strict
          FROM finance_payment_authority_rules
         WHERE id = NEW.payment_authority_rule_id
           AND business_id = NEW.business_id;

        SELECT a.payment_limit_minor_units
          INTO v_limit
          FROM finance_bank_access_assignments a
         WHERE a.business_id = NEW.business_id
           AND a.formal_record_version_id = NEW.finance_policy_formal_record_version_id
           AND a.bank_account_reference_id = NEW.bank_account_reference_id
           AND a.membership_id = NEW.payer_membership_id
           AND a.access_level = v_access_level
           AND a.status = 'active'
         LIMIT 1;

        IF NOT FOUND OR (v_limit IS NOT NULL AND v_limit < NEW.amount_minor_units) THEN
            RAISE EXCEPTION 'Finance Payer lacks required Bank Access Assignment or payment limit';
        END IF;

        IF v_strict AND NEW.payer_membership_id = NEW.requester_membership_id THEN
            RAISE EXCEPTION 'Strict Finance segregation forbids Requester and Payer overlap';
        END IF;
    END IF;

    IF NEW.status = 'completed' AND OLD.status <> 'completed' THEN
        IF NOT EXISTS (
            SELECT 1
            FROM finance_payment_evidence_refs r
            JOIN evidence e
              ON e.id = r.evidence_id
             AND e.business_id = r.business_id
            WHERE r.finance_payment_id = NEW.id
              AND r.business_id = NEW.business_id
              AND r.purpose = 'payment_proof'
              AND e.verified_at IS NOT NULL
        ) THEN
            RAISE EXCEPTION 'Finance Payment completion requires verified payment proof Evidence';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER fin_payment_history
BEFORE UPDATE ON finance_payments
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_protect_finance_payment();

CREATE OR REPLACE FUNCTION pbr_f6c_protect_finance_payment_evidence_ref()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_payment_id uuid;
    v_business_id uuid;
    v_purpose text;
    v_payment record;
BEGIN
    IF TG_OP = 'DELETE' THEN
        v_payment_id := OLD.finance_payment_id;
        v_business_id := OLD.business_id;
        v_purpose := OLD.purpose;
    ELSE
        v_payment_id := NEW.finance_payment_id;
        v_business_id := NEW.business_id;
        v_purpose := NEW.purpose;
    END IF;

    SELECT * INTO v_payment
      FROM finance_payments
     WHERE id = v_payment_id
       AND business_id = v_business_id;

    IF v_payment IS NULL THEN
        RAISE EXCEPTION 'Finance Payment Evidence reference must bind an existing Payment';
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF v_purpose = 'request_support'
           AND v_payment.finance_verified_at IS NOT NULL THEN
            RAISE EXCEPTION 'Request-support Evidence cannot be added after Finance Verification';
        END IF;

        IF v_purpose = 'payment_proof'
           AND v_payment.status NOT IN ('authorized','paid') THEN
            RAISE EXCEPTION 'Payment-proof Evidence may be added only to an Authorized or Paid Payment';
        END IF;

        RETURN NEW;
    END IF;

    IF v_purpose = 'request_support'
       AND v_payment.finance_verified_at IS NOT NULL THEN
        RAISE EXCEPTION 'Finance-verified request Evidence links are immutable';
    END IF;

    IF v_purpose = 'payment_proof'
       AND v_payment.status IN ('paid','completed') THEN
        RAISE EXCEPTION 'Paid Payment evidence links are immutable';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

CREATE TRIGGER fin_payment_evidence_history
BEFORE INSERT OR UPDATE OR DELETE ON finance_payment_evidence_refs
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_protect_finance_payment_evidence_ref();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6c_protect_reconciliation()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status IN ('completed','exception') AND NEW IS DISTINCT FROM OLD THEN
        RAISE EXCEPTION 'Terminal Finance Reconciliation history is immutable';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER fin_reconciliation_history
BEFORE UPDATE ON finance_reconciliation_reviews
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_protect_reconciliation();

CREATE OR REPLACE FUNCTION pbr_f6c_protect_exception_review()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Finance compensating review history is append-only';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER fin_exception_reviews_append_only
BEFORE UPDATE OR DELETE ON finance_exception_reviews
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_protect_exception_review();

CREATE OR REPLACE FUNCTION pbr_f6c_protect_finance_exception()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status IN ('cleared','blocked','resolved')
       AND NEW IS DISTINCT FROM OLD THEN
        RAISE EXCEPTION 'Resolved Finance Exception history is immutable';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.finance_policy_formal_record_version_id IS DISTINCT FROM OLD.finance_policy_formal_record_version_id
       OR NEW.finance_payment_id IS DISTINCT FROM OLD.finance_payment_id
       OR NEW.reconciliation_review_id IS DISTINCT FROM OLD.reconciliation_review_id
       OR NEW.exception_type IS DISTINCT FROM OLD.exception_type
       OR NEW.reason IS DISTINCT FROM OLD.reason
       OR NEW.requires_compensating_review IS DISTINCT FROM OLD.requires_compensating_review
       OR NEW.opened_by_membership_id IS DISTINCT FROM OLD.opened_by_membership_id
       OR NEW.opened_at IS DISTINCT FROM OLD.opened_at THEN
        RAISE EXCEPTION 'Finance Exception source identity is immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER fin_exception_history
BEFORE UPDATE ON finance_exceptions
FOR EACH ROW EXECUTE FUNCTION pbr_f6c_protect_finance_exception();
SQL);
    }

    private function backfillFinanceCapabilities(): void
    {
        $now = now();
        $permissionIds = [];

        foreach ($this->financeCapabilities as $key) {
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
            'Workspace Owner' => $this->financeCapabilities,
            'Managing Partner / CEO' => ['finance.view', 'finance.manage'],
            'Finance Owner' => $this->financeCapabilities,
            'Governance Secretary / PBR Administrator' => ['finance.view'],
            'Auditor / Viewer' => ['finance.view'],
            'External Accountant / Legal Advisor' => ['finance.view'],
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
            ['finance_exceptions', 'fin_exception_history'],
            ['finance_exception_reviews', 'fin_exception_reviews_append_only'],
            ['finance_reconciliation_reviews', 'fin_reconciliation_history'],
            ['finance_payment_evidence_refs', 'fin_payment_evidence_history'],
            ['finance_payments', 'fin_payment_history'],
            ['finance_payment_submissions', 'fin_payment_submission_binding'],
            ['finance_payments', 'fin_payment_binding'],
            ['finance_policy_versions', 'fin_policy_version_binding'],
            ['finance_policy_versions', 'fin_policy_header_mutable'],
            ['finance_expense_procurement_rules', 'fin_exp_rules_mutable'],
            ['finance_payment_authority_rules', 'fin_pay_rules_mutable'],
            ['finance_bank_access_assignments', 'fin_bank_access_mutable'],
            ['finance_bank_account_references', 'fin_bank_refs_mutable'],
        ] as [$table, $trigger]) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }

        foreach ([
            'pbr_f6c_protect_finance_exception',
            'pbr_f6c_protect_exception_review',
            'pbr_f6c_protect_reconciliation',
            'pbr_f6c_protect_finance_payment_evidence_ref',
            'pbr_f6c_protect_finance_payment',
            'pbr_f6c_validate_finance_payment_submission',
            'pbr_f6c_validate_finance_payment',
            'pbr_f6c_validate_finance_policy_version',
            'pbr_f6c_finance_policy_child_mutable',
        ] as $function) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
        }

        Schema::dropIfExists('finance_exception_reviews');
        Schema::dropIfExists('finance_exceptions');
        Schema::dropIfExists('finance_payment_evidence_refs');
        Schema::dropIfExists('finance_payment_submissions');
        Schema::dropIfExists('finance_payments');
        Schema::dropIfExists('finance_reconciliation_reviews');
        Schema::dropIfExists('finance_expense_procurement_rules');
        Schema::dropIfExists('finance_payment_authority_rules');
        Schema::dropIfExists('finance_bank_access_assignments');
        Schema::dropIfExists('finance_bank_account_references');
        Schema::dropIfExists('finance_policy_versions');
    }
};
