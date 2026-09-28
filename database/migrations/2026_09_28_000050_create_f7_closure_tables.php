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
        'closure.view',
        'closure.manage',
    ];

    public function up(): void
    {
        $this->backfillCapabilities();
        $this->createTables();
        $this->addChecks();
        $this->addGuards();
    }

    private function createTables(): void
    {
        Schema::create('closure_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('case_number', 40);
            $table->string('trigger', 96);
            $table->text('trigger_detail')->nullable();
            $table->text('jurisdiction_reference');
            $table->text('legal_entity_reference')->nullable();
            $table->string('governance_decision_type', 160);
            $table->timestampTz('intended_legal_closure_at')->nullable();
            $table->unsignedBigInteger('residual_distribution_minor_units')->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('residual_distribution_basis')->nullable();
            $table->string('residual_distribution_status', 32)->default('pending');
            $table->timestampTz('legal_closed_at')->nullable();
            $table->timestampTz('workspace_closed_at')->nullable();
            $table->string('status', 32)->default('draft');
            $table->uuid('created_by_membership_id');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'closure_cases_id_biz_uq');
            $table->unique(
                ['business_id', 'case_number'],
                'closure_cases_number_biz_uq',
            );
            $table->index(
                ['business_id', 'status', 'created_at'],
                'closure_cases_status_idx',
            );

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'closure_cases_creator_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('closure_case_transitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('closure_case_id');
            $table->unsignedBigInteger('case_revision');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->uuid('actor_membership_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['id', 'business_id'],
                'closure_transition_id_biz_uq',
            );
            $table->unique(
                ['closure_case_id', 'case_revision'],
                'closure_transition_case_rev_uq',
            );

            $table->foreign(
                ['closure_case_id', 'business_id'],
                'closure_transition_case_fk',
            )->references(['id', 'business_id'])
                ->on('closure_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['actor_membership_id', 'business_id'],
                'closure_transition_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('closure_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('closure_case_id');
            $table->string('claim_reference', 80);
            $table->string('claim_type', 80);
            $table->string('claimant_reference', 240);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('amount_minor_units')->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('required')->default(true);
            $table->text('legal_priority_reference')->nullable();
            $table->string('source_type', 80)->nullable();
            $table->uuid('source_id')->nullable();
            $table->text('external_source_reference')->nullable();
            $table->string('status', 24)->default('identified');
            $table->uuid('created_by_membership_id');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'closure_claims_id_biz_uq');
            $table->unique(
                ['closure_case_id', 'claim_reference'],
                'closure_claim_case_reference_uq',
            );
            $table->index(
                ['business_id', 'closure_case_id', 'status'],
                'closure_claim_case_status_idx',
            );

            $table->foreign(
                ['closure_case_id', 'business_id'],
                'closure_claim_case_fk',
            )->references(['id', 'business_id'])
                ->on('closure_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'closure_claim_creator_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('closure_claim_transitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('closure_case_id');
            $table->uuid('closure_claim_id');
            $table->unsignedBigInteger('claim_revision');
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('note')->nullable();
            $table->uuid('actor_membership_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['id', 'business_id'],
                'closure_claim_transition_id_biz_uq',
            );
            $table->unique(
                ['closure_claim_id', 'claim_revision'],
                'closure_claim_transition_claim_rev_uq',
            );

            $table->foreign(
                ['closure_case_id', 'business_id'],
                'closure_claim_transition_case_fk',
            )->references(['id', 'business_id'])
                ->on('closure_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['closure_claim_id', 'business_id'],
                'closure_claim_transition_claim_fk',
            )->references(['id', 'business_id'])
                ->on('closure_claims')
                ->restrictOnDelete();

            $table->foreign(
                ['actor_membership_id', 'business_id'],
                'closure_claim_transition_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('closure_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('closure_case_id');
            $table->unsignedBigInteger('case_revision');
            $table->string('requirement_type', 48);
            $table->string('requirement_key', 96);
            $table->string('status', 24);
            $table->text('detail')->nullable();
            $table->string('source_type', 80)->nullable();
            $table->uuid('source_id')->nullable();
            $table->text('external_source_reference')->nullable();
            $table->uuid('recorded_by_membership_id');
            $table->timestampTz('recorded_at');

            $table->unique(
                ['id', 'business_id'],
                'closure_requirement_id_biz_uq',
            );
            $table->index(
                [
                    'business_id',
                    'closure_case_id',
                    'requirement_key',
                    'recorded_at',
                ],
                'closure_requirement_lookup_idx',
            );

            $table->foreign(
                ['closure_case_id', 'business_id'],
                'closure_requirement_case_fk',
            )->references(['id', 'business_id'])
                ->on('closure_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['recorded_by_membership_id', 'business_id'],
                'closure_requirement_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('closure_finance_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('closure_case_id');
            $table->uuid('closure_claim_id')->nullable();
            $table->uuid('finance_payment_id');
            $table->string('purpose', 48);
            $table->uuid('linked_by_membership_id');
            $table->timestampTz('linked_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['id', 'business_id'],
                'closure_finance_link_id_biz_uq',
            );
            $table->unique(
                ['closure_case_id', 'finance_payment_id', 'purpose'],
                'closure_finance_case_payment_purpose_uq',
            );

            $table->foreign(
                ['closure_case_id', 'business_id'],
                'closure_finance_case_fk',
            )->references(['id', 'business_id'])
                ->on('closure_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['closure_claim_id', 'business_id'],
                'closure_finance_claim_fk',
            )->references(['id', 'business_id'])
                ->on('closure_claims')
                ->restrictOnDelete();

            $table->foreign(
                ['finance_payment_id', 'business_id'],
                'closure_finance_payment_fk',
            )->references(['id', 'business_id'])
                ->on('finance_payments')
                ->restrictOnDelete();

            $table->foreign(
                ['linked_by_membership_id', 'business_id'],
                'closure_finance_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('closure_record_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('closure_case_id');
            $table->uuid('formal_record_version_id');
            $table->unsignedBigInteger('source_case_revision');
            $table->string('trigger', 96);
            $table->text('jurisdiction_reference');
            $table->string('governance_decision_type', 160);
            $table->timestampTz('intended_legal_closure_at')->nullable();
            $table->char('package_hash', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['id', 'business_id'],
                'closure_record_version_id_biz_uq',
            );
            $table->unique(
                'formal_record_version_id',
                'closure_record_formal_version_uq',
            );

            $table->foreign(
                ['closure_case_id', 'business_id'],
                'closure_record_case_fk',
            )->references(['id', 'business_id'])
                ->on('closure_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'closure_record_formal_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();
        });

        Schema::create('closure_governance_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('closure_case_id');
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
                'closure_submission_id_biz_uq',
            );
            $table->unique(
                'closure_case_id',
                'closure_submission_case_uq',
            );
            $table->unique(
                'proposal_version_id',
                'closure_submission_proposal_version_uq',
            );

            $table->foreign(
                ['closure_case_id', 'business_id'],
                'closure_submission_case_fk',
            )->references(['id', 'business_id'])
                ->on('closure_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'closure_submission_formal_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['proposal_id', 'business_id'],
                'closure_submission_proposal_fk',
            )->references(['id', 'business_id'])
                ->on('proposals')
                ->restrictOnDelete();

            $table->foreign(
                ['proposal_version_id', 'business_id'],
                'closure_submission_proposal_version_fk',
            )->references(['id', 'business_id'])
                ->on('proposal_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['decision_id', 'business_id'],
                'closure_submission_decision_fk',
            )->references(['id', 'business_id'])
                ->on('decisions')
                ->restrictOnDelete();
        });
    }

    private function addChecks(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE closure_cases
ADD CONSTRAINT closure_cases_status_check
CHECK (status IN (
    'draft','under_governance','approved','wind_down_active','residual_ready',
    'legal_closure_ready','legally_closed','completed','rejected','withdrawn','cancelled'
)),
ADD CONSTRAINT closure_cases_revision_positive CHECK (revision > 0),
ADD CONSTRAINT closure_cases_currency_check
CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),
ADD CONSTRAINT closure_cases_residual_status_check
CHECK (residual_distribution_status IN (
    'pending','not_applicable','planned','completed'
));
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE closure_case_transitions
ADD CONSTRAINT closure_case_transition_revision_positive
CHECK (case_revision > 0),
ADD CONSTRAINT closure_case_transition_from_status_check
CHECK (
    from_status IS NULL
    OR from_status IN (
        'draft','under_governance','approved','wind_down_active',
        'residual_ready','legal_closure_ready','legally_closed',
        'completed','rejected','withdrawn','cancelled'
    )
),
ADD CONSTRAINT closure_case_transition_to_status_check
CHECK (to_status IN (
    'draft','under_governance','approved','wind_down_active',
    'residual_ready','legal_closure_ready','legally_closed',
    'completed','rejected','withdrawn','cancelled'
));
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE closure_claims
ADD CONSTRAINT closure_claims_status_check
CHECK (status IN ('identified','verified','disputed','settled','waived')),
ADD CONSTRAINT closure_claims_revision_positive CHECK (revision > 0),
ADD CONSTRAINT closure_claims_currency_check
CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),
ADD CONSTRAINT closure_claims_source_pair_check CHECK (
    (source_type IS NULL AND source_id IS NULL)
    OR (source_type IS NOT NULL AND source_id IS NOT NULL)
);
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE closure_claim_transitions
ADD CONSTRAINT closure_claim_transition_revision_positive
CHECK (claim_revision > 0),
ADD CONSTRAINT closure_claim_transition_from_check
CHECK (
    from_status IS NULL
    OR from_status IN ('identified','verified','disputed','settled','waived')
),
ADD CONSTRAINT closure_claim_transition_to_check
CHECK (to_status IN ('identified','verified','disputed','settled','waived'));
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE closure_requirements
ADD CONSTRAINT closure_requirements_status_check
CHECK (status IN ('pending','met','blocked','not_applicable')),
ADD CONSTRAINT closure_requirements_source_pair_check CHECK (
    (source_type IS NULL AND source_id IS NULL)
    OR (source_type IS NOT NULL AND source_id IS NOT NULL)
);
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE closure_finance_links
ADD CONSTRAINT closure_finance_links_purpose_check
CHECK (purpose IN (
    'claim_settlement','tax','residual_distribution','other'
));
SQL);
    }

    private function addGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_closure_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'F7 Closure history is append-only';
