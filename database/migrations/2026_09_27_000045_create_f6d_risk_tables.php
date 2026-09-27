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
    private array $riskCapabilities = [
        'risk.view',
        'risk.manage',
    ];

    public function up(): void
    {
        $this->backfillRiskCapabilities();

        Schema::create('risk_register_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('risk_owner_membership_id');
            $table->unsignedSmallInteger('low_max_score');
            $table->unsignedSmallInteger('medium_max_score');
            $table->unsignedSmallInteger('high_max_score');
            $table->string('review_frequency', 80);
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'risk_versions_id_business_uq');
            $table->unique('formal_record_version_id', 'risk_versions_record_uq');

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'risk_versions_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['risk_owner_membership_id', 'business_id'],
                'risk_versions_owner_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('risk_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('operations_formal_record_version_id')->nullable();
            $table->uuid('operations_role_id')->nullable();
            $table->uuid('owner_membership_id')->nullable();
            $table->string('category', 48);
            $table->string('title', 200);
            $table->text('description');
            $table->unsignedSmallInteger('likelihood');
            $table->unsignedSmallInteger('impact');
            $table->unsignedSmallInteger('risk_score');
            $table->string('risk_level', 16);
            $table->text('warning_indicator')->nullable();
            $table->text('mitigation');
            $table->text('response_plan');
            $table->date('review_date')->nullable();
            $table->string('status', 24)->default('active');
            $table->string('confidentiality', 16)->default('standard');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'risk_items_id_business_uq');

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'risk_items_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_formal_record_version_id', 'business_id'],
                'risk_items_ops_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_role_id', 'business_id'],
                'risk_items_ops_role_fk',
            )->references(['id', 'business_id'])
                ->on('operations_roles')
                ->restrictOnDelete();

            $table->foreign(
                ['owner_membership_id', 'business_id'],
                'risk_items_owner_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('risk_protection_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('risk_item_id')->nullable();
            $table->string('protection_type', 40);
            $table->string('covered_subject', 240);
            $table->string('provider', 200)->nullable();
            $table->string('policy_reference', 200)->nullable();
            $table->bigInteger('coverage_amount_minor_units')->nullable();
            $table->string('currency', 3)->nullable();
            $table->bigInteger('deductible_minor_units')->nullable();
            $table->text('main_exclusions')->nullable();
            $table->bigInteger('premium_minor_units')->nullable();
            $table->date('start_date')->nullable();
            $table->date('renewal_date')->nullable();
            $table->uuid('owner_membership_id');
            $table->text('access_rule')->nullable();
            $table->text('protection_method')->nullable();
            $table->text('confidentiality_requirement')->nullable();
            $table->string('evidence_reference', 240)->nullable();
            $table->date('review_date')->nullable();
            $table->string('status', 24)->default('active');
            $table->string('confidentiality', 16)->default('standard');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'risk_protection_id_business_uq');

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'risk_protection_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['risk_item_id', 'business_id'],
                'risk_protection_risk_fk',
            )->references(['id', 'business_id'])
                ->on('risk_items')
                ->restrictOnDelete();

            $table->foreign(
                ['owner_membership_id', 'business_id'],
                'risk_protection_owner_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('risk_incidents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('risk_item_id')->nullable();
            $table->timestampTz('incident_at');
            $table->string('incident_type', 80);
            $table->text('description');
            $table->text('business_impact');
            $table->text('immediate_action');
            $table->bigInteger('loss_amount_minor_units')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 32)->default('open');
            $table->string('confidentiality', 16)->default('standard');
            $table->uuid('opened_by_membership_id');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'risk_incidents_id_business_uq');

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'risk_incidents_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['risk_item_id', 'business_id'],
                'risk_incidents_risk_fk',
            )->references(['id', 'business_id'])
                ->on('risk_items')
                ->restrictOnDelete();

            $table->foreign(
                ['opened_by_membership_id', 'business_id'],
                'risk_incidents_opener_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('risk_incident_updates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('risk_incident_id');
            $table->string('status', 32);
            $table->text('root_cause')->nullable();
            $table->text('corrective_action')->nullable();
            $table->text('note')->nullable();
            $table->uuid('actor_membership_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(
                ['risk_incident_id', 'business_id'],
                'risk_incident_updates_incident_fk',
            )->references(['id', 'business_id'])
                ->on('risk_incidents')
                ->restrictOnDelete();

            $table->foreign(
                ['actor_membership_id', 'business_id'],
                'risk_incident_updates_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('risk_control_tests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('risk_item_id')->nullable();
            $table->uuid('risk_protection_record_id')->nullable();
            $table->string('control_name', 200);
            $table->text('scenario');
            $table->timestampTz('tested_at')->nullable();
            $table->string('result', 16)->default('planned');
            $table->text('gap_found')->nullable();
            $table->text('corrective_action')->nullable();
            $table->uuid('owner_membership_id');
            $table->date('next_test_date')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('confidentiality', 16)->default('standard');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'risk_tests_id_business_uq');

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'risk_tests_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['risk_item_id', 'business_id'],
                'risk_tests_risk_fk',
            )->references(['id', 'business_id'])
                ->on('risk_items')
                ->restrictOnDelete();

            $table->foreign(
                ['risk_protection_record_id', 'business_id'],
                'risk_tests_protection_fk',
            )->references(['id', 'business_id'])
                ->on('risk_protection_records')
                ->restrictOnDelete();

            $table->foreign(
                ['owner_membership_id', 'business_id'],
                'risk_tests_owner_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        $this->addChecks();
        $this->addGuards();
    }

    private function addChecks(): void
    {
        DB::statement('ALTER TABLE risk_register_versions
            ADD CONSTRAINT risk_thresholds_check
            CHECK (
                low_max_score >= 1
                AND low_max_score < medium_max_score
                AND medium_max_score < high_max_score
                AND high_max_score < 25
            )');

        DB::statement("ALTER TABLE risk_items
            ADD CONSTRAINT risk_items_category_check
            CHECK (category IN (
                'operational','financial','people_key_person','customer_liability',
                'technology_cyber','legal_regulatory','ip_brand_confidentiality',
                'strategic_partnership'
            ))");

        DB::statement('ALTER TABLE risk_items
            ADD CONSTRAINT risk_items_score_check
            CHECK (
                likelihood BETWEEN 1 AND 5
                AND impact BETWEEN 1 AND 5
                AND risk_score = likelihood * impact
            )');

        DB::statement("ALTER TABLE risk_items
            ADD CONSTRAINT risk_items_level_check
            CHECK (risk_level IN ('low','medium','high','critical'))");

        DB::statement("ALTER TABLE risk_items
            ADD CONSTRAINT risk_items_status_check
            CHECK (status IN ('active','monitoring','treated','accepted','closed'))");

        DB::statement("ALTER TABLE risk_items
            ADD CONSTRAINT risk_items_confidentiality_check
            CHECK (confidentiality IN ('standard','restricted'))");

        DB::statement("ALTER TABLE risk_protection_records
            ADD CONSTRAINT risk_protection_type_check
            CHECK (protection_type IN (
                'insurance','ip_brand','confidentiality_data',
                'system_access','misconduct','other'
            ))");

        DB::statement("ALTER TABLE risk_protection_records
            ADD CONSTRAINT risk_protection_confidentiality_check
            CHECK (confidentiality IN ('standard','restricted'))");

        DB::statement("ALTER TABLE risk_incidents
            ADD CONSTRAINT risk_incidents_status_check
            CHECK (status IN (
                'open','investigating','contained','corrective_action',
                'resolved','closed'
            ))");

        DB::statement("ALTER TABLE risk_incidents
            ADD CONSTRAINT risk_incidents_confidentiality_check
            CHECK (confidentiality IN ('standard','restricted'))");

        DB::statement("ALTER TABLE risk_control_tests
            ADD CONSTRAINT risk_tests_result_check
            CHECK (result IN ('planned','running','passed','partial','failed'))");

        DB::statement("ALTER TABLE risk_control_tests
            ADD CONSTRAINT risk_tests_confidentiality_check
            CHECK (confidentiality IN ('standard','restricted'))");
    }

    private function addGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6d_risk_snapshot_mutable()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_version uuid;
    v_frozen timestamptz;
BEGIN
    v_version := CASE
        WHEN TG_OP = 'DELETE' THEN OLD.formal_record_version_id
        ELSE NEW.formal_record_version_id
    END;

    SELECT frozen_at INTO v_frozen
      FROM formal_record_versions
     WHERE id = v_version;

    IF v_frozen IS NOT NULL THEN
        RAISE EXCEPTION 'Frozen Risk Register snapshot is immutable';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

CREATE TRIGGER risk_register_header_mutable
BEFORE UPDATE OR DELETE ON risk_register_versions
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_risk_snapshot_mutable();

CREATE TRIGGER risk_items_mutable
BEFORE INSERT OR UPDATE OR DELETE ON risk_items
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_risk_snapshot_mutable();

CREATE TRIGGER risk_protection_mutable
BEFORE INSERT OR UPDATE OR DELETE ON risk_protection_records
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_risk_snapshot_mutable();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6d_validate_risk_item()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_low integer;
    v_medium integer;
    v_high integer;
    v_expected text;
    v_role_version uuid;
BEGIN
    SELECT low_max_score, medium_max_score, high_max_score
      INTO v_low, v_medium, v_high
      FROM risk_register_versions
     WHERE business_id = NEW.business_id
       AND formal_record_version_id = NEW.formal_record_version_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Risk item requires same-Business Risk Register Version';
    END IF;

    v_expected := CASE
        WHEN NEW.risk_score <= v_low THEN 'low'
        WHEN NEW.risk_score <= v_medium THEN 'medium'
        WHEN NEW.risk_score <= v_high THEN 'high'
        ELSE 'critical'
    END;

    IF NEW.risk_level <> v_expected THEN
        RAISE EXCEPTION 'Risk level does not match exact Risk Register thresholds';
    END IF;

    IF (NEW.operations_role_id IS NULL) <> (NEW.operations_formal_record_version_id IS NULL) THEN
        RAISE EXCEPTION 'Risk Operations role and version must be bound together';
    END IF;

    IF NEW.operations_role_id IS NOT NULL THEN
        SELECT formal_record_version_id INTO v_role_version
          FROM operations_roles
         WHERE id = NEW.operations_role_id
           AND business_id = NEW.business_id;

        IF v_role_version IS DISTINCT FROM NEW.operations_formal_record_version_id THEN
            RAISE EXCEPTION 'Risk owner role must bind exact Operations Version';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER risk_items_validate
BEFORE INSERT OR UPDATE ON risk_items
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_validate_risk_item();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6d_protect_incident_history()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Risk Incident history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.formal_record_version_id IS DISTINCT FROM OLD.formal_record_version_id
       OR NEW.risk_item_id IS DISTINCT FROM OLD.risk_item_id
       OR NEW.incident_at IS DISTINCT FROM OLD.incident_at
       OR NEW.incident_type IS DISTINCT FROM OLD.incident_type
       OR NEW.description IS DISTINCT FROM OLD.description
       OR NEW.business_impact IS DISTINCT FROM OLD.business_impact
       OR NEW.immediate_action IS DISTINCT FROM OLD.immediate_action
       OR NEW.loss_amount_minor_units IS DISTINCT FROM OLD.loss_amount_minor_units
       OR NEW.currency IS DISTINCT FROM OLD.currency
       OR NEW.confidentiality IS DISTINCT FROM OLD.confidentiality
       OR NEW.opened_by_membership_id IS DISTINCT FROM OLD.opened_by_membership_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Risk Incident source identity is immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER risk_incident_history
BEFORE UPDATE OR DELETE ON risk_incidents
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_protect_incident_history();

CREATE OR REPLACE FUNCTION pbr_f6d_incident_update_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Risk Incident updates are append-only';
END;
$$;

CREATE TRIGGER risk_incident_updates_append_only
BEFORE UPDATE OR DELETE ON risk_incident_updates
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_incident_update_append_only();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6d_protect_completed_risk_test()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.completed_at IS NOT NULL THEN
            RAISE EXCEPTION 'Completed Risk Control Test is immutable';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.completed_at IS NOT NULL THEN
        RAISE EXCEPTION 'Completed Risk Control Test is immutable';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.formal_record_version_id IS DISTINCT FROM OLD.formal_record_version_id
       OR NEW.risk_item_id IS DISTINCT FROM OLD.risk_item_id
       OR NEW.risk_protection_record_id IS DISTINCT FROM OLD.risk_protection_record_id
       OR NEW.control_name IS DISTINCT FROM OLD.control_name
       OR NEW.scenario IS DISTINCT FROM OLD.scenario
       OR NEW.owner_membership_id IS DISTINCT FROM OLD.owner_membership_id
       OR NEW.confidentiality IS DISTINCT FROM OLD.confidentiality THEN
        RAISE EXCEPTION 'Risk Control Test source identity is immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER risk_test_history
BEFORE UPDATE OR DELETE ON risk_control_tests
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_protect_completed_risk_test();
SQL);
    }

    private function backfillRiskCapabilities(): void
    {
        $now = now();
        $permissionIds = [];

        foreach ($this->riskCapabilities as $key) {
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
            'Workspace Owner' => $this->riskCapabilities,
            'Partner' => ['risk.view'],
            'Managing Partner / CEO' => $this->riskCapabilities,
            'Finance Owner' => ['risk.view'],
            'Governance Secretary / PBR Administrator' => $this->riskCapabilities,
            'Auditor / Viewer' => ['risk.view'],
            'External Accountant / Legal Advisor' => ['risk.view'],
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
            ['risk_control_tests', 'risk_test_history'],
            ['risk_incident_updates', 'risk_incident_updates_append_only'],
            ['risk_incidents', 'risk_incident_history'],
            ['risk_items', 'risk_items_validate'],
            ['risk_protection_records', 'risk_protection_mutable'],
            ['risk_items', 'risk_items_mutable'],
            ['risk_register_versions', 'risk_register_header_mutable'],
        ] as [$table, $trigger]) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }

        foreach ([
            'pbr_f6d_protect_completed_risk_test',
            'pbr_f6d_incident_update_append_only',
            'pbr_f6d_protect_incident_history',
            'pbr_f6d_validate_risk_item',
            'pbr_f6d_risk_snapshot_mutable',
        ] as $function) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
        }

        Schema::dropIfExists('risk_control_tests');
        Schema::dropIfExists('risk_incident_updates');
        Schema::dropIfExists('risk_incidents');
        Schema::dropIfExists('risk_protection_records');
        Schema::dropIfExists('risk_items');
        Schema::dropIfExists('risk_register_versions');
    }
};
