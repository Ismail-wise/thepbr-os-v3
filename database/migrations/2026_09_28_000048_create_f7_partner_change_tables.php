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
        'partner_changes.view',
        'partner_changes.manage',
    ];

    public function up(): void
    {
        $this->backfillCapabilities();
        $this->extendPartnerLifecycle();
        $this->hardenOwnershipTenantKeys();
        $this->createLifecycleHistory();
        $this->createPartnerChangeTables();
        $this->addDatabaseGuards();
    }

    private function extendPartnerLifecycle(): void
    {
        DB::statement(
            'ALTER TABLE partners DROP CONSTRAINT partners_status_check'
        );

        DB::statement(<<<'SQL'
ALTER TABLE partners
ADD CONSTRAINT partners_status_check
CHECK (status IN (
    'prospective',
    'due_diligence',
    'admission_pending',
    'active',
    'exiting',
    'former',
    'inactive'
))
SQL);
    }

    private function hardenOwnershipTenantKeys(): void
    {
        Schema::table('ownership_register_versions', function (Blueprint $table): void {
            $table->unique(
                ['id', 'business_id'],
                'ownership_register_versions_id_business_uq',
            );
        });

        Schema::table('ownership_register_share_classes', function (Blueprint $table): void {
            $table->unique(
                ['id', 'business_id'],
                'ownership_register_share_classes_id_business_uq',
            );
        });
    }

    private function createLifecycleHistory(): void
    {
        Schema::create('partner_lifecycle_transitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('partner_id');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('reason_code', 80);
            $table->string('source_type', 80)->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('actor_membership_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'partner_lifecycle_id_biz_uq');
            $table->index(
                ['business_id', 'partner_id', 'occurred_at'],
                'partner_lifecycle_partner_time_idx',
            );

            $table->foreign(['partner_id', 'business_id'], 'partner_lifecycle_partner_fk')
                ->references(['id', 'business_id'])
                ->on('partners')
                ->restrictOnDelete();

            $table->foreign(
                ['actor_membership_id', 'business_id'],
                'partner_lifecycle_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });
    }

    private function createPartnerChangeTables(): void
    {
        Schema::create('partner_change_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('case_number', 40);
            $table->string('transaction_type', 32);
            $table->uuid('source_ownership_register_version_id')->nullable();
            $table->uuid('seller_partner_id')->nullable();
            $table->uuid('buyer_partner_id');
            $table->uuid('source_share_class_id')->nullable();

            $table->decimal('shares', 28, 8)->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedBigInteger('consideration_minor_units')->nullable();
            $table->text('valuation_method')->nullable();
            $table->text('rights_impact_summary')->nullable();
            $table->string('governance_decision_type', 160);
            $table->boolean('rofr_required')->default(false);
            $table->string('status', 32)->default('draft');
            $table->timestampTz('effective_from')->nullable();
            $table->uuid('created_by_membership_id');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'partner_change_case_id_biz_uq');
            $table->unique(
                ['business_id', 'case_number'],
                'partner_change_case_number_uq',
            );
            $table->index(
                ['business_id', 'status', 'created_at'],
                'partner_change_status_idx',
            );

            $table->foreign('business_id')
                ->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign(
                ['source_ownership_register_version_id', 'business_id'],
                'partner_change_source_register_fk',
            )->references(['id', 'business_id'])
                ->on('ownership_register_versions')->restrictOnDelete();
            $table->foreign(
                ['seller_partner_id', 'business_id'],
                'partner_change_seller_fk',
            )->references(['id', 'business_id'])
                ->on('partners')->restrictOnDelete();
            $table->foreign(
                ['buyer_partner_id', 'business_id'],
                'partner_change_buyer_fk',
            )->references(['id', 'business_id'])
                ->on('partners')->restrictOnDelete();
            $table->foreign(
                ['source_share_class_id', 'business_id'],
                'partner_change_share_class_fk',
            )->references(['id', 'business_id'])
                ->on('ownership_register_share_classes')->restrictOnDelete();
            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'partner_change_creator_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')->restrictOnDelete();
        });

        Schema::create('partner_change_case_transitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('partner_change_case_id');
            $table->unsignedBigInteger('sequence');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->uuid('actor_membership_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['partner_change_case_id', 'sequence'],
                'partner_change_case_transition_seq_uq',
            );
            $table->foreign(
                ['partner_change_case_id', 'business_id'],
                'partner_change_case_transition_case_fk',
            )->references(['id', 'business_id'])
                ->on('partner_change_cases')->restrictOnDelete();
            $table->foreign(
                ['actor_membership_id', 'business_id'],
                'partner_change_case_transition_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')->restrictOnDelete();
        });

        Schema::create('partner_change_eligibility_checks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('partner_change_case_id');
            $table->unsignedBigInteger('case_revision');
            $table->string('check_key', 80);
            $table->string('result', 24);
            $table->text('detail')->nullable();
            $table->string('source_type', 80)->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('checked_by_membership_id');
            $table->timestampTz('checked_at');

            $table->index(
                ['business_id', 'partner_change_case_id', 'check_key', 'case_revision'],
                'partner_change_eligibility_lookup_idx',
            );
            $table->foreign(
                ['partner_change_case_id', 'business_id'],
                'partner_change_eligibility_case_fk',
            )->references(['id', 'business_id'])
                ->on('partner_change_cases')->restrictOnDelete();
            $table->foreign(
                ['checked_by_membership_id', 'business_id'],
                'partner_change_eligibility_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')->restrictOnDelete();
        });

        Schema::create('partner_change_rofr_rounds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('partner_change_case_id');
            $table->unsignedInteger('sequence');
            $table->text('terms_summary');
            $table->timestampTz('opened_at');
            $table->timestampTz('deadline_at');
            $table->string('status', 24)->default('open');
            $table->uuid('created_by_membership_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['partner_change_case_id', 'sequence'],
                'partner_change_rofr_round_seq_uq',
            );
            $table->unique(['id', 'business_id'], 'partner_change_rofr_id_biz_uq');
            $table->foreign(
                ['partner_change_case_id', 'business_id'],
                'partner_change_rofr_case_fk',
            )->references(['id', 'business_id'])
                ->on('partner_change_cases')->restrictOnDelete();
            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'partner_change_rofr_creator_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')->restrictOnDelete();
        });

        Schema::create('partner_change_rofr_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('rofr_round_id');
            $table->uuid('eligible_partner_id');
            $table->string('response', 24);
            $table->text('note')->nullable();
            $table->timestampTz('responded_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['rofr_round_id', 'eligible_partner_id'],
                'partner_change_rofr_response_uq',
            );
            $table->foreign(['rofr_round_id', 'business_id'], 'partner_change_rofr_response_round_fk')
                ->references(['id', 'business_id'])
                ->on('partner_change_rofr_rounds')->restrictOnDelete();
            $table->foreign(
                ['eligible_partner_id', 'business_id'],
                'partner_change_rofr_response_partner_fk',
            )->references(['id', 'business_id'])
                ->on('partners')->restrictOnDelete();
        });

        Schema::create('partner_change_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('partner_change_case_id');
            $table->unsignedBigInteger('case_revision');
            $table->string('requirement_type', 40);
            $table->string('requirement_key', 80);
            $table->string('status', 24);
            $table->text('detail')->nullable();
            $table->string('source_type', 80)->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('recorded_by_membership_id');
            $table->timestampTz('recorded_at');

            $table->index(
                ['business_id', 'partner_change_case_id', 'requirement_key', 'case_revision'],
                'partner_change_requirement_lookup_idx',
            );
            $table->foreign(
                ['partner_change_case_id', 'business_id'],
                'partner_change_requirement_case_fk',
            )->references(['id', 'business_id'])
                ->on('partner_change_cases')->restrictOnDelete();
            $table->foreign(
                ['recorded_by_membership_id', 'business_id'],
                'partner_change_requirement_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')->restrictOnDelete();
        });

        Schema::create('partner_change_record_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('partner_change_case_id');
            $table->uuid('formal_record_version_id');
            $table->unsignedBigInteger('source_case_revision');
            $table->string('transaction_type', 32);
            $table->uuid('source_ownership_register_version_id')->nullable();
            $table->uuid('seller_partner_id')->nullable();
            $table->uuid('buyer_partner_id');
            $table->uuid('source_share_class_id')->nullable();
            $table->decimal('shares', 28, 8)->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedBigInteger('consideration_minor_units')->nullable();
            $table->text('valuation_method')->nullable();
            $table->text('rights_impact_summary')->nullable();
            $table->string('governance_decision_type', 160);
            $table->boolean('rofr_required');
            $table->timestampTz('effective_from')->nullable();
            $table->char('package_hash', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                'formal_record_version_id',
                'partner_change_record_formal_uq',
            );
            $table->unique(['id', 'business_id'], 'partner_change_record_id_biz_uq');
            $table->foreign(
                ['partner_change_case_id', 'business_id'],
                'partner_change_record_case_fk',
            )->references(['id', 'business_id'])
                ->on('partner_change_cases')->restrictOnDelete();
            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'partner_change_record_formal_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(
                ['source_ownership_register_version_id', 'business_id'],
                'partner_change_record_register_fk',
            )->references(['id', 'business_id'])
                ->on('ownership_register_versions')->restrictOnDelete();
            $table->foreign(
                ['buyer_partner_id', 'business_id'],
                'partner_change_record_buyer_fk',
            )->references(['id', 'business_id'])
                ->on('partners')->restrictOnDelete();
        });

        Schema::create('partner_change_governance_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('partner_change_case_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('proposal_id');
            $table->uuid('proposal_version_id');
            $table->string('decision_type', 160);
            $table->unsignedBigInteger('source_case_revision');
            $table->char('package_hash', 64);
            $table->uuid('decision_id')->nullable();
            $table->uuid('effective_register_version_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('effected_at')->nullable();

            $table->unique(
                ['partner_change_case_id', 'source_case_revision'],
                'partner_change_submission_revision_uq',
            );
            $table->foreign(
                ['partner_change_case_id', 'business_id'],
                'partner_change_submission_case_fk',
            )->references(['id', 'business_id'])
                ->on('partner_change_cases')->restrictOnDelete();
            $table->foreign('formal_record_version_id')
                ->references('id')->on('formal_record_versions')->restrictOnDelete();
            $table->foreign('proposal_id')
                ->references('id')->on('proposals')->restrictOnDelete();

            $table->foreign('proposal_version_id')
                ->references('id')->on('proposal_versions')->restrictOnDelete();
            $table->foreign('decision_id')
                ->references('id')->on('decisions')->restrictOnDelete();
            $table->foreign('effective_register_version_id')
                ->references('id')->on('ownership_register_versions')->restrictOnDelete();
        });

        Schema::create('ownership_register_transfer_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('ownership_register_version_id');
            $table->uuid('partner_change_case_id');
            $table->uuid('source_ownership_register_version_id');
            $table->uuid('seller_partner_id');
            $table->uuid('buyer_partner_id');
            $table->uuid('source_share_class_id');
            $table->decimal('shares_transferred', 28, 8);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['ownership_register_version_id', 'partner_change_case_id'],
                'ownership_transfer_source_register_case_uq',
            );
            $table->foreign(
                ['partner_change_case_id', 'business_id'],
                'ownership_transfer_source_case_fk',
            )->references(['id', 'business_id'])
                ->on('partner_change_cases')->restrictOnDelete();

            $table->foreign(
                ['ownership_register_version_id', 'business_id'],
                'ownership_transfer_new_register_fk',
            )->references(['id', 'business_id'])
                ->on('ownership_register_versions')->restrictOnDelete();
            $table->foreign(
                ['source_ownership_register_version_id', 'business_id'],
                'ownership_transfer_old_register_fk',
            )->references(['id', 'business_id'])
                ->on('ownership_register_versions')->restrictOnDelete();
            $table->foreign(
                ['seller_partner_id', 'business_id'],
                'ownership_transfer_seller_fk',
            )->references(['id', 'business_id'])
                ->on('partners')->restrictOnDelete();
            $table->foreign(
                ['buyer_partner_id', 'business_id'],
                'ownership_transfer_buyer_fk',
            )->references(['id', 'business_id'])
                ->on('partners')->restrictOnDelete();
            $table->foreign(
                ['source_share_class_id', 'business_id'],
                'ownership_transfer_share_class_fk',
            )->references(['id', 'business_id'])
                ->on('ownership_register_share_classes')->restrictOnDelete();
        });
    }

    private function addDatabaseGuards(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE partner_change_cases
ADD CONSTRAINT partner_change_type_check
CHECK (transaction_type IN ('admission','transfer_existing','issue_new')),
ADD CONSTRAINT partner_change_status_check
CHECK (status IN (
 'draft','eligibility_review','blocked','eligible','rofr','terms_ready',
 'under_governance','approved','ready_for_effect','effective','completed',
 'rejected','withdrawn'
)),
ADD CONSTRAINT partner_change_currency_check
CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),
ADD CONSTRAINT partner_change_shares_check
CHECK (shares IS NULL OR shares > 0),
ADD CONSTRAINT partner_change_transfer_shape_check
CHECK (
 (transaction_type = 'admission'
  AND seller_partner_id IS NULL
  AND source_share_class_id IS NULL
  AND shares IS NULL)
 OR
 (transaction_type IN ('transfer_existing','issue_new')
  AND source_ownership_register_version_id IS NOT NULL
  AND source_share_class_id IS NOT NULL
  AND shares IS NOT NULL)
);

