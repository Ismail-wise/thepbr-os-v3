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
        Schema::table('authority_snapshots', function (Blueprint $table): void {
            $table->string('source_kind', 48)
                ->default('formation_authority')
                ->after('source_content_hash');
            $table->boolean('meeting_required')
                ->default(false)
                ->after('reserved_matter');
            $table->boolean('record_required')
                ->default(true)
                ->after('meeting_required');
        });

        Schema::table('emergency_authority_grants', function (Blueprint $table): void {
            $table->string('capacity', 120)->nullable()->after('scope');
            $table->boolean('can_approve')->nullable()->after('capacity');
            $table->boolean('can_vote')->nullable()->after('can_approve');
            $table->boolean('can_sign')->nullable()->after('can_vote');
        });

        Schema::create('governance_charter_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('governance_owner_membership_id');
            $table->string('voting_basis', 64);
            $table->string('default_approval_rule', 160);
            $table->string('meeting_frequency', 80)->nullable();
            $table->unsignedSmallInteger('default_quorum_count');
            $table->uuid('minutes_owner_membership_id');
            $table->text('conflict_of_interest_rule');
            $table->text('deadlock_rule');
            $table->boolean('remote_voting_allowed')->default(false);
            $table->boolean('written_resolution_allowed')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'gov_charter_versions_id_business_uq');
            $table->unique(
                ['formal_record_version_id'],
                'gov_charter_versions_record_uq',
            );

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'gov_charter_versions_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['governance_owner_membership_id', 'business_id'],
                'gov_charter_versions_owner_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign(
                ['minutes_owner_membership_id', 'business_id'],
                'gov_charter_versions_minutes_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('governance_charter_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->unsignedInteger('sequence');
            $table->string('decision_type', 160);
            $table->string('category', 48);
            $table->string('decision_method', 32);
            $table->unsignedSmallInteger('required_approvals')->default(0);
            $table->unsignedSmallInteger('required_votes')->default(0);
            $table->unsignedSmallInteger('quorum_count');
            $table->boolean('signature_required')->default(false);
            $table->boolean('reserved_matter')->default(false);
            $table->boolean('meeting_required')->default(false);
            $table->boolean('record_required')->default(true);
            $table->decimal('amount_min', 20, 2)->nullable();
            $table->decimal('amount_max', 20, 2)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'gov_charter_rules_id_business_uq');
            $table->unique(
                ['formal_record_version_id', 'sequence'],
                'gov_charter_rules_record_sequence_uq',
            );

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'gov_charter_rules_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->index(
                ['business_id', 'formal_record_version_id', 'decision_type'],
                'gov_charter_rules_lookup_idx',
            );
        });

        Schema::create('governance_charter_rule_actors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('governance_charter_rule_id');
            $table->uuid('membership_id');
            $table->string('capacity', 120);
            $table->boolean('is_decision_owner')->default(false);
            $table->boolean('is_consulted')->default(false);
            $table->boolean('can_approve')->default(false);
            $table->boolean('can_vote')->default(false);
            $table->boolean('can_sign')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['governance_charter_rule_id', 'membership_id'],
                'gov_charter_actors_rule_member_uq',
            );

            $table->foreign(
                ['governance_charter_rule_id', 'business_id'],
                'gov_charter_actors_rule_fk',
            )->references(['id', 'business_id'])
                ->on('governance_charter_rules')
                ->restrictOnDelete();

            $table->foreign(
                ['membership_id', 'business_id'],
                'gov_charter_actors_member_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create(
            'governance_authority_change_submissions',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->string('subject_type', 32);
                $table->uuid('subject_id');
                $table->string('action', 16)->default('grant');
                $table->char('content_hash', 64);
                $table->uuid('proposal_id');
                $table->uuid('proposal_version_id');
                $table->uuid('authorizing_decision_id')->nullable();
                $table->uuid('created_by_membership_id');
                $table->timestampTz('authorized_at')->nullable();
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['subject_type', 'subject_id', 'action'],
                    'gov_auth_changes_subject_action_uq',
                );
                $table->unique(
                    ['proposal_version_id'],
                    'gov_auth_changes_proposal_version_uq',
                );

                $table->foreign(
                    ['proposal_id', 'business_id'],
                    'gov_auth_changes_proposal_fk',
                )->references(['id', 'business_id'])
                    ->on('proposals')
                    ->restrictOnDelete();

                $table->foreign(
                    ['proposal_version_id', 'business_id'],
                    'gov_auth_changes_proposal_version_fk',
                )->references(['id', 'business_id'])
                    ->on('proposal_versions')
                    ->restrictOnDelete();

                $table->foreign(
                    ['authorizing_decision_id', 'business_id'],
                    'gov_auth_changes_decision_fk',
                )->references(['id', 'business_id'])
                    ->on('decisions')
                    ->restrictOnDelete();

                $table->foreign(
                    ['created_by_membership_id', 'business_id'],
                    'gov_auth_changes_creator_fk',
                )->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        DB::statement(
            'ALTER TABLE governance_charter_versions
             ADD CONSTRAINT gov_charter_versions_quorum_ck
             CHECK (default_quorum_count > 0)',
        );
        DB::statement(
            'ALTER TABLE governance_charter_rules
             ADD CONSTRAINT gov_charter_rules_sequence_ck
             CHECK (sequence > 0)',
        );
        DB::statement(
            "ALTER TABLE governance_charter_rules
             ADD CONSTRAINT gov_charter_rules_category_ck
             CHECK (category IN (
                 'daily_operating',
                 'management',
                 'major_business',
                 'ownership_structural',
                 'custom'
             ))",
        );
        DB::statement(
            "ALTER TABLE governance_charter_rules
             ADD CONSTRAINT gov_charter_rules_method_ck
             CHECK (decision_method IN (
                 'approval',
                 'vote',
                 'approval_and_vote'
             ))",
        );
        DB::statement(
            "ALTER TABLE governance_charter_rules
             ADD CONSTRAINT gov_charter_rules_threshold_ck
             CHECK (
                 (
                     decision_method = 'approval'
                     AND required_approvals > 0
                     AND required_votes = 0
                 )
                 OR
                 (
                     decision_method = 'vote'
                     AND required_votes > 0
                     AND required_approvals = 0
                 )
                 OR
                 (
                     decision_method = 'approval_and_vote'
                     AND required_approvals > 0
                     AND required_votes > 0
                 )
             )",
        );
        DB::statement(
            'ALTER TABLE governance_charter_rules
             ADD CONSTRAINT gov_charter_rules_quorum_ck
             CHECK (quorum_count > 0)',
        );
        DB::statement(
            'ALTER TABLE governance_charter_rules
             ADD CONSTRAINT gov_charter_rules_amount_ck
             CHECK (
                 (amount_min IS NULL OR amount_min >= 0)
                 AND (amount_max IS NULL OR amount_max >= 0)
                 AND (
                     amount_min IS NULL
                     OR amount_max IS NULL
                     OR amount_max >= amount_min
                 )
             )',
        );
        DB::statement(
            "ALTER TABLE governance_charter_rule_actors
             ADD CONSTRAINT gov_charter_actors_capacity_ck
             CHECK (btrim(capacity) <> '')",
        );
        DB::statement(
            'ALTER TABLE governance_charter_rule_actors
             ADD CONSTRAINT gov_charter_actors_role_ck
             CHECK (
                 is_decision_owner
                 OR is_consulted
                 OR can_approve
                 OR can_vote
                 OR can_sign
             )',
        );
        DB::statement(
            "ALTER TABLE emergency_authority_grants
             ADD CONSTRAINT emergency_authority_capabilities_ck
             CHECK (
                 (
                     capacity IS NULL
                     AND can_approve IS NULL
                     AND can_vote IS NULL
                     AND can_sign IS NULL
                 )
                 OR
                 (
                     capacity IS NOT NULL
                     AND btrim(capacity) <> ''
                     AND can_approve IS NOT NULL
                     AND can_vote IS NOT NULL
                     AND can_sign IS NOT NULL
                     AND (can_approve OR can_vote OR can_sign)
                 )
             )",
        );
        DB::statement(
            "ALTER TABLE governance_authority_change_submissions
             ADD CONSTRAINT gov_auth_changes_subject_ck
             CHECK (subject_type IN ('delegation', 'emergency_authority'))",
        );
        DB::statement(
            "ALTER TABLE governance_authority_change_submissions
             ADD CONSTRAINT gov_auth_changes_action_ck
             CHECK (action IN ('grant', 'revoke'))",
        );
        DB::statement(
            "ALTER TABLE governance_authority_change_submissions
             ADD CONSTRAINT gov_auth_changes_hash_ck
             CHECK (content_hash ~ '^[0-9a-f]{64}$')",
        );
        DB::statement(
            'ALTER TABLE governance_authority_change_submissions
             ADD CONSTRAINT gov_auth_changes_authorized_ck
             CHECK (
                 (authorizing_decision_id IS NULL AND authorized_at IS NULL)
                 OR
                 (authorizing_decision_id IS NOT NULL AND authorized_at IS NOT NULL)
             )',
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6_assert_charter_mutable(
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

    IF NOT FOUND OR target_record_type <> 'governance_charter' THEN
        RAISE EXCEPTION 'Governance Charter content requires a Governance Charter formal record version';
    END IF;

    IF target_frozen_at IS NOT NULL THEN
        RAISE EXCEPTION 'Frozen Governance Charter content is immutable';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION pbr_f6_protect_charter_version()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN
        PERFORM pbr_f6_assert_charter_mutable(
            OLD.formal_record_version_id,
            OLD.business_id
        );
    END IF;

    IF TG_OP <> 'DELETE' THEN
        PERFORM pbr_f6_assert_charter_mutable(
            NEW.formal_record_version_id,
            NEW.business_id
        );
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

CREATE TRIGGER gov_charter_versions_mutable
BEFORE INSERT OR UPDATE OR DELETE ON governance_charter_versions
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_charter_version();

CREATE OR REPLACE FUNCTION pbr_f6_protect_charter_rule()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    target_version uuid;
    target_business uuid;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        target_version := OLD.formal_record_version_id;
        target_business := OLD.business_id;
        PERFORM pbr_f6_assert_charter_mutable(target_version, target_business);
    END IF;

    IF TG_OP <> 'DELETE' THEN
        target_version := NEW.formal_record_version_id;
        target_business := NEW.business_id;
        PERFORM pbr_f6_assert_charter_mutable(target_version, target_business);
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

CREATE TRIGGER gov_charter_rules_mutable
BEFORE INSERT OR UPDATE OR DELETE ON governance_charter_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_charter_rule();

CREATE OR REPLACE FUNCTION pbr_f6_protect_charter_actor()
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
          FROM governance_charter_rules
         WHERE id = OLD.governance_charter_rule_id
           AND business_id = OLD.business_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Governance Charter rule does not exist';
        END IF;

        PERFORM pbr_f6_assert_charter_mutable(target_version, target_business);
    END IF;

    IF TG_OP <> 'DELETE' THEN
        SELECT formal_record_version_id, business_id
          INTO target_version, target_business
          FROM governance_charter_rules
         WHERE id = NEW.governance_charter_rule_id
           AND business_id = NEW.business_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Governance Charter rule does not exist';
        END IF;

        PERFORM pbr_f6_assert_charter_mutable(target_version, target_business);
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

CREATE TRIGGER gov_charter_actors_mutable
BEFORE INSERT OR UPDATE OR DELETE ON governance_charter_rule_actors
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_charter_actor();

CREATE OR REPLACE FUNCTION pbr_f6_validate_authority_change()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    proposal_business uuid;
    proposal_parent_id uuid;
    proposal_hash text;
    proposal_frozen timestamptz;
    subject_business uuid;
BEGIN
    SELECT business_id, proposal_id, proposal_content_hash, frozen_at
      INTO proposal_business, proposal_parent_id, proposal_hash, proposal_frozen
      FROM proposal_versions
     WHERE id = NEW.proposal_version_id;

    IF proposal_business IS DISTINCT FROM NEW.business_id
       OR proposal_parent_id IS DISTINCT FROM NEW.proposal_id
       OR proposal_frozen IS NULL
       OR proposal_hash IS DISTINCT FROM NEW.content_hash
    THEN
        RAISE EXCEPTION 'Authority change must bind the exact Frozen Proposal Version/hash';
    END IF;

    IF NEW.subject_type = 'delegation' THEN
        SELECT business_id INTO subject_business
          FROM governance_delegations
         WHERE id = NEW.subject_id;
    ELSE
        SELECT business_id INTO subject_business
          FROM emergency_authority_grants
         WHERE id = NEW.subject_id;
    END IF;

    IF subject_business IS DISTINCT FROM NEW.business_id THEN
        RAISE EXCEPTION 'Authority change subject must belong to the same Business';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER gov_auth_changes_validate
BEFORE INSERT ON governance_authority_change_submissions
FOR EACH ROW EXECUTE FUNCTION pbr_f6_validate_authority_change();

CREATE OR REPLACE FUNCTION pbr_f6_protect_authority_change()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Governance authority change history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.subject_type IS DISTINCT FROM OLD.subject_type
       OR NEW.subject_id IS DISTINCT FROM OLD.subject_id
       OR NEW.action IS DISTINCT FROM OLD.action
       OR NEW.content_hash IS DISTINCT FROM OLD.content_hash
       OR NEW.proposal_id IS DISTINCT FROM OLD.proposal_id
       OR NEW.proposal_version_id IS DISTINCT FROM OLD.proposal_version_id
       OR NEW.created_by_membership_id IS DISTINCT FROM OLD.created_by_membership_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Governance authority change frozen bindings are immutable';
    END IF;

    IF OLD.authorizing_decision_id IS NOT NULL
       OR OLD.authorized_at IS NOT NULL
       OR NEW.authorizing_decision_id IS NULL
       OR NEW.authorized_at IS NULL
    THEN
        RAISE EXCEPTION 'Governance authority change may be authorized exactly once';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER gov_auth_changes_protect
BEFORE UPDATE OR DELETE ON governance_authority_change_submissions
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_authority_change();

DROP TRIGGER IF EXISTS authority_snapshots_validate ON authority_snapshots;

CREATE OR REPLACE FUNCTION pbr_f6_validate_authority_snapshot()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    source_record_type text;
    source_frozen_at timestamptz;
    source_hash text;
    latest_source_state text;

    rule_decision_type text;
    rule_decision_method text;
    rule_required_approvals integer;
    rule_required_votes integer;
    rule_quorum_count integer;
    rule_signature_required boolean;
    rule_reserved_matter boolean;
    rule_meeting_required boolean;
    rule_record_required boolean;
    rule_amount_min numeric;
    rule_amount_max numeric;

    has_effective_charter boolean;
    is_initial_bootstrap boolean;
    is_current_effective boolean;
BEGIN
    SELECT
        family.record_type,
        version.frozen_at,
        version.content_hash
    INTO
        source_record_type,
        source_frozen_at,
        source_hash
    FROM formal_record_versions version
    JOIN formal_record_families family
      ON family.id = version.formal_record_family_id
     AND family.business_id = version.business_id
    WHERE version.id = NEW.source_formal_record_version_id
      AND version.business_id = NEW.business_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'authority source record version does not exist in this Business';
    END IF;

    IF source_frozen_at IS NULL THEN
        RAISE EXCEPTION 'authority source record version must be frozen';
    END IF;

    IF NEW.source_content_hash IS DISTINCT FROM source_hash THEN
        RAISE EXCEPTION 'authority snapshot source hash must match exact source record version';
    END IF;

    SELECT to_state
      INTO latest_source_state
      FROM record_version_state_transitions
     WHERE formal_record_version_id = NEW.source_formal_record_version_id
     ORDER BY sequence DESC
     LIMIT 1;

    SELECT EXISTS (
        SELECT 1
          FROM record_family_effective_heads head
          JOIN formal_record_versions version
            ON version.id = head.formal_record_version_id
           AND version.business_id = head.business_id
          JOIN formal_record_families family
            ON family.id = version.formal_record_family_id
           AND family.business_id = version.business_id
         WHERE head.business_id = NEW.business_id
           AND family.record_type = 'governance_charter'
    )
    INTO has_effective_charter;

    SELECT EXISTS (
        SELECT 1
          FROM record_family_effective_heads head
         WHERE head.business_id = NEW.business_id
           AND head.formal_record_version_id =
               NEW.source_formal_record_version_id
    )
    INTO is_current_effective;

    IF NEW.source_kind = 'governance_charter' THEN
        IF source_record_type <> 'governance_charter' THEN
            RAISE EXCEPTION 'Governance Charter snapshot source kind/type mismatch';
        END IF;

        IF NOT is_current_effective OR latest_source_state <> 'effective' THEN
            RAISE EXCEPTION 'Governance Charter authority must be the Current Effective version';
        END IF;

        SELECT
            rule.decision_type,
            rule.decision_method,
            rule.required_approvals,
            rule.required_votes,
            rule.quorum_count,
            rule.signature_required,
            rule.reserved_matter,
            rule.meeting_required,
            rule.record_required,
            rule.amount_min,
            rule.amount_max
        INTO
            rule_decision_type,
            rule_decision_method,
            rule_required_approvals,
            rule_required_votes,
            rule_quorum_count,
            rule_signature_required,
            rule_reserved_matter,
            rule_meeting_required,
            rule_record_required,
            rule_amount_min,
            rule_amount_max
        FROM governance_charter_rules rule
        WHERE rule.business_id = NEW.business_id
          AND rule.formal_record_version_id =
              NEW.source_formal_record_version_id
          AND rule.sequence = NEW.source_rule_sequence;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Governance Charter authority rule does not exist';
        END IF;
    ELSIF NEW.source_kind = 'formation_authority' THEN
        IF source_record_type <> 'formation_authority_policy' THEN
            RAISE EXCEPTION 'Formation Authority snapshot source kind/type mismatch';
        END IF;

        IF has_effective_charter THEN
            RAISE EXCEPTION 'Temporary Formation Authority cannot authorize after Governance becomes Effective';
        END IF;

        SELECT
            EXISTS (
                SELECT 1
                  FROM formation_authority_establishments establishment
                 WHERE establishment.business_id = NEW.business_id
                   AND establishment.formal_record_version_id =
                       NEW.source_formal_record_version_id
            )
            AND NOT EXISTS (
                SELECT 1
                  FROM record_family_effective_heads current_head
                  JOIN formal_record_versions current_version
                    ON current_version.id =
                       current_head.formal_record_version_id
                   AND current_version.business_id =
                       current_head.business_id
                  JOIN formal_record_families current_family
                    ON current_family.id =
                       current_version.formal_record_family_id
                   AND current_family.business_id =
                       current_head.business_id
                 WHERE current_head.business_id = NEW.business_id
                   AND current_family.record_type =
                       'formation_authority_policy'
            )
        INTO is_initial_bootstrap;

        IF NOT is_initial_bootstrap AND NOT is_current_effective THEN
            RAISE EXCEPTION 'Formation Authority source must be bootstrap or Current Effective while Governance is not yet Effective';
        END IF;

        IF
            is_initial_bootstrap
            AND latest_source_state NOT IN (
                'ready_for_review',
                'under_review',
                'approved',
                'ready_for_effect',
                'effective'
            )
        THEN
            RAISE EXCEPTION 'initial Formation Authority source is not authority-bearing';
        END IF;

        IF is_current_effective AND latest_source_state <> 'effective' THEN
            RAISE EXCEPTION 'current Formation Authority source must be Effective';
        END IF;

        SELECT
            rule.decision_type,
            rule.decision_method,
            rule.required_approvals,
            rule.required_votes,
            rule.quorum_count,
            rule.signature_required,
            rule.reserved_matter,
            false,
            true,
            rule.amount_min,
            rule.amount_max
        INTO
            rule_decision_type,
            rule_decision_method,
            rule_required_approvals,
            rule_required_votes,
            rule_quorum_count,
            rule_signature_required,
            rule_reserved_matter,
            rule_meeting_required,
            rule_record_required,
            rule_amount_min,
            rule_amount_max
        FROM formation_authority_policy_rules rule
        WHERE rule.business_id = NEW.business_id
          AND rule.formal_record_version_id =
              NEW.source_formal_record_version_id
          AND rule.sequence = NEW.source_rule_sequence;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Formation Authority source rule does not exist';
        END IF;
    ELSE
        RAISE EXCEPTION 'Unsupported governance authority source kind';
    END IF;

    IF
        NEW.decision_type IS DISTINCT FROM rule_decision_type
        OR NEW.decision_method IS DISTINCT FROM rule_decision_method
        OR NEW.required_approvals IS DISTINCT FROM rule_required_approvals
        OR NEW.required_votes IS DISTINCT FROM rule_required_votes
        OR NEW.quorum_count IS DISTINCT FROM rule_quorum_count
        OR NEW.signature_required IS DISTINCT FROM rule_signature_required
        OR NEW.reserved_matter IS DISTINCT FROM rule_reserved_matter
        OR NEW.meeting_required IS DISTINCT FROM rule_meeting_required
        OR NEW.record_required IS DISTINCT FROM rule_record_required
        OR NEW.amount_min IS DISTINCT FROM rule_amount_min
        OR NEW.amount_max IS DISTINCT FROM rule_amount_max
    THEN
        RAISE EXCEPTION 'Authority Snapshot must exactly capture the applicable centralized authority rule';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER authority_snapshots_validate
BEFORE INSERT ON authority_snapshots
FOR EACH ROW EXECUTE FUNCTION pbr_f6_validate_authority_snapshot();

DROP TRIGGER IF EXISTS decision_participants_validate ON decision_participants;

CREATE OR REPLACE FUNCTION pbr_f6_validate_decision_participant()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    decision_status text;
    source_kind_value text;
    source_version uuid;
    source_sequence integer;
    captured_at_value timestamptz;
    decision_type_value text;
    source_rule_id uuid;

    direct_capacity text;
    direct_can_approve boolean;
    direct_can_vote boolean;
    direct_can_sign boolean;
    direct_delegated boolean;

    delegated_capacity text;
    delegated_can_approve boolean;
    delegated_can_vote boolean;
    delegated_can_sign boolean;
    delegated_match_count integer;

    emergency_capacity text;
    emergency_can_approve boolean;
    emergency_can_vote boolean;
    emergency_can_sign boolean;
    emergency_match_count integer;

    actor_match_count integer := 0;
BEGIN
    SELECT status
      INTO decision_status
      FROM decisions
     WHERE id = NEW.decision_id
       AND business_id = NEW.business_id
       AND proposal_version_id = NEW.proposal_version_id
       AND authority_snapshot_id = NEW.authority_snapshot_id;

    IF decision_status IS DISTINCT FROM 'open' THEN
        RAISE EXCEPTION 'Decision Participant requires an Open Decision';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM memberships membership
         WHERE membership.id = NEW.membership_id
           AND membership.business_id = NEW.business_id
           AND membership.access_status = 'active'
    ) THEN
        RAISE EXCEPTION 'Decision Participant requires an active Membership in the same Business';
    END IF;

    SELECT
        source_kind,
        source_formal_record_version_id,
        source_rule_sequence,
        captured_at,
        decision_type
    INTO
        source_kind_value,
        source_version,
        source_sequence,
        captured_at_value,
        decision_type_value
    FROM authority_snapshots
    WHERE id = NEW.authority_snapshot_id
      AND business_id = NEW.business_id
      AND proposal_version_id = NEW.proposal_version_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Decision Participant Authority Snapshot does not exist';
    END IF;

    IF source_kind_value = 'governance_charter' THEN
        SELECT id
          INTO source_rule_id
          FROM governance_charter_rules
         WHERE business_id = NEW.business_id
           AND formal_record_version_id = source_version
           AND sequence = source_sequence;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Decision Participant Governance Charter rule is missing';
        END IF;

        SELECT
            capacity,
            can_approve,
            can_vote,
            can_sign
        INTO
            direct_capacity,
            direct_can_approve,
            direct_can_vote,
            direct_can_sign
        FROM governance_charter_rule_actors
        WHERE business_id = NEW.business_id
          AND governance_charter_rule_id = source_rule_id
          AND membership_id = NEW.membership_id
          AND (can_approve OR can_vote OR can_sign);
    ELSE
        SELECT id
          INTO source_rule_id
          FROM formation_authority_policy_rules
         WHERE business_id = NEW.business_id
           AND formal_record_version_id = source_version
           AND sequence = source_sequence;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Decision Participant Formation Authority rule is missing';
        END IF;

        SELECT
            capacity,
            can_approve,
            can_vote,
            can_sign
        INTO
            direct_capacity,
            direct_can_approve,
            direct_can_vote,
            direct_can_sign
        FROM formation_authority_policy_actors
        WHERE business_id = NEW.business_id
          AND formation_authority_policy_rule_id = source_rule_id
          AND membership_id = NEW.membership_id;
    END IF;

    IF direct_capacity IS NOT NULL THEN
        SELECT EXISTS (
            SELECT 1
              FROM governance_delegations delegation
              JOIN governance_authority_change_submissions change_record
                ON change_record.business_id = delegation.business_id
               AND change_record.subject_type = 'delegation'
               AND change_record.subject_id = delegation.id
               AND change_record.action = 'grant'
               AND change_record.authorized_at IS NOT NULL
               AND change_record.authorized_at <= captured_at_value
             WHERE delegation.business_id = NEW.business_id
               AND delegation.delegator_membership_id = NEW.membership_id
               AND delegation.decision_type = decision_type_value
               AND delegation.effective_from <= captured_at_value
               AND (
                    delegation.expires_at IS NULL
                    OR delegation.expires_at > captured_at_value
               )
               AND (
                    delegation.revoked_at IS NULL
                    OR delegation.revoked_at > captured_at_value
               )
        )
        INTO direct_delegated;

        IF NOT direct_delegated THEN
            actor_match_count := actor_match_count + 1;

            IF
                NEW.capacity IS DISTINCT FROM direct_capacity
                OR NEW.can_approve IS DISTINCT FROM direct_can_approve
                OR NEW.can_vote IS DISTINCT FROM direct_can_vote
                OR NEW.can_sign IS DISTINCT FROM direct_can_sign
            THEN
                RAISE EXCEPTION 'Decision Participant must exactly capture direct authority';
            END IF;
        END IF;
    END IF;

    IF source_kind_value = 'governance_charter' THEN
        SELECT
            count(*),
            min('Delegated: ' || base_actor.capacity),
            bool_or(base_actor.can_approve),
            bool_or(base_actor.can_vote),
            bool_or(base_actor.can_sign)
        INTO
            delegated_match_count,
            delegated_capacity,
            delegated_can_approve,
            delegated_can_vote,
            delegated_can_sign
        FROM governance_charter_rule_actors base_actor
        JOIN governance_delegations delegation
          ON delegation.business_id = base_actor.business_id
         AND delegation.delegator_membership_id =
             base_actor.membership_id
         AND delegation.delegate_membership_id = NEW.membership_id
         AND delegation.decision_type = decision_type_value
         AND delegation.effective_from <= captured_at_value
         AND (
              delegation.expires_at IS NULL
              OR delegation.expires_at > captured_at_value
         )
         AND (
              delegation.revoked_at IS NULL
              OR delegation.revoked_at > captured_at_value
         )
        JOIN governance_authority_change_submissions change_record
          ON change_record.business_id = delegation.business_id
         AND change_record.subject_type = 'delegation'
         AND change_record.subject_id = delegation.id
         AND change_record.action = 'grant'
         AND change_record.authorized_at IS NOT NULL
         AND change_record.authorized_at <= captured_at_value
        WHERE base_actor.business_id = NEW.business_id
          AND base_actor.governance_charter_rule_id = source_rule_id
          AND (base_actor.can_approve OR base_actor.can_vote OR base_actor.can_sign);
    ELSE
        SELECT
            count(*),
            min('Delegated: ' || base_actor.capacity),
            bool_or(base_actor.can_approve),
            bool_or(base_actor.can_vote),
            bool_or(base_actor.can_sign)
        INTO
            delegated_match_count,
            delegated_capacity,
            delegated_can_approve,
            delegated_can_vote,
            delegated_can_sign
        FROM formation_authority_policy_actors base_actor
        JOIN governance_delegations delegation
          ON delegation.business_id = base_actor.business_id
         AND delegation.delegator_membership_id =
             base_actor.membership_id
         AND delegation.delegate_membership_id = NEW.membership_id
         AND delegation.decision_type = decision_type_value
         AND delegation.effective_from <= captured_at_value
         AND (
              delegation.expires_at IS NULL
              OR delegation.expires_at > captured_at_value
         )
         AND (
              delegation.revoked_at IS NULL
              OR delegation.revoked_at > captured_at_value
         )
        JOIN governance_authority_change_submissions change_record
          ON change_record.business_id = delegation.business_id
         AND change_record.subject_type = 'delegation'
         AND change_record.subject_id = delegation.id
         AND change_record.action = 'grant'
         AND change_record.authorized_at IS NOT NULL
         AND change_record.authorized_at <= captured_at_value
        WHERE base_actor.business_id = NEW.business_id
          AND base_actor.formation_authority_policy_rule_id = source_rule_id;
    END IF;

    IF delegated_match_count > 1 THEN
        RAISE EXCEPTION 'Delegation resolves ambiguously to more than one authority seat';
    END IF;

    IF delegated_match_count = 1 THEN
        actor_match_count := actor_match_count + 1;

        IF
            NEW.capacity IS DISTINCT FROM delegated_capacity
            OR NEW.can_approve IS DISTINCT FROM delegated_can_approve
            OR NEW.can_vote IS DISTINCT FROM delegated_can_vote
            OR NEW.can_sign IS DISTINCT FROM delegated_can_sign
        THEN
            RAISE EXCEPTION 'Delegated Participant must preserve the delegator authority exactly';
        END IF;
    END IF;

    SELECT
        count(*),
        min(grant_record.capacity),
        bool_or(grant_record.can_approve),
        bool_or(grant_record.can_vote),
        bool_or(grant_record.can_sign)
    INTO
        emergency_match_count,
        emergency_capacity,
        emergency_can_approve,
        emergency_can_vote,
        emergency_can_sign
    FROM emergency_authority_grants grant_record
    JOIN governance_authority_change_submissions change_record
      ON change_record.business_id = grant_record.business_id
     AND change_record.subject_type = 'emergency_authority'
     AND change_record.subject_id = grant_record.id
     AND change_record.action = 'grant'
     AND change_record.authorized_at IS NOT NULL
     AND change_record.authorized_at <= captured_at_value
    WHERE grant_record.business_id = NEW.business_id
      AND grant_record.grantee_membership_id = NEW.membership_id
      AND grant_record.decision_type = decision_type_value
      AND grant_record.effective_from <= captured_at_value
      AND grant_record.expires_at > captured_at_value
      AND (
           grant_record.revoked_at IS NULL
           OR grant_record.revoked_at > captured_at_value
      );

    IF emergency_match_count > 1 THEN
        RAISE EXCEPTION 'Emergency Authority resolves ambiguously to more than one grant';
    END IF;

    IF emergency_match_count = 1 THEN
        actor_match_count := actor_match_count + 1;

        IF
            NEW.capacity IS DISTINCT FROM emergency_capacity
            OR NEW.can_approve IS DISTINCT FROM emergency_can_approve
            OR NEW.can_vote IS DISTINCT FROM emergency_can_vote
            OR NEW.can_sign IS DISTINCT FROM emergency_can_sign
        THEN
            RAISE EXCEPTION 'Emergency Participant must exactly capture authorized emergency authority';
        END IF;
    END IF;

    IF actor_match_count <> 1 THEN
        RAISE EXCEPTION 'Membership does not resolve to exactly one captured Governance authority seat';
    END IF;

    IF NEW.status <> 'eligible' THEN
        RAISE EXCEPTION 'Decision Participant must begin Eligible before any recusal';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER decision_participants_validate
BEFORE INSERT ON decision_participants
FOR EACH ROW EXECUTE FUNCTION pbr_f6_validate_decision_participant();

CREATE OR REPLACE FUNCTION pbr_f6_assert_decision_approval_ready(
    target_decision_id uuid,
    target_business_id uuid
)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    snapshot_method text;
    required_approvals_count integer;
    required_votes_count integer;
    required_quorum_count integer;
    source_kind_value text;
    source_version_id uuid;
    source_rule_sequence integer;
    captured_at_value timestamptz;
    decision_type_value text;

    source_rule_id uuid;
    base_actor_count integer;
    emergency_actor_count integer;
    expected_participant_count integer;
    participant_count integer;

    approval_requirement_record_id uuid;
    vote_requirement_record_id uuid;
    recorded_approval_count integer;
    supporting_vote_count integer;
    vote_quorum_count integer;
BEGIN
    SELECT
        snapshot.decision_method,
        snapshot.required_approvals,
        snapshot.required_votes,
        snapshot.quorum_count,
        snapshot.source_kind,
        snapshot.source_formal_record_version_id,
        snapshot.source_rule_sequence,
        snapshot.captured_at,
        snapshot.decision_type
    INTO
        snapshot_method,
        required_approvals_count,
        required_votes_count,
        required_quorum_count,
        source_kind_value,
        source_version_id,
        source_rule_sequence,
        captured_at_value,
        decision_type_value
    FROM decisions decision_record
    JOIN authority_snapshots snapshot
      ON snapshot.id = decision_record.authority_snapshot_id
     AND snapshot.business_id = decision_record.business_id
     AND snapshot.proposal_version_id =
         decision_record.proposal_version_id
    WHERE decision_record.id = target_decision_id
      AND decision_record.business_id = target_business_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Decision approval Authority Snapshot is missing';
    END IF;

    IF source_kind_value = 'governance_charter' THEN
        SELECT id
          INTO source_rule_id
          FROM governance_charter_rules
         WHERE business_id = target_business_id
           AND formal_record_version_id = source_version_id
           AND sequence = source_rule_sequence;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Decision approval Governance Charter rule is missing';
        END IF;

        SELECT count(*)
          INTO base_actor_count
          FROM governance_charter_rule_actors
         WHERE business_id = target_business_id
           AND governance_charter_rule_id = source_rule_id
           AND (can_approve OR can_vote OR can_sign);
    ELSE
        SELECT id
          INTO source_rule_id
          FROM formation_authority_policy_rules
         WHERE business_id = target_business_id
           AND formal_record_version_id = source_version_id
           AND sequence = source_rule_sequence;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Decision approval Formation Authority rule is missing';
        END IF;

        SELECT count(*)
          INTO base_actor_count
          FROM formation_authority_policy_actors
         WHERE business_id = target_business_id
           AND formation_authority_policy_rule_id = source_rule_id;
    END IF;

    SELECT count(*)
      INTO emergency_actor_count
      FROM emergency_authority_grants grant_record
      JOIN governance_authority_change_submissions change_record
        ON change_record.business_id = grant_record.business_id
       AND change_record.subject_type = 'emergency_authority'
       AND change_record.subject_id = grant_record.id
       AND change_record.action = 'grant'
       AND change_record.authorized_at IS NOT NULL
       AND change_record.authorized_at <= captured_at_value
     WHERE grant_record.business_id = target_business_id
       AND grant_record.decision_type = decision_type_value
       AND grant_record.effective_from <= captured_at_value
       AND grant_record.expires_at > captured_at_value
       AND (
            grant_record.revoked_at IS NULL
            OR grant_record.revoked_at > captured_at_value
       );

    expected_participant_count :=
        base_actor_count + emergency_actor_count;

    SELECT count(*)
      INTO participant_count
      FROM decision_participants
     WHERE business_id = target_business_id
       AND decision_id = target_decision_id;

    IF base_actor_count < 1 THEN
        RAISE EXCEPTION 'Decision approval source has no explicit eligible authority actors';
    END IF;

    IF participant_count <> expected_participant_count THEN
        RAISE EXCEPTION 'Decision must capture the complete resolved authority actor set before approval';
    END IF;

    IF snapshot_method IN ('approval', 'approval_and_vote') THEN
        SELECT requirement.id
          INTO approval_requirement_record_id
          FROM approval_requirements requirement
         WHERE requirement.business_id = target_business_id
           AND requirement.decision_id = target_decision_id
           AND requirement.requirement_kind = 'approval';

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Approved Decision is missing its Approval Requirement';
        END IF;

        SELECT count(*)
          INTO recorded_approval_count
          FROM approvals approval_record
          JOIN decision_participants participant
            ON participant.id =
               approval_record.decision_participant_id
           AND participant.business_id =
               approval_record.business_id
           AND participant.decision_id =
               approval_record.decision_id
         WHERE approval_record.business_id = target_business_id
           AND approval_record.decision_id = target_decision_id
           AND approval_record.approval_requirement_id =
               approval_requirement_record_id
           AND approval_record.outcome = 'approved'
           AND participant.status = 'eligible';

        IF recorded_approval_count < required_approvals_count THEN
            RAISE EXCEPTION 'Approved Decision has not met its approval threshold';
        END IF;
    END IF;

    IF snapshot_method IN ('vote', 'approval_and_vote') THEN
        SELECT requirement.id
          INTO vote_requirement_record_id
          FROM approval_requirements requirement
         WHERE requirement.business_id = target_business_id
           AND requirement.decision_id = target_decision_id
           AND requirement.requirement_kind = 'vote';

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Approved Decision is missing its Vote Requirement';
        END IF;

        SELECT count(*)
          INTO supporting_vote_count
          FROM votes vote_record
          JOIN decision_participants participant
            ON participant.id =
               vote_record.decision_participant_id
           AND participant.business_id =
               vote_record.business_id
           AND participant.decision_id =
               vote_record.decision_id
         WHERE vote_record.business_id = target_business_id
           AND vote_record.decision_id = target_decision_id
           AND vote_record.approval_requirement_id =
               vote_requirement_record_id
           AND vote_record.choice = 'for'
           AND participant.status = 'eligible';

        SELECT count(*)
          INTO vote_quorum_count
          FROM votes vote_record
          JOIN decision_participants participant
            ON participant.id =
               vote_record.decision_participant_id
           AND participant.business_id =
               vote_record.business_id
           AND participant.decision_id =
               vote_record.decision_id
         WHERE vote_record.business_id = target_business_id
           AND vote_record.decision_id = target_decision_id
           AND vote_record.approval_requirement_id =
               vote_requirement_record_id
           AND vote_record.choice IN ('for', 'against', 'abstain')
           AND participant.status = 'eligible';

        IF supporting_vote_count < required_votes_count THEN
            RAISE EXCEPTION 'Approved Decision has not met its supporting vote threshold';
        END IF;

        IF vote_quorum_count < required_quorum_count THEN
            RAISE EXCEPTION 'Approved Decision has not met its vote quorum';
        END IF;
    END IF;
END;
$$;

DROP TRIGGER IF EXISTS emergency_authority_grants_protect_history
ON emergency_authority_grants;

CREATE OR REPLACE FUNCTION pbr_f6_protect_emergency_authority()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Emergency Authority history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.grantee_membership_id IS DISTINCT FROM OLD.grantee_membership_id
       OR NEW.decision_type IS DISTINCT FROM OLD.decision_type
       OR NEW.scope IS DISTINCT FROM OLD.scope
       OR NEW.capacity IS DISTINCT FROM OLD.capacity
       OR NEW.can_approve IS DISTINCT FROM OLD.can_approve
       OR NEW.can_vote IS DISTINCT FROM OLD.can_vote
       OR NEW.can_sign IS DISTINCT FROM OLD.can_sign
       OR NEW.reason IS DISTINCT FROM OLD.reason
       OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
       OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
       OR NEW.created_by_membership_id IS DISTINCT FROM OLD.created_by_membership_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Emergency Authority scope and source are immutable';
    END IF;

    IF OLD.status <> 'active'
       OR NEW.status <> 'revoked'
       OR NEW.revoked_by_membership_id IS NULL
       OR NEW.revoked_at IS NULL
    THEN
        RAISE EXCEPTION 'Emergency Authority may only be revoked once';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER emergency_authority_grants_protect_history
BEFORE UPDATE OR DELETE ON emergency_authority_grants
FOR EACH ROW EXECUTE FUNCTION pbr_f6_protect_emergency_authority();
SQL);
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS emergency_authority_grants_protect_history
             ON emergency_authority_grants',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS decision_participants_validate
             ON decision_participants',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS authority_snapshots_validate
             ON authority_snapshots',
        );

        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_protect_emergency_authority()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_assert_decision_approval_ready(uuid, uuid)');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_validate_decision_participant()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_validate_authority_snapshot()');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER authority_snapshots_validate
