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
    private array $continuityCapabilities = [
        'continuity.view',
        'continuity.manage',
    ];

    public function up(): void
    {
        $this->backfillContinuityCapabilities();

        Schema::create('continuity_plan_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('operations_formal_record_version_id');
            $table->uuid('continuity_owner_membership_id');
            $table->string('governance_decision_type', 96);
            $table->string('review_frequency', 80);
            $table->string('test_frequency', 80);
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'continuity_versions_id_business_uq');
            $table->unique('formal_record_version_id', 'continuity_versions_record_uq');

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'continuity_versions_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_formal_record_version_id', 'business_id'],
                'continuity_versions_ops_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['continuity_owner_membership_id', 'business_id'],
                'continuity_versions_owner_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('continuity_critical_functions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('operations_role_id');
            $table->string('function_name', 180);
            $table->string('critical_process', 240);
            $table->unsignedInteger('maximum_downtime_minutes');
            $table->uuid('primary_owner_membership_id');
            $table->uuid('first_backup_membership_id');
            $table->uuid('second_backup_membership_id')->nullable();
            $table->unsignedSmallInteger('recovery_priority');
            $table->text('minimum_resources');
            $table->date('review_date')->nullable();
            $table->string('status', 24)->default('ready');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'continuity_functions_id_business_uq');

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'continuity_functions_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_role_id', 'business_id'],
                'continuity_functions_role_fk',
            )->references(['id', 'business_id'])
                ->on('operations_roles')
                ->restrictOnDelete();

            foreach ([
                ['primary_owner_membership_id', 'continuity_functions_primary_fk'],
                ['first_backup_membership_id', 'continuity_functions_backup1_fk'],
                ['second_backup_membership_id', 'continuity_functions_backup2_fk'],
            ] as [$column, $name]) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            }
        });

        Schema::create('continuity_emergency_access_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->string('system_asset', 200);
            $table->uuid('primary_access_membership_id');
            $table->uuid('backup_access_membership_id');
            $table->string('access_level', 120);
            $table->text('emergency_access_procedure');
            $table->string('secure_storage_reference', 240)->nullable();
            $table->date('last_tested_date')->nullable();
            $table->date('review_date')->nullable();
            $table->text('removal_trigger');
            $table->string('status', 24)->default('active');
            $table->string('confidentiality', 16)->default('restricted');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'continuity_access_id_business_uq');

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'continuity_access_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            foreach ([
                ['primary_access_membership_id', 'continuity_access_primary_fk'],
                ['backup_access_membership_id', 'continuity_access_backup_fk'],
            ] as [$column, $name]) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            }
        });

        Schema::create('continuity_interim_authority_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('operations_role_id');
            $table->uuid('interim_membership_id');
            $table->text('trigger');
            $table->string('governance_decision_type', 96);
            $table->bigInteger('spending_limit_minor_units')->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('decision_limit');
            $table->unsignedInteger('maximum_interim_hours');
            $table->text('reporting_requirement');
            $table->string('status', 24)->default('active');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'continuity_interim_id_business_uq');

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'continuity_interim_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_role_id', 'business_id'],
                'continuity_interim_role_fk',
            )->references(['id', 'business_id'])
                ->on('operations_roles')
                ->restrictOnDelete();

            $table->foreign(
                ['interim_membership_id', 'business_id'],
                'continuity_interim_member_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('continuity_successor_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('operations_role_id');
            $table->uuid('current_owner_membership_id');
            $table->uuid('candidate_membership_id');
            $table->string('readiness_level', 24);
            $table->text('skills_gap')->nullable();
            $table->text('development_required')->nullable();
            $table->date('target_ready_date')->nullable();
            $table->string('status', 24)->default('candidate');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'continuity_successors_id_business_uq');

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'continuity_successors_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_role_id', 'business_id'],
                'continuity_successors_role_fk',
            )->references(['id', 'business_id'])
                ->on('operations_roles')
                ->restrictOnDelete();

            foreach ([
                ['current_owner_membership_id', 'continuity_successors_owner_fk'],
                ['candidate_membership_id', 'continuity_successors_candidate_fk'],
            ] as [$column, $name]) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            }
        });

        Schema::create('continuity_communication_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->unsignedInteger('sequence');
            $table->string('event_type', 120);
            $table->string('stakeholder', 200);
            $table->uuid('owner_membership_id');
            $table->string('channel', 80);
            $table->string('timing', 120);
            $table->text('message_reference')->nullable();
            $table->boolean('governance_approval_may_be_required')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['formal_record_version_id', 'sequence'],
                'continuity_comms_record_sequence_uq',
            );

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'continuity_comms_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['owner_membership_id', 'business_id'],
                'continuity_comms_owner_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('continuity_recovery_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->unsignedInteger('sequence');
            $table->string('timeline_band', 24);
            $table->text('action');
            $table->uuid('operations_role_id');
            $table->text('required_resource')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['formal_record_version_id', 'sequence'],
                'continuity_recovery_record_sequence_uq',
            );

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'continuity_recovery_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_role_id', 'business_id'],
                'continuity_recovery_role_fk',
            )->references(['id', 'business_id'])
                ->on('operations_roles')
                ->restrictOnDelete();
        });

        Schema::create('continuity_tests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->string('scenario_name', 200);
            $table->text('scenario');
            $table->timestampTz('tested_at')->nullable();
            $table->string('result', 16)->default('planned');
            $table->text('failed_items')->nullable();
            $table->text('improvement_actions')->nullable();
            $table->uuid('owner_membership_id');
            $table->date('next_test_date')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'continuity_tests_id_business_uq');

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'continuity_tests_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['owner_membership_id', 'business_id'],
                'continuity_tests_owner_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('continuity_emergency_access_activations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('emergency_access_record_id');
            $table->uuid('activated_by_membership_id');
            $table->uuid('emergency_authority_grant_id')->nullable();
            $table->string('required_decision_type', 96)->nullable();
            $table->text('trigger');
            $table->text('reason');
            $table->timestampTz('starts_at');
            $table->timestampTz('expires_at');
            $table->string('status', 16)->default('requested');
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'continuity_activation_id_business_uq');

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'continuity_activation_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['emergency_access_record_id', 'business_id'],
                'continuity_activation_access_fk',
            )->references(['id', 'business_id'])
                ->on('continuity_emergency_access_records')
                ->restrictOnDelete();

            $table->foreign(
                ['activated_by_membership_id', 'business_id'],
                'continuity_activation_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('risk_continuity_action_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('action_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('operations_role_id');
            $table->string('source_type', 48);
            $table->uuid('source_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('action_id', 'risk_continuity_action_action_uq');

            $table->foreign(
                ['action_id', 'business_id'],
                'risk_continuity_action_fk',
            )->references(['id', 'business_id'])
                ->on('actions')
                ->restrictOnDelete();

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'risk_continuity_action_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_role_id', 'business_id'],
                'risk_continuity_action_role_fk',
            )->references(['id', 'business_id'])
                ->on('operations_roles')
                ->restrictOnDelete();
        });

        $this->addChecks();
        $this->addGuards();
    }

    private function addChecks(): void
    {
        DB::statement('ALTER TABLE continuity_critical_functions
            ADD CONSTRAINT continuity_functions_priority_check
            CHECK (recovery_priority BETWEEN 1 AND 5)');

        DB::statement("ALTER TABLE continuity_critical_functions
            ADD CONSTRAINT continuity_functions_status_check
            CHECK (status IN ('ready','partial','gap','inactive'))");

        DB::statement("ALTER TABLE continuity_emergency_access_records
            ADD CONSTRAINT continuity_access_confidentiality_check
            CHECK (confidentiality = 'restricted')");

        DB::statement("ALTER TABLE continuity_interim_authority_plans
            ADD CONSTRAINT continuity_interim_status_check
            CHECK (status IN ('active','inactive'))");

        DB::statement("ALTER TABLE continuity_successor_candidates
            ADD CONSTRAINT continuity_successor_readiness_check
            CHECK (readiness_level IN ('not_ready','developing','ready','ready_now'))");

        DB::statement("ALTER TABLE continuity_recovery_actions
            ADD CONSTRAINT continuity_recovery_band_check
            CHECK (timeline_band IN ('0_24_hours','1_7_days','7_30_days'))");

        DB::statement("ALTER TABLE continuity_tests
            ADD CONSTRAINT continuity_test_result_check
            CHECK (result IN ('planned','running','passed','partial','failed'))");

        DB::statement("ALTER TABLE continuity_emergency_access_activations
            ADD CONSTRAINT continuity_activation_status_check
            CHECK (status IN ('requested','active','expired','revoked','closed'))");

        DB::statement('ALTER TABLE continuity_emergency_access_activations
            ADD CONSTRAINT continuity_activation_time_check
            CHECK (expires_at > starts_at)');

        DB::statement("ALTER TABLE risk_continuity_action_links
            ADD CONSTRAINT risk_continuity_action_source_check
            CHECK (source_type IN (
                'risk_item','risk_incident','risk_control_test',
                'continuity_critical_function','continuity_test',
                'emergency_access_activation'
            ))");
    }

    private function addGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6d_continuity_snapshot_mutable()
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
        RAISE EXCEPTION 'Frozen Continuity Plan snapshot is immutable';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

CREATE TRIGGER continuity_header_mutable
BEFORE UPDATE OR DELETE ON continuity_plan_versions
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_continuity_snapshot_mutable();

CREATE TRIGGER continuity_functions_mutable
BEFORE INSERT OR UPDATE OR DELETE ON continuity_critical_functions
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_continuity_snapshot_mutable();

CREATE TRIGGER continuity_access_mutable
BEFORE INSERT OR UPDATE OR DELETE ON continuity_emergency_access_records
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_continuity_snapshot_mutable();

CREATE TRIGGER continuity_interim_mutable
BEFORE INSERT OR UPDATE OR DELETE ON continuity_interim_authority_plans
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_continuity_snapshot_mutable();

CREATE TRIGGER continuity_successors_mutable
BEFORE INSERT OR UPDATE OR DELETE ON continuity_successor_candidates
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_continuity_snapshot_mutable();

CREATE TRIGGER continuity_comms_mutable
BEFORE INSERT OR UPDATE OR DELETE ON continuity_communication_steps
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_continuity_snapshot_mutable();

CREATE TRIGGER continuity_recovery_mutable
BEFORE INSERT OR UPDATE OR DELETE ON continuity_recovery_actions
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_continuity_snapshot_mutable();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6d_validate_continuity_role()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_ops_version uuid;
    v_role_version uuid;
BEGIN
    SELECT operations_formal_record_version_id INTO v_ops_version
      FROM continuity_plan_versions
     WHERE business_id = NEW.business_id
       AND formal_record_version_id = NEW.formal_record_version_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Continuity child requires same-Business Continuity Plan Version';
    END IF;

    SELECT formal_record_version_id INTO v_role_version
      FROM operations_roles
     WHERE id = NEW.operations_role_id
       AND business_id = NEW.business_id;

    IF v_role_version IS DISTINCT FROM v_ops_version THEN
        RAISE EXCEPTION 'Continuity role must bind exact Operations Version';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER continuity_functions_role_validate
BEFORE INSERT OR UPDATE ON continuity_critical_functions
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_validate_continuity_role();

CREATE TRIGGER continuity_interim_role_validate
BEFORE INSERT OR UPDATE ON continuity_interim_authority_plans
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_validate_continuity_role();

CREATE TRIGGER continuity_successor_role_validate
BEFORE INSERT OR UPDATE ON continuity_successor_candidates
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_validate_continuity_role();

CREATE TRIGGER continuity_recovery_role_validate
BEFORE INSERT OR UPDATE ON continuity_recovery_actions
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_validate_continuity_role();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6d_protect_completed_continuity_test()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.completed_at IS NOT NULL THEN
            RAISE EXCEPTION 'Completed Continuity Test is immutable';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.completed_at IS NOT NULL THEN
        RAISE EXCEPTION 'Completed Continuity Test is immutable';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.formal_record_version_id IS DISTINCT FROM OLD.formal_record_version_id
       OR NEW.scenario_name IS DISTINCT FROM OLD.scenario_name
       OR NEW.scenario IS DISTINCT FROM OLD.scenario
       OR NEW.owner_membership_id IS DISTINCT FROM OLD.owner_membership_id THEN
        RAISE EXCEPTION 'Continuity Test source identity is immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER continuity_test_history
BEFORE UPDATE OR DELETE ON continuity_tests
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_protect_completed_continuity_test();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6d_validate_emergency_activation()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_access_version uuid;
    v_grantee uuid;
    v_decision_type text;
    v_scope text;
    v_effective timestamptz;
    v_expires timestamptz;
    v_status text;
    v_authorized boolean;
BEGIN
    SELECT formal_record_version_id INTO v_access_version
      FROM continuity_emergency_access_records
     WHERE id = NEW.emergency_access_record_id
       AND business_id = NEW.business_id;

    IF v_access_version IS DISTINCT FROM NEW.formal_record_version_id THEN
        RAISE EXCEPTION 'Emergency Access Activation must bind exact Continuity Plan Version';
    END IF;

    IF NEW.required_decision_type IS NULL AND NEW.emergency_authority_grant_id IS NOT NULL THEN
        RAISE EXCEPTION 'Governance Emergency Authority cannot be attached without exact required Decision Type';
    END IF;

    IF NEW.required_decision_type IS NOT NULL THEN
        IF NEW.emergency_authority_grant_id IS NULL THEN
            RAISE EXCEPTION 'Governance authority-required activation needs exact authorized Emergency Authority Grant';
        END IF;

        SELECT grantee_membership_id, decision_type, scope, effective_from, expires_at, status
          INTO v_grantee, v_decision_type, v_scope, v_effective, v_expires, v_status
          FROM emergency_authority_grants
         WHERE id = NEW.emergency_authority_grant_id
           AND business_id = NEW.business_id;

        IF NOT FOUND
           OR v_grantee IS DISTINCT FROM NEW.activated_by_membership_id
           OR v_decision_type IS DISTINCT FROM NEW.required_decision_type
           OR v_scope IS DISTINCT FROM ('continuity_emergency_access:' || NEW.emergency_access_record_id::text)
           OR v_status <> 'active'
           OR NEW.starts_at < v_effective
           OR NEW.expires_at > v_expires THEN
            RAISE EXCEPTION 'Emergency Access Activation cannot expand governed Emergency Authority';
        END IF;

        SELECT EXISTS (
            SELECT 1
              FROM governance_authority_change_submissions change_record
             WHERE change_record.business_id = NEW.business_id
               AND change_record.subject_type = 'emergency_authority'
               AND change_record.subject_id = NEW.emergency_authority_grant_id
               AND change_record.action = 'grant'
               AND change_record.authorized_at IS NOT NULL
        ) INTO v_authorized;

        IF NOT v_authorized THEN
            RAISE EXCEPTION 'Emergency Authority Grant is not governed/authorized';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER continuity_activation_validate
BEFORE INSERT OR UPDATE ON continuity_emergency_access_activations
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_validate_emergency_activation();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6d_protect_emergency_activation()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Emergency Access Activation history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.formal_record_version_id IS DISTINCT FROM OLD.formal_record_version_id
       OR NEW.emergency_access_record_id IS DISTINCT FROM OLD.emergency_access_record_id
       OR NEW.activated_by_membership_id IS DISTINCT FROM OLD.activated_by_membership_id
       OR NEW.emergency_authority_grant_id IS DISTINCT FROM OLD.emergency_authority_grant_id
       OR NEW.required_decision_type IS DISTINCT FROM OLD.required_decision_type
       OR NEW.trigger IS DISTINCT FROM OLD.trigger
       OR NEW.reason IS DISTINCT FROM OLD.reason
       OR NEW.starts_at IS DISTINCT FROM OLD.starts_at
       OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Emergency Access Activation source identity is immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER continuity_activation_history
BEFORE UPDATE OR DELETE ON continuity_emergency_access_activations
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_protect_emergency_activation();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6d_validate_action_link()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_action_version uuid;
    v_role_version uuid;
BEGIN
    SELECT formal_record_version_id INTO v_action_version
      FROM actions
     WHERE id = NEW.action_id
       AND business_id = NEW.business_id;

    SELECT formal_record_version_id INTO v_role_version
      FROM operations_roles
     WHERE id = NEW.operations_role_id
       AND business_id = NEW.business_id;

    IF v_action_version IS DISTINCT FROM v_role_version THEN
        RAISE EXCEPTION 'Risk/Continuity Action must preserve exact Operations source binding';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER risk_continuity_action_validate
BEFORE INSERT ON risk_continuity_action_links
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_validate_action_link();

CREATE OR REPLACE FUNCTION pbr_f6d_action_link_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Risk/Continuity Action link is append-only';
END;
$$;

CREATE TRIGGER risk_continuity_action_append_only
BEFORE UPDATE OR DELETE ON risk_continuity_action_links
FOR EACH ROW EXECUTE FUNCTION pbr_f6d_action_link_append_only();
SQL);
    }

    private function backfillContinuityCapabilities(): void
    {
        $now = now();
        $permissionIds = [];

        foreach ($this->continuityCapabilities as $key) {
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
            'Workspace Owner' => $this->continuityCapabilities,
            'Partner' => ['continuity.view'],
            'Managing Partner / CEO' => $this->continuityCapabilities,
            'Finance Owner' => ['continuity.view'],
            'Governance Secretary / PBR Administrator' => $this->continuityCapabilities,
            'Auditor / Viewer' => ['continuity.view'],
            'External Accountant / Legal Advisor' => ['continuity.view'],
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
            ['risk_continuity_action_links', 'risk_continuity_action_append_only'],
            ['risk_continuity_action_links', 'risk_continuity_action_validate'],
            ['continuity_emergency_access_activations', 'continuity_activation_history'],
            ['continuity_emergency_access_activations', 'continuity_activation_validate'],
            ['continuity_tests', 'continuity_test_history'],
            ['continuity_recovery_actions', 'continuity_recovery_role_validate'],
            ['continuity_successor_candidates', 'continuity_successor_role_validate'],
            ['continuity_interim_authority_plans', 'continuity_interim_role_validate'],
            ['continuity_critical_functions', 'continuity_functions_role_validate'],
            ['continuity_recovery_actions', 'continuity_recovery_mutable'],
            ['continuity_communication_steps', 'continuity_comms_mutable'],
            ['continuity_successor_candidates', 'continuity_successors_mutable'],
            ['continuity_interim_authority_plans', 'continuity_interim_mutable'],
            ['continuity_emergency_access_records', 'continuity_access_mutable'],
            ['continuity_critical_functions', 'continuity_functions_mutable'],
            ['continuity_plan_versions', 'continuity_header_mutable'],
        ] as [$table, $trigger]) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }

        foreach ([
            'pbr_f6d_action_link_append_only',
            'pbr_f6d_validate_action_link',
            'pbr_f6d_protect_emergency_activation',
            'pbr_f6d_validate_emergency_activation',
            'pbr_f6d_protect_completed_continuity_test',
            'pbr_f6d_validate_continuity_role',
            'pbr_f6d_continuity_snapshot_mutable',
        ] as $function) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
        }

        Schema::dropIfExists('risk_continuity_action_links');
        Schema::dropIfExists('continuity_emergency_access_activations');
        Schema::dropIfExists('continuity_tests');
        Schema::dropIfExists('continuity_recovery_actions');
        Schema::dropIfExists('continuity_communication_steps');
        Schema::dropIfExists('continuity_successor_candidates');
        Schema::dropIfExists('continuity_interim_authority_plans');
        Schema::dropIfExists('continuity_emergency_access_records');
        Schema::dropIfExists('continuity_critical_functions');
        Schema::dropIfExists('continuity_plan_versions');
    }
};
