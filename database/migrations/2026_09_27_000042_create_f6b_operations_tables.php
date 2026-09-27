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
    private array $operationsCapabilities = [
        'operations.view',
        'operations.manage',
    ];

    public function up(): void
    {
        $this->backfillOperationsCapabilities();

        Schema::create('operations_register_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->string('organization_name', 160);
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'ops_versions_id_business_uq');
            $table->unique('formal_record_version_id', 'ops_versions_record_uq');

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'ops_versions_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();
        });

        Schema::create('operations_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->string('role_key', 96);
            $table->string('name', 160);
            $table->string('function_name', 160);
            $table->text('purpose');
            $table->text('responsibilities');
            $table->text('operational_authority')->nullable();
            $table->string('reports_to_role_key', 96)->nullable();
            $table->string('report_type', 120)->nullable();
            $table->string('reporting_frequency', 80)->nullable();
            $table->string('meeting_frequency', 80)->nullable();
            $table->string('review_frequency', 80);
            $table->string('status', 24)->default('active');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'ops_roles_id_business_uq');
            $table->unique(
                ['formal_record_version_id', 'role_key'],
                'ops_roles_record_key_uq',
            );

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'ops_roles_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();
        });

        Schema::create('operations_role_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('operations_role_id');
            $table->uuid('membership_id');
            $table->string('assignment_type', 16);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['operations_role_id', 'membership_id', 'assignment_type'],
                'ops_assignments_role_member_type_uq',
            );

            $table->foreign(
                ['operations_role_id', 'business_id'],
                'ops_assignments_role_fk',
            )->references(['id', 'business_id'])
                ->on('operations_roles')
                ->restrictOnDelete();

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'ops_assignments_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['membership_id', 'business_id'],
                'ops_assignments_member_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('operations_raci_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->unsignedInteger('sequence');
            $table->string('activity', 200);
            $table->string('result', 200)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'ops_raci_items_id_business_uq');
            $table->unique(
                ['formal_record_version_id', 'sequence'],
                'ops_raci_items_record_sequence_uq',
            );

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'ops_raci_items_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();
        });

        Schema::create('operations_raci_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('operations_raci_item_id');
            $table->uuid('operations_role_id');
            $table->string('responsibility', 2);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['operations_raci_item_id', 'operations_role_id'],
                'ops_raci_assignments_item_role_uq',
            );

            $table->foreign(
                ['operations_raci_item_id', 'business_id'],
                'ops_raci_assignments_item_fk',
            )->references(['id', 'business_id'])
                ->on('operations_raci_items')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_role_id', 'business_id'],
                'ops_raci_assignments_role_fk',
            )->references(['id', 'business_id'])
                ->on('operations_roles')
                ->restrictOnDelete();
        });

        Schema::create('operations_kpis', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('operations_role_id');
            $table->string('name', 160);
            $table->string('target', 200);
            $table->string('measurement_method', 240);
            $table->string('frequency', 80);
            $table->string('current_status', 32)->default('not_started');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'ops_kpis_id_business_uq');

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'ops_kpis_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_role_id', 'business_id'],
                'ops_kpis_role_fk',
            )->references(['id', 'business_id'])
                ->on('operations_roles')
                ->restrictOnDelete();
        });

        Schema::create('governance_meetings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('authority_source_formal_record_version_id');
            $table->string('title', 200);
            $table->string('meeting_type', 48)->default('governance');
            $table->string('status', 24)->default('scheduled');
            $table->timestampTz('scheduled_at');
            $table->timestampTz('notice_sent_at')->nullable();
            $table->unsignedSmallInteger('quorum_required');
            $table->unsignedSmallInteger('quorum_present')->default(0);
            $table->uuid('agenda_owner_membership_id');
            $table->uuid('minutes_owner_membership_id');
            $table->text('agenda');
            $table->text('minutes')->nullable();
            $table->uuid('created_by_membership_id');
            $table->timestampTz('held_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'gov_meetings_id_business_uq');

            $table->foreign(
                ['authority_source_formal_record_version_id', 'business_id'],
                'gov_meetings_authority_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            foreach ([
                ['agenda_owner_membership_id', 'gov_meetings_agenda_owner_fk'],
                ['minutes_owner_membership_id', 'gov_meetings_minutes_owner_fk'],
                ['created_by_membership_id', 'gov_meetings_creator_fk'],
            ] as [$column, $name]) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            }
        });

        Schema::create('governance_meeting_attendees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('governance_meeting_id');
            $table->uuid('membership_id');
            $table->string('attendance_status', 16);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['governance_meeting_id', 'membership_id'],
                'gov_meeting_attendees_meeting_member_uq',
            );

            $table->foreign(
                ['governance_meeting_id', 'business_id'],
                'gov_meeting_attendees_meeting_fk',
            )->references(['id', 'business_id'])
                ->on('governance_meetings')
                ->restrictOnDelete();

            $table->foreign(
                ['membership_id', 'business_id'],
                'gov_meeting_attendees_member_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::table('decisions', function (Blueprint $table): void {
            $table->uuid('meeting_id')->nullable()->after('decision_amount');

            $table->foreign(
                ['meeting_id', 'business_id'],
                'decisions_meeting_business_fk',
            )->references(['id', 'business_id'])
                ->on('governance_meetings')
                ->restrictOnDelete();
        });

        Schema::create('operations_action_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('action_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('operations_role_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('action_id', 'ops_action_links_action_uq');

            $table->foreign(
                ['action_id', 'business_id'],
                'ops_action_links_action_fk',
            )->references(['id', 'business_id'])
                ->on('actions')
                ->restrictOnDelete();

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'ops_action_links_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_role_id', 'business_id'],
                'ops_action_links_role_fk',
            )->references(['id', 'business_id'])
                ->on('operations_roles')
                ->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE operations_roles
             ADD CONSTRAINT ops_roles_status_ck
             CHECK (status IN ('active', 'inactive'))",
        );
        DB::statement(
            "ALTER TABLE operations_role_assignments
             ADD CONSTRAINT ops_assignments_type_ck
             CHECK (assignment_type IN ('primary', 'backup'))",
        );
        DB::statement(
            'ALTER TABLE operations_raci_items
             ADD CONSTRAINT ops_raci_items_sequence_ck
             CHECK (sequence > 0)',
        );
        DB::statement(
            "ALTER TABLE operations_raci_assignments
             ADD CONSTRAINT ops_raci_responsibility_ck
             CHECK (responsibility IN ('A', 'R', 'AR', 'C', 'I'))",
        );
        DB::statement(
            "ALTER TABLE operations_kpis
             ADD CONSTRAINT ops_kpis_status_ck
             CHECK (current_status IN (
                 'not_started',
                 'on_track',
                 'at_risk',
                 'off_track',
                 'achieved'
             ))",
        );
        DB::statement(
            "ALTER TABLE governance_meetings
             ADD CONSTRAINT gov_meetings_status_ck
             CHECK (status IN ('scheduled', 'held', 'cancelled'))",
        );
        DB::statement(
            'ALTER TABLE governance_meetings
             ADD CONSTRAINT gov_meetings_quorum_ck
             CHECK (quorum_required > 0 AND quorum_present >= 0)',
        );
        DB::statement(
            "ALTER TABLE governance_meetings
             ADD CONSTRAINT gov_meetings_lifecycle_ck
             CHECK (
                 (
                     status = 'scheduled'
                     AND held_at IS NULL
                     AND cancelled_at IS NULL
                 )
                 OR
                 (
                     status = 'held'
                     AND held_at IS NOT NULL
                     AND cancelled_at IS NULL
                     AND minutes IS NOT NULL
                 )
                 OR
                 (
                     status = 'cancelled'
                     AND held_at IS NULL
                     AND cancelled_at IS NOT NULL
                 )
             )",
        );
        DB::statement(
            "ALTER TABLE governance_meeting_attendees
             ADD CONSTRAINT gov_meeting_attendance_ck
             CHECK (attendance_status IN ('invited', 'present', 'remote', 'absent', 'recused'))",
        );

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS decisions_validate_insert ON decisions;
DROP TRIGGER IF EXISTS decisions_protect_history ON decisions;