END;
$$;

CREATE TRIGGER closure_case_transitions_append_only
BEFORE UPDATE OR DELETE ON closure_case_transitions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_closure_append_only();

CREATE TRIGGER closure_claim_transitions_append_only
BEFORE UPDATE OR DELETE ON closure_claim_transitions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_closure_append_only();

CREATE TRIGGER closure_requirements_append_only
BEFORE UPDATE OR DELETE ON closure_requirements
FOR EACH ROW EXECUTE FUNCTION pbr_f7_closure_append_only();

CREATE TRIGGER closure_finance_links_append_only
BEFORE UPDATE OR DELETE ON closure_finance_links
FOR EACH ROW EXECUTE FUNCTION pbr_f7_closure_append_only();

CREATE TRIGGER closure_record_versions_append_only
BEFORE UPDATE OR DELETE ON closure_record_versions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_closure_append_only();

CREATE TRIGGER closure_governance_submissions_delete_protect
BEFORE DELETE ON closure_governance_submissions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_closure_append_only();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_protect_closure_case()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_terms_changed boolean;
    v_residual_changed boolean;
    v_valid_transition boolean := false;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Closure Case history cannot be deleted';
    END IF;

    IF OLD.status IN ('completed','rejected','withdrawn','cancelled') THEN
        RAISE EXCEPTION 'Terminal Closure Case history is immutable';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.case_number IS DISTINCT FROM OLD.case_number
       OR NEW.trigger IS DISTINCT FROM OLD.trigger
       OR NEW.created_by_membership_id
            IS DISTINCT FROM OLD.created_by_membership_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Closure Case identity is immutable';
    END IF;

    v_terms_changed :=
        NEW.trigger_detail IS DISTINCT FROM OLD.trigger_detail
        OR NEW.jurisdiction_reference
            IS DISTINCT FROM OLD.jurisdiction_reference
        OR NEW.legal_entity_reference
            IS DISTINCT FROM OLD.legal_entity_reference
        OR NEW.governance_decision_type
            IS DISTINCT FROM OLD.governance_decision_type
        OR NEW.intended_legal_closure_at
            IS DISTINCT FROM OLD.intended_legal_closure_at;

    IF v_terms_changed
       AND (OLD.status <> 'draft' OR NEW.status <> 'draft')
    THEN
        RAISE EXCEPTION
            'Closure terms are immutable after Governance submission';
    END IF;

    v_residual_changed :=
        NEW.residual_distribution_minor_units
            IS DISTINCT FROM OLD.residual_distribution_minor_units
        OR NEW.currency IS DISTINCT FROM OLD.currency
        OR NEW.residual_distribution_basis
            IS DISTINCT FROM OLD.residual_distribution_basis
        OR NEW.residual_distribution_status
            IS DISTINCT FROM OLD.residual_distribution_status;

    IF v_residual_changed
       AND OLD.status NOT IN ('wind_down_active','residual_ready')
    THEN
        RAISE EXCEPTION
            'Residual distribution may be recorded only during governed wind-down';
    END IF;

    IF OLD.status IS DISTINCT FROM NEW.status THEN
        v_valid_transition := CASE OLD.status
            WHEN 'draft' THEN NEW.status IN (
                'under_governance','withdrawn','cancelled'
            )
            WHEN 'under_governance' THEN NEW.status IN (
                'approved','rejected'
            )
            WHEN 'approved' THEN NEW.status = 'wind_down_active'
            WHEN 'wind_down_active' THEN NEW.status = 'residual_ready'
            WHEN 'residual_ready' THEN NEW.status = 'legal_closure_ready'
            WHEN 'legal_closure_ready' THEN NEW.status = 'legally_closed'
            WHEN 'legally_closed' THEN NEW.status = 'completed'
            ELSE false
        END;

        IF NOT v_valid_transition THEN
            RAISE EXCEPTION 'Invalid Closure Case status transition';
        END IF;
    END IF;

    IF NEW.legal_closed_at IS DISTINCT FROM OLD.legal_closed_at
       AND NEW.status NOT IN ('legally_closed','completed')
    THEN
        RAISE EXCEPTION
            'Legal closure timestamp requires Legally Closed state';
    END IF;

    IF NEW.workspace_closed_at IS DISTINCT FROM OLD.workspace_closed_at
       AND NEW.status <> 'completed'
    THEN
        RAISE EXCEPTION
            'Workspace closure timestamp requires Completed state';
    END IF;

    IF OLD.status IS DISTINCT FROM NEW.status
       OR v_terms_changed
       OR v_residual_changed
       OR NEW.legal_closed_at IS DISTINCT FROM OLD.legal_closed_at
       OR NEW.workspace_closed_at IS DISTINCT FROM OLD.workspace_closed_at
    THEN
        IF NEW.revision <> OLD.revision + 1 THEN
            RAISE EXCEPTION
                'Closure Case revision must advance exactly once';
        END IF;
    ELSIF NEW.revision IS DISTINCT FROM OLD.revision THEN
        RAISE EXCEPTION
            'Closure Case revision cannot change without a domain mutation';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER closure_cases_history_guard