ALTER TABLE partner_change_case_transitions
ADD CONSTRAINT partner_change_case_transition_from_check
CHECK (
 from_status IS NULL OR from_status IN (
  'draft','eligibility_review','blocked','eligible','rofr','terms_ready',
  'under_governance','approved','ready_for_effect','effective','completed',
  'rejected','withdrawn'
 )
),
ADD CONSTRAINT partner_change_case_transition_to_check
CHECK (to_status IN (
 'draft','eligibility_review','blocked','eligible','rofr','terms_ready',
 'under_governance','approved','ready_for_effect','effective','completed',
 'rejected','withdrawn'
));

ALTER TABLE partner_change_eligibility_checks
ADD CONSTRAINT partner_change_eligibility_result_check
CHECK (result IN ('pending','met','blocked','not_applicable'));

ALTER TABLE partner_change_rofr_rounds
ADD CONSTRAINT partner_change_rofr_round_status_check
CHECK (status IN ('open','completed','waived','cancelled')),
ADD CONSTRAINT partner_change_rofr_deadline_check
CHECK (deadline_at > opened_at);

ALTER TABLE partner_change_rofr_responses
ADD CONSTRAINT partner_change_rofr_response_check
CHECK (response IN ('pending','accept','decline','waive'));

ALTER TABLE partner_change_requirements
ADD CONSTRAINT partner_change_requirement_status_check
CHECK (status IN ('pending','met','blocked','not_applicable')),
ADD CONSTRAINT partner_change_requirement_type_check
CHECK (requirement_type IN (
 'due_diligence','contribution','legal_document','onboarding',
 'governance','ownership','other'
));