CREATE OR REPLACE FUNCTION pbr_f6_validate_decision()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    snapshot_decision_type text;
    snapshot_amount_min numeric;
    snapshot_amount_max numeric;
    snapshot_meeting_required boolean;
    snapshot_source_version uuid;

    meeting_status_value text;
    meeting_source_version uuid;
    meeting_quorum_required integer;
    meeting_quorum_present integer;
    meeting_held_at timestamptz;
BEGIN
    SELECT
        decision_type,
        amount_min,
        amount_max,
        meeting_required,
        source_formal_record_version_id
    INTO
        snapshot_decision_type,
        snapshot_amount_min,
        snapshot_amount_max,
        snapshot_meeting_required,
        snapshot_source_version
    FROM authority_snapshots
    WHERE id = NEW.authority_snapshot_id
      AND business_id = NEW.business_id
      AND proposal_version_id = NEW.proposal_version_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Decision Authority Snapshot binding is invalid';
    END IF;

    IF
        NEW.status <> 'open'
        OR NEW.outcome IS NOT NULL
        OR NEW.resolved_at IS NOT NULL
    THEN
        RAISE EXCEPTION 'Decision must begin Open with no outcome';
    END IF;

    IF NEW.decision_type IS DISTINCT FROM snapshot_decision_type THEN
        RAISE EXCEPTION 'Decision type must match Authority Snapshot';
    END IF;

    IF snapshot_amount_min IS NOT NULL AND NEW.decision_amount IS NULL THEN
        RAISE EXCEPTION 'Decision amount is required for this authority threshold';
    END IF;

    IF
        snapshot_amount_min IS NOT NULL
        AND NEW.decision_amount < snapshot_amount_min
    THEN
        RAISE EXCEPTION 'Decision amount is below the captured authority threshold';
    END IF;

    IF
        snapshot_amount_max IS NOT NULL
        AND NEW.decision_amount > snapshot_amount_max
    THEN
        RAISE EXCEPTION 'Decision amount exceeds the captured authority threshold';
    END IF;

    IF snapshot_meeting_required AND NEW.meeting_id IS NULL THEN
        RAISE EXCEPTION 'Captured Governance rule requires a qualifying Meeting';
    END IF;

    IF NEW.meeting_id IS NOT NULL THEN
        SELECT
            status,
            authority_source_formal_record_version_id,
            quorum_required,
            quorum_present,
            held_at
        INTO
            meeting_status_value,
            meeting_source_version,
            meeting_quorum_required,
            meeting_quorum_present,
            meeting_held_at
        FROM governance_meetings
        WHERE id = NEW.meeting_id
          AND business_id = NEW.business_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Decision Meeting does not exist in this Business';
        END IF;

        IF meeting_status_value <> 'held' THEN
            RAISE EXCEPTION 'Decision Meeting must be Held';
        END IF;

        IF meeting_source_version IS DISTINCT FROM snapshot_source_version THEN
            RAISE EXCEPTION 'Decision Meeting must bind the same captured Governance authority source';
        END IF;

        IF meeting_quorum_present < meeting_quorum_required THEN
            RAISE EXCEPTION 'Decision Meeting quorum was not met';
        END IF;

        IF meeting_held_at IS NULL OR meeting_held_at > NEW.opened_at THEN
            RAISE EXCEPTION 'Decision cannot rely on a Meeting that was not already Held';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER decisions_validate_insert