BEFORE INSERT ON authority_snapshots
FOR EACH ROW EXECUTE FUNCTION pbr_validate_authority_snapshot();

CREATE TRIGGER decision_participants_validate
BEFORE INSERT ON decision_participants
FOR EACH ROW EXECUTE FUNCTION pbr_validate_decision_participant();

CREATE TRIGGER emergency_authority_grants_protect_history
BEFORE UPDATE OR DELETE ON emergency_authority_grants
FOR EACH ROW EXECUTE FUNCTION pbr_f3_protect_emergency_authority();
SQL);

        DB::statement(
            'DROP TRIGGER IF EXISTS gov_auth_changes_protect
             ON governance_authority_change_submissions',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS gov_auth_changes_validate
             ON governance_authority_change_submissions',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS gov_charter_actors_mutable
             ON governance_charter_rule_actors',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS gov_charter_rules_mutable
             ON governance_charter_rules',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS gov_charter_versions_mutable
             ON governance_charter_versions',
        );

        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_protect_authority_change()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_validate_authority_change()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_protect_charter_actor()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_protect_charter_rule()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_protect_charter_version()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f6_assert_charter_mutable(uuid, uuid)');

        Schema::dropIfExists('governance_authority_change_submissions');
        Schema::dropIfExists('governance_charter_rule_actors');
        Schema::dropIfExists('governance_charter_rules');
        Schema::dropIfExists('governance_charter_versions');

        DB::statement(
            'ALTER TABLE emergency_authority_grants
             DROP CONSTRAINT IF EXISTS emergency_authority_capabilities_ck',
        );

        Schema::table('emergency_authority_grants', function (Blueprint $table): void {
            $table->dropColumn([
                'capacity',
                'can_approve',
                'can_vote',
                'can_sign',
            ]);
        });

        Schema::table('authority_snapshots', function (Blueprint $table): void {
            $table->dropColumn([
                'source_kind',
                'meeting_required',
                'record_required',
            ]);
        });
    }
};