CREATE OR REPLACE FUNCTION pbr_f7_partner_change_case_guard()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    valid_transition boolean := false;
    source_changed boolean := false;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Partner Change Case history cannot be deleted';
    END IF;

    source_changed :=
        OLD.business_id IS DISTINCT FROM NEW.business_id
        OR OLD.case_number IS DISTINCT FROM NEW.case_number
        OR OLD.transaction_type IS DISTINCT FROM NEW.transaction_type
        OR OLD.source_ownership_register_version_id
            IS DISTINCT FROM NEW.source_ownership_register_version_id
        OR OLD.seller_partner_id IS DISTINCT FROM NEW.seller_partner_id
        OR OLD.buyer_partner_id IS DISTINCT FROM NEW.buyer_partner_id
        OR OLD.source_share_class_id IS DISTINCT FROM NEW.source_share_class_id
        OR OLD.shares IS DISTINCT FROM NEW.shares
        OR OLD.currency IS DISTINCT FROM NEW.currency
        OR OLD.consideration_minor_units
            IS DISTINCT FROM NEW.consideration_minor_units
        OR OLD.valuation_method IS DISTINCT FROM NEW.valuation_method
        OR OLD.rights_impact_summary IS DISTINCT FROM NEW.rights_impact_summary
        OR OLD.governance_decision_type
            IS DISTINCT FROM NEW.governance_decision_type
        OR OLD.rofr_required IS DISTINCT FROM NEW.rofr_required
        OR OLD.effective_from IS DISTINCT FROM NEW.effective_from
        OR OLD.created_by_membership_id
            IS DISTINCT FROM NEW.created_by_membership_id;

    IF source_changed
       AND (OLD.status <> 'draft' OR NEW.status <> 'draft') THEN
        RAISE EXCEPTION
            'Partner Change source fields are immutable after Draft';
    END IF;

    IF OLD.status IS DISTINCT FROM NEW.status THEN
        valid_transition := CASE OLD.status
            WHEN 'draft' THEN NEW.status IN (
                'eligibility_review','withdrawn'
            )
            WHEN 'eligibility_review' THEN NEW.status IN (
                'eligible','blocked','withdrawn'
            )
            WHEN 'blocked' THEN NEW.status IN (
                'eligibility_review','withdrawn'
            )
            WHEN 'eligible' THEN NEW.status IN (
                'rofr','terms_ready','withdrawn'
            )
            WHEN 'rofr' THEN NEW.status IN (
                'terms_ready','blocked','withdrawn'
            )
            WHEN 'terms_ready' THEN NEW.status IN (
                'under_governance','withdrawn'
            )
            WHEN 'under_governance' THEN NEW.status IN (
                'approved','rejected'
            )
            WHEN 'approved' THEN NEW.status IN (
                'ready_for_effect'
            )
            WHEN 'ready_for_effect' THEN NEW.status IN (
                'effective'
            )
            WHEN 'effective' THEN NEW.status IN (
                'completed'
            )
            ELSE false
        END;

        IF NOT valid_transition THEN
            RAISE EXCEPTION 'Invalid Partner Change status transition';
        END IF;

        IF NEW.revision <> OLD.revision + 1 THEN
            RAISE EXCEPTION
                'Partner Change transition must advance revision by one';
        END IF;
    ELSIF source_changed AND NEW.revision <> OLD.revision + 1 THEN
        RAISE EXCEPTION
            'Partner Change Draft edit must advance revision by one';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER partner_change_case_guard