BEFORE INSERT ON decisions
FOR EACH ROW EXECUTE FUNCTION pbr_f6_validate_decision();

CREATE OR REPLACE FUNCTION pbr_f6_protect_decision()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Decision history cannot be deleted';
    END IF;

    IF
        NEW.business_id IS DISTINCT FROM OLD.business_id
        OR NEW.proposal_version_id IS DISTINCT FROM OLD.proposal_version_id
        OR NEW.authority_snapshot_id IS DISTINCT FROM OLD.authority_snapshot_id
        OR NEW.decision_type IS DISTINCT FROM OLD.decision_type
        OR NEW.decision_amount IS DISTINCT FROM OLD.decision_amount
        OR NEW.meeting_id IS DISTINCT FROM OLD.meeting_id
        OR NEW.opened_by_membership_id IS DISTINCT FROM OLD.opened_by_membership_id
        OR NEW.opened_at IS DISTINCT FROM OLD.opened_at
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Decision identity and frozen bindings are immutable';
    END IF;

    IF OLD.status <> 'open' THEN
        RAISE EXCEPTION 'resolved Decision history is immutable';
    END IF;

    IF NEW.status NOT IN ('decided', 'cancelled') THEN
        RAISE EXCEPTION 'Decision may only resolve from Open to Decided or Cancelled';
    END IF;

    IF NEW.status = 'decided' AND NEW.outcome = 'approved' THEN
        PERFORM pbr_f6_assert_decision_approval_ready(
            OLD.id,
            OLD.business_id
        );
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER decisions_protect_history
BEFORE UPDATE OR DELETE ON decisions
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_decision();