BEFORE UPDATE OR DELETE ON closure_cases
FOR EACH ROW EXECUTE FUNCTION pbr_f7_protect_closure_case();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_protect_closure_claim()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_valid_transition boolean := false;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Closure Claim history cannot be deleted';
    END IF;

    IF OLD.status IN ('settled','waived') THEN
        RAISE EXCEPTION
            'Settled or Waived Closure Claim is immutable';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.closure_case_id IS DISTINCT FROM OLD.closure_case_id
       OR NEW.claim_reference IS DISTINCT FROM OLD.claim_reference
       OR NEW.claim_type IS DISTINCT FROM OLD.claim_type
       OR NEW.created_by_membership_id
            IS DISTINCT FROM OLD.created_by_membership_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Closure Claim identity is immutable';
    END IF;

    IF OLD.status IS DISTINCT FROM NEW.status THEN
        v_valid_transition := CASE OLD.status
            WHEN 'identified' THEN NEW.status IN (
                'verified','disputed','waived'
            )
            WHEN 'verified' THEN NEW.status IN (
                'disputed','settled','waived'
            )
            WHEN 'disputed' THEN NEW.status IN (
                'verified','settled','waived'
            )
            ELSE false
        END;

        IF NOT v_valid_transition THEN
            RAISE EXCEPTION 'Invalid Closure Claim status transition';
        END IF;
    END IF;

    IF NEW.revision <> OLD.revision + 1 THEN
        RAISE EXCEPTION
            'Closure Claim revision must advance exactly once';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER closure_claims_history_guard