BEFORE UPDATE OR DELETE ON partner_change_cases
FOR EACH ROW EXECUTE FUNCTION pbr_f7_partner_change_case_guard();

CREATE OR REPLACE FUNCTION pbr_f7_partner_change_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'F7 Partner Change history is append-only';
END;
$$;

CREATE TRIGGER partner_lifecycle_transitions_append_only
BEFORE UPDATE OR DELETE ON partner_lifecycle_transitions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_partner_change_append_only();

CREATE TRIGGER partner_change_case_transitions_append_only
BEFORE UPDATE OR DELETE ON partner_change_case_transitions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_partner_change_append_only();

CREATE TRIGGER partner_change_eligibility_append_only
BEFORE UPDATE OR DELETE ON partner_change_eligibility_checks
FOR EACH ROW EXECUTE FUNCTION pbr_f7_partner_change_append_only();

CREATE TRIGGER partner_change_rofr_response_append_only
BEFORE UPDATE OR DELETE ON partner_change_rofr_responses
FOR EACH ROW EXECUTE FUNCTION pbr_f7_partner_change_append_only();

CREATE TRIGGER partner_change_requirements_append_only
BEFORE UPDATE OR DELETE ON partner_change_requirements
FOR EACH ROW EXECUTE FUNCTION pbr_f7_partner_change_append_only();