CREATE OR REPLACE FUNCTION pbr_f6_assert_operations_mutable(
    target_record_version uuid,
    target_business uuid
)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    target_frozen_at timestamptz;
    target_record_type text;
BEGIN
    SELECT v.frozen_at, f.record_type
      INTO target_frozen_at, target_record_type
      FROM formal_record_versions v
      JOIN formal_record_families f
        ON f.id = v.formal_record_family_id
       AND f.business_id = v.business_id
     WHERE v.id = target_record_version
       AND v.business_id = target_business;

    IF NOT FOUND OR target_record_type <> 'operations_register' THEN
        RAISE EXCEPTION 'Operations content requires an Operations Register formal record version';
    END IF;

    IF target_frozen_at IS NOT NULL THEN
        RAISE EXCEPTION 'Frozen Operations Register content is immutable';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION pbr_f6_protect_operations_direct()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN
        PERFORM pbr_f6_assert_operations_mutable(
            OLD.formal_record_version_id,
            OLD.business_id
        );
    END IF;

    IF TG_OP <> 'DELETE' THEN
        PERFORM pbr_f6_assert_operations_mutable(
            NEW.formal_record_version_id,
            NEW.business_id
        );
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

CREATE TRIGGER ops_versions_mutable
BEFORE INSERT OR UPDATE OR DELETE ON operations_register_versions
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_operations_direct();

CREATE TRIGGER ops_roles_mutable
BEFORE INSERT OR UPDATE OR DELETE ON operations_roles
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_operations_direct();

CREATE OR REPLACE FUNCTION pbr_f6_validate_operations_reporting_line()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.reports_to_role_key IS NULL THEN
        RETURN NEW;
    END IF;

    IF NEW.reports_to_role_key = NEW.role_key THEN
        RAISE EXCEPTION 'Operations Role cannot report to itself';
    END IF;

    /*
     * Inserts can arrive before the referenced Role in one draft snapshot, so
     * application validation owns creation-order safety. Updates, however,
     * must never point outside the exact Register version.
     */
    IF TG_OP = 'UPDATE'
       AND NOT EXISTS (
            SELECT 1
              FROM operations_roles parent
             WHERE parent.business_id = NEW.business_id
               AND parent.formal_record_version_id = NEW.formal_record_version_id
               AND parent.role_key = NEW.reports_to_role_key
       )
    THEN
        RAISE EXCEPTION 'Operations reporting line must remain inside the same Register version';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER ops_roles_reporting_line
BEFORE INSERT OR UPDATE ON operations_roles
FOR EACH ROW EXECUTE FUNCTION pbr_f6_validate_operations_reporting_line();

CREATE TRIGGER ops_assignments_mutable
BEFORE INSERT OR UPDATE OR DELETE ON operations_role_assignments
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_operations_direct();

CREATE TRIGGER ops_raci_items_mutable
BEFORE INSERT OR UPDATE OR DELETE ON operations_raci_items
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_operations_direct();

CREATE TRIGGER ops_kpis_mutable
BEFORE INSERT OR UPDATE OR DELETE ON operations_kpis
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_operations_direct();

CREATE OR REPLACE FUNCTION pbr_f6_validate_operations_role_binding()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    role_version uuid;
BEGIN
    SELECT formal_record_version_id
      INTO role_version
      FROM operations_roles
     WHERE id = NEW.operations_role_id
       AND business_id = NEW.business_id;

    IF role_version IS NULL
       OR role_version IS DISTINCT FROM NEW.formal_record_version_id
    THEN
        RAISE EXCEPTION 'Operations child must bind a Role from the same Register version';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER ops_assignments_role_version