BEFORE UPDATE OR DELETE ON closure_claims
FOR EACH ROW EXECUTE FUNCTION pbr_f7_protect_closure_claim();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_protect_closure_submission()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.closure_case_id IS DISTINCT FROM OLD.closure_case_id
       OR NEW.formal_record_version_id
            IS DISTINCT FROM OLD.formal_record_version_id
       OR NEW.proposal_id IS DISTINCT FROM OLD.proposal_id
       OR NEW.proposal_version_id IS DISTINCT FROM OLD.proposal_version_id
       OR NEW.decision_type IS DISTINCT FROM OLD.decision_type
       OR NEW.source_case_revision IS DISTINCT FROM OLD.source_case_revision
       OR NEW.package_hash IS DISTINCT FROM OLD.package_hash
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION
            'Closure Governance submission source binding is immutable';
    END IF;

    IF OLD.decision_id IS NOT NULL
       AND NEW.decision_id IS DISTINCT FROM OLD.decision_id
    THEN
        RAISE EXCEPTION
            'Closure Governance Decision binding is immutable once recorded';
    END IF;

    IF OLD.authorized_at IS NOT NULL
       AND NEW.authorized_at IS DISTINCT FROM OLD.authorized_at
    THEN
        RAISE EXCEPTION
            'Closure Governance authorization timestamp is immutable once recorded';
    END IF;

    IF OLD.effected_at IS NOT NULL
       AND NEW.effected_at IS DISTINCT FROM OLD.effected_at
    THEN
        RAISE EXCEPTION
            'Closure effectivity timestamp is immutable once recorded';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER closure_governance_submissions_history_guard