CREATE TRIGGER partner_change_record_versions_append_only
BEFORE UPDATE OR DELETE ON partner_change_record_versions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_partner_change_append_only();

CREATE TRIGGER ownership_transfer_sources_append_only
BEFORE UPDATE OR DELETE ON ownership_register_transfer_sources
FOR EACH ROW EXECUTE FUNCTION pbr_f7_partner_change_append_only();
SQL);
    }

    private function backfillCapabilities(): void
    {
        $now = now();
        $permissionIds = [];

        foreach ($this->capabilities as $key) {
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
            'Workspace Owner' => $this->capabilities,
            'Partner' => ['partner_changes.view'],
            'Managing Partner / CEO' => $this->capabilities,
            'Finance Owner' => ['partner_changes.view'],
            'Governance Secretary / PBR Administrator' => $this->capabilities,
            'Advisor / Consultant' => ['partner_changes.view'],
            'Auditor / Viewer' => ['partner_changes.view'],
            'External Accountant / Legal Advisor' => ['partner_changes.view'],
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
        DB::statement(
            'DROP TRIGGER IF EXISTS partner_change_case_guard
             ON partner_change_cases'
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_f7_partner_change_case_guard()'
        );

        foreach ([
            ['ownership_register_transfer_sources', 'ownership_transfer_sources_append_only'],
            ['partner_change_record_versions', 'partner_change_record_versions_append_only'],
            ['partner_change_requirements', 'partner_change_requirements_append_only'],
            ['partner_change_rofr_responses', 'partner_change_rofr_response_append_only'],
            ['partner_change_eligibility_checks', 'partner_change_eligibility_append_only'],
            ['partner_change_case_transitions', 'partner_change_case_transitions_append_only'],
            ['partner_lifecycle_transitions', 'partner_lifecycle_transitions_append_only'],
        ] as [$table, $trigger]) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_f7_partner_change_append_only()'
        );

        foreach ([
            'ownership_register_transfer_sources',
            'partner_change_governance_submissions',
            'partner_change_record_versions',
            'partner_change_requirements',
            'partner_change_rofr_responses',
            'partner_change_rofr_rounds',
            'partner_change_eligibility_checks',
            'partner_change_case_transitions',
            'partner_change_cases',
            'partner_lifecycle_transitions',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('ownership_register_share_classes', function (Blueprint $table): void {
            $table->dropUnique('ownership_register_share_classes_id_business_uq');
        });

        Schema::table('ownership_register_versions', function (Blueprint $table): void {
            $table->dropUnique('ownership_register_versions_id_business_uq');
        });

        DB::statement(
            'ALTER TABLE partners DROP CONSTRAINT partners_status_check'
        );

        DB::statement(<<<'SQL'
ALTER TABLE partners
ADD CONSTRAINT partners_status_check
CHECK (status IN ('prospective','active','inactive'))
SQL);
    }
};