BEFORE INSERT OR UPDATE ON operations_role_assignments
FOR EACH ROW EXECUTE FUNCTION pbr_f6_validate_operations_role_binding();

CREATE TRIGGER ops_kpis_role_version
BEFORE INSERT OR UPDATE ON operations_kpis
FOR EACH ROW EXECUTE FUNCTION pbr_f6_validate_operations_role_binding();

CREATE OR REPLACE FUNCTION pbr_f6_validate_operations_raci_binding()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    item_version uuid;
    role_version uuid;
BEGIN
    SELECT formal_record_version_id
      INTO item_version
      FROM operations_raci_items
     WHERE id = NEW.operations_raci_item_id
       AND business_id = NEW.business_id;

    SELECT formal_record_version_id
      INTO role_version
      FROM operations_roles
     WHERE id = NEW.operations_role_id
       AND business_id = NEW.business_id;

    IF item_version IS NULL
       OR role_version IS NULL
       OR item_version IS DISTINCT FROM role_version
    THEN
        RAISE EXCEPTION 'RACI Assignment item and Role must belong to the same Register version';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER ops_raci_assignments_version
BEFORE INSERT OR UPDATE ON operations_raci_assignments
FOR EACH ROW EXECUTE FUNCTION pbr_f6_validate_operations_raci_binding();

CREATE OR REPLACE FUNCTION pbr_f6_protect_raci_assignment()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    target_version uuid;
    target_business uuid;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        SELECT formal_record_version_id, business_id
          INTO target_version, target_business
          FROM operations_raci_items
         WHERE id = OLD.operations_raci_item_id
           AND business_id = OLD.business_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Operations RACI item does not exist';
        END IF;

        PERFORM pbr_f6_assert_operations_mutable(target_version, target_business);
    END IF;

    IF TG_OP <> 'DELETE' THEN
        SELECT formal_record_version_id, business_id
          INTO target_version, target_business
          FROM operations_raci_items
         WHERE id = NEW.operations_raci_item_id
           AND business_id = NEW.business_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Operations RACI item does not exist';
        END IF;

        PERFORM pbr_f6_assert_operations_mutable(target_version, target_business);
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

CREATE TRIGGER ops_raci_assignments_mutable
BEFORE INSERT OR UPDATE OR DELETE ON operations_raci_assignments
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_raci_assignment();

CREATE OR REPLACE FUNCTION pbr_f6_protect_meeting_history()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Governance Meeting history cannot be deleted';
    END IF;

    IF OLD.status IN ('held', 'cancelled') THEN
        RAISE EXCEPTION 'Held or Cancelled Governance Meeting is immutable';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.authority_source_formal_record_version_id IS DISTINCT FROM OLD.authority_source_formal_record_version_id
       OR NEW.created_by_membership_id IS DISTINCT FROM OLD.created_by_membership_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Governance Meeting source identity is immutable';
    END IF;

    IF NEW.revision <> OLD.revision + 1 THEN
        RAISE EXCEPTION 'Governance Meeting revision must advance exactly once';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER gov_meetings_history
BEFORE UPDATE OR DELETE ON governance_meetings
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_meeting_history();

CREATE OR REPLACE FUNCTION pbr_f6_protect_meeting_attendee()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    meeting_status text;
BEGIN
    SELECT status INTO meeting_status
      FROM governance_meetings
     WHERE id = COALESCE(NEW.governance_meeting_id, OLD.governance_meeting_id)
       AND business_id = COALESCE(NEW.business_id, OLD.business_id);

    IF meeting_status IS DISTINCT FROM 'scheduled' THEN
        RAISE EXCEPTION 'Meeting attendance list is mutable only while Scheduled';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

CREATE TRIGGER gov_meeting_attendees_mutable
BEFORE INSERT OR UPDATE OR DELETE ON governance_meeting_attendees
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_meeting_attendee();