BEFORE UPDATE ON closure_governance_submissions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_protect_closure_submission();
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
            'Partner' => ['closure.view'],
            'Managing Partner / CEO' => $this->capabilities,
            'Finance Owner' => ['closure.view'],
            'Governance Secretary / PBR Administrator' => $this->capabilities,
            'Advisor / Consultant' => ['closure.view'],
            'Auditor / Viewer' => ['closure.view'],
            'External Accountant / Legal Advisor' => ['closure.view'],
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
        foreach ([
            [
                'closure_governance_submissions',
                'closure_governance_submissions_history_guard',
            ],
            [
                'closure_governance_submissions',
                'closure_governance_submissions_delete_protect',
            ],
            ['closure_claims', 'closure_claims_history_guard'],
            ['closure_record_versions', 'closure_record_versions_append_only'],
            ['closure_finance_links', 'closure_finance_links_append_only'],
            ['closure_requirements', 'closure_requirements_append_only'],
            [
                'closure_claim_transitions',
                'closure_claim_transitions_append_only',
            ],
            [
                'closure_case_transitions',
                'closure_case_transitions_append_only',
            ],
            ['closure_cases', 'closure_cases_history_guard'],
        ] as [$table, $trigger]) {
            DB::statement(
                'DROP TRIGGER IF EXISTS '.$trigger.' ON '.$table,
            );
        }

        foreach ([
            'pbr_f7_protect_closure_submission',
            'pbr_f7_protect_closure_claim',
            'pbr_f7_protect_closure_case',
            'pbr_f7_closure_append_only',
        ] as $function) {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS '.$function.'()',
            );
        }

        Schema::dropIfExists('closure_governance_submissions');
        Schema::dropIfExists('closure_record_versions');
        Schema::dropIfExists('closure_finance_links');
        Schema::dropIfExists('closure_requirements');
        Schema::dropIfExists('closure_claim_transitions');
        Schema::dropIfExists('closure_claims');
        Schema::dropIfExists('closure_case_transitions');
        Schema::dropIfExists('closure_cases');
    }
};