CREATE OR REPLACE FUNCTION pbr_f6_protect_operations_action_link()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Operations Action source link is append-only';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM record_family_effective_heads h
          JOIN formal_record_versions v
            ON v.id = h.formal_record_version_id
           AND v.business_id = h.business_id
          JOIN formal_record_families f
            ON f.id = v.formal_record_family_id
           AND f.business_id = v.business_id
         WHERE h.business_id = NEW.business_id
           AND h.formal_record_version_id = NEW.formal_record_version_id
           AND f.record_type = 'operations_register'
    ) THEN
        RAISE EXCEPTION 'Operations Action must bind the Current Effective Operations Register';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM operations_roles r
         WHERE r.id = NEW.operations_role_id
           AND r.business_id = NEW.business_id
           AND r.formal_record_version_id = NEW.formal_record_version_id
    ) THEN
        RAISE EXCEPTION 'Operations Action role must belong to the same Effective Operations Register';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER ops_action_links_protect
BEFORE INSERT OR UPDATE OR DELETE ON operations_action_links
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_operations_action_link();
SQL);
    }

    private function backfillOperationsCapabilities(): void
    {
        $now = now();
        $permissionIds = [];

        foreach ($this->operationsCapabilities as $key) {
            $existing = DB::table('permissions')
                ->where('key', $key)
                ->value('id');

            if ($existing === null) {
                $existing = (string) Str::uuid7();

                DB::table('permissions')->insert([
                    'id' => $existing,
                    'key' => $key,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $permissionIds[$key] = (string) $existing;
        }

        $matrix = [
            'Workspace Owner' => $this->operationsCapabilities,
            'Partner' => ['operations.view'],
            'Managing Partner / CEO' => $this->operationsCapabilities,
            'Finance Owner' => ['operations.view'],
            'Governance Secretary / PBR Administrator' => $this->operationsCapabilities,
            'Advisor / Consultant' => ['operations.view'],
            'Auditor / Viewer' => ['operations.view'],
            'External Accountant / Legal Advisor' => ['operations.view'],
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
        DB::statement(
            'DROP TRIGGER IF EXISTS ops_action_links_protect
             ON operations_action_links',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS gov_meeting_attendees_mutable
             ON governance_meeting_attendees',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS gov_meetings_history
             ON governance_meetings',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS ops_raci_assignments_mutable
             ON operations_raci_assignments',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS ops_raci_assignments_version
             ON operations_raci_assignments',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS ops_kpis_role_version
             ON operations_kpis',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS ops_assignments_role_version
             ON operations_role_assignments',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS ops_roles_reporting_line
             ON operations_roles',
        );

        foreach ([
            'operations_kpis' => 'ops_kpis_mutable',
            'operations_raci_items' => 'ops_raci_items_mutable',
            'operations_role_assignments' => 'ops_assignments_mutable',
            'operations_roles' => 'ops_roles_mutable',
            'operations_register_versions' => 'ops_versions_mutable',
        ] as $table => $trigger) {
            DB::statement(sprintf(
                'DROP TRIGGER IF EXISTS %s ON %s',
                $trigger,
                $table,
            ));
        }

        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_protect_operations_action_link()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_protect_meeting_attendee()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_protect_meeting_history()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_protect_raci_assignment()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_validate_operations_raci_binding()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_validate_operations_role_binding()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_validate_operations_reporting_line()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_protect_operations_direct()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_assert_operations_mutable(uuid, uuid)');

        DB::statement(
            'DROP TRIGGER IF EXISTS decisions_protect_history ON decisions',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS decisions_validate_insert ON decisions',
        );
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_protect_decision()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_validate_decision()');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER decisions_validate_insert
BEFORE INSERT ON decisions
FOR EACH ROW EXECUTE FUNCTION pbr_validate_decision();

CREATE TRIGGER decisions_protect_history
BEFORE UPDATE OR DELETE ON decisions
FOR EACH ROW EXECUTE FUNCTION pbr_protect_decision();
SQL);

        Schema::dropIfExists('operations_action_links');

        Schema::table('decisions', function (Blueprint $table): void {
            $table->dropForeign('decisions_meeting_business_fk');
            $table->dropColumn('meeting_id');
        });

        Schema::dropIfExists('governance_meeting_attendees');
        Schema::dropIfExists('governance_meetings');
        Schema::dropIfExists('operations_kpis');
        Schema::dropIfExists('operations_raci_assignments');
        Schema::dropIfExists('operations_raci_items');
        Schema::dropIfExists('operations_role_assignments');
        Schema::dropIfExists('operations_roles');
        Schema::dropIfExists('operations_register_versions');
    }
};
