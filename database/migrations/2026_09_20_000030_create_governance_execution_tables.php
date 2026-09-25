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
        Schema::create('authority_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('proposal_version_id');
            $table->uuid('source_formal_record_version_id');
            $table->unsignedInteger('source_rule_sequence');
            $table->char('source_content_hash', 64);
            $table->string('decision_type', 160);
            $table->string('decision_method', 32);
            $table->unsignedSmallInteger('required_approvals')->default(0);
            $table->unsignedSmallInteger('required_votes')->default(0);
            $table->unsignedSmallInteger('quorum_count');
            $table->boolean('signature_required')->default(false);
            $table->boolean('reserved_matter')->default(false);
            $table->decimal('amount_min', 20, 2)->nullable();
            $table->decimal('amount_max', 20, 2)->nullable();
            $table->char('snapshot_hash', 64);
            $table->uuid('captured_by_membership_id');
            $table->timestampTz('captured_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['id', 'business_id'],
                'authority_snapshots_id_business_unique',
            );

            $table->unique(
                ['id', 'business_id', 'proposal_version_id'],
                'authority_snapshots_binding_unique',
            );

            $table->unique(
                ['proposal_version_id', 'decision_type'],
                'authority_snapshots_proposal_type_unique',
            );

            $table->foreign(
                ['proposal_version_id', 'business_id'],
                'authority_snapshots_proposal_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('proposal_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['source_formal_record_version_id', 'business_id'],
                'authority_snapshots_source_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['captured_by_membership_id', 'business_id'],
                'authority_snapshots_capturer_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('proposal_version_id');
            $table->uuid('authority_snapshot_id');
            $table->string('decision_type', 160);
            $table->decimal('decision_amount', 20, 2)->nullable();
            $table->string('status', 24)->default('open');
            $table->string('outcome', 24)->nullable();
            $table->uuid('opened_by_membership_id');
            $table->timestampTz('opened_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['id', 'business_id'],
                'decisions_id_business_unique',
            );

            $table->unique(
                [
                    'id',
                    'business_id',
                    'proposal_version_id',
                    'authority_snapshot_id',
                ],
                'decisions_exact_binding_unique',
            );

            $table->unique(
                ['proposal_version_id', 'decision_type'],
                'decisions_proposal_type_unique',
            );

            $table->foreign(
                ['proposal_version_id', 'business_id'],
                'decisions_proposal_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('proposal_versions')
                ->restrictOnDelete();

            $table->foreign(
                [
                    'authority_snapshot_id',
                    'business_id',
                    'proposal_version_id',
                ],
                'decisions_snapshot_binding_fk',
            )
                ->references([
                    'id',
                    'business_id',
                    'proposal_version_id',
                ])
                ->on('authority_snapshots')
                ->restrictOnDelete();

            $table->foreign(
                ['opened_by_membership_id', 'business_id'],
                'decisions_opener_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create(
            'approval_requirements',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('decision_id');
                $table->uuid('proposal_version_id');
                $table->uuid('authority_snapshot_id');
                $table->unsignedInteger('sequence');
                $table->string('requirement_kind', 24);
                $table->unsignedSmallInteger('required_count');
                $table->unsignedSmallInteger('quorum_count')->nullable();
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['id', 'business_id'],
                    'approval_requirements_id_business_unique',
                );

                $table->unique(
                    ['id', 'business_id', 'decision_id'],
                    'approval_requirements_decision_binding_unique',
                );

                $table->unique(
                    ['decision_id', 'sequence'],
                    'approval_requirements_sequence_unique',
                );

                $table->unique(
                    ['decision_id', 'requirement_kind'],
                    'approval_requirements_kind_unique',
                );

                $table->foreign(
                    [
                        'decision_id',
                        'business_id',
                        'proposal_version_id',
                        'authority_snapshot_id',
                    ],
                    'approval_requirements_decision_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                        'proposal_version_id',
                        'authority_snapshot_id',
                    ])
                    ->on('decisions')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'decision_participants',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('decision_id');
                $table->uuid('proposal_version_id');
                $table->uuid('authority_snapshot_id');
                $table->uuid('membership_id');
                $table->string('capacity', 120);
                $table->boolean('can_approve')->default(false);
                $table->boolean('can_vote')->default(false);
                $table->boolean('can_sign')->default(false);
                $table->string('status', 24)->default('eligible');
                $table->text('recusal_reason')->nullable();
                $table->uuid('recused_by_membership_id')->nullable();
                $table->timestampTz('recused_at')->nullable();
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['id', 'business_id'],
                    'decision_participants_id_business_unique',
                );

                $table->unique(
                    ['id', 'business_id', 'decision_id'],
                    'decision_participants_decision_binding_unique',
                );

                $table->unique(
                    ['decision_id', 'membership_id'],
                    'decision_participants_membership_unique',
                );

                $table->foreign(
                    [
                        'decision_id',
                        'business_id',
                        'proposal_version_id',
                        'authority_snapshot_id',
                    ],
                    'decision_participants_decision_fk',
                )
                    ->references([
                        'id',
                        'business_id',
                        'proposal_version_id',
                        'authority_snapshot_id',
                    ])
                    ->on('decisions')
                    ->restrictOnDelete();

                $table->foreign(
                    ['membership_id', 'business_id'],
                    'decision_participants_membership_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();

                $table->foreign(
                    ['recused_by_membership_id', 'business_id'],
                    'decision_participants_recuser_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        Schema::create('votes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('decision_id');
            $table->uuid('proposal_version_id');
            $table->uuid('authority_snapshot_id');
            $table->uuid('approval_requirement_id');
            $table->uuid('decision_participant_id');
            $table->uuid('membership_id');
            $table->string('choice', 24);
            $table->text('rationale')->nullable();
            $table->timestampTz('cast_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['decision_id', 'decision_participant_id'],
                'votes_participant_unique',
            );

            $table->foreign(
                [
                    'decision_id',
                    'business_id',
                    'proposal_version_id',
                    'authority_snapshot_id',
                ],
                'votes_decision_fk',
            )
                ->references([
                    'id',
                    'business_id',
                    'proposal_version_id',
                    'authority_snapshot_id',
                ])
                ->on('decisions')
                ->restrictOnDelete();

            $table->foreign(
                [
                    'approval_requirement_id',
                    'business_id',
                    'decision_id',
                ],
                'votes_requirement_fk',
            )
                ->references([
                    'id',
                    'business_id',
                    'decision_id',
                ])
                ->on('approval_requirements')
                ->restrictOnDelete();

            $table->foreign(
                [
                    'decision_participant_id',
                    'business_id',
                    'decision_id',
                ],
                'votes_participant_fk',
            )
                ->references([
                    'id',
                    'business_id',
                    'decision_id',
                ])
                ->on('decision_participants')
                ->restrictOnDelete();

            $table->foreign(
                ['membership_id', 'business_id'],
                'votes_membership_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('decision_id');
            $table->uuid('proposal_version_id');
            $table->uuid('authority_snapshot_id');
            $table->uuid('approval_requirement_id');
            $table->uuid('decision_participant_id');
            $table->uuid('membership_id');
            $table->string('outcome', 24);
            $table->text('rationale')->nullable();
            $table->timestampTz('recorded_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                [
                    'decision_id',
                    'approval_requirement_id',
                    'decision_participant_id',
                ],
                'approvals_participant_requirement_unique',
            );

            $table->foreign(
                [
                    'decision_id',
                    'business_id',
                    'proposal_version_id',
                    'authority_snapshot_id',
                ],
                'approvals_decision_fk',
            )
                ->references([
                    'id',
                    'business_id',
                    'proposal_version_id',
                    'authority_snapshot_id',
                ])
                ->on('decisions')
                ->restrictOnDelete();

            $table->foreign(
                [
                    'approval_requirement_id',
                    'business_id',
                    'decision_id',
                ],
                'approvals_requirement_fk',
            )
                ->references([
                    'id',
                    'business_id',
                    'decision_id',
                ])
                ->on('approval_requirements')
                ->restrictOnDelete();

            $table->foreign(
                [
                    'decision_participant_id',
                    'business_id',
                    'decision_id',
                ],
                'approvals_participant_fk',
            )
                ->references([
                    'id',
                    'business_id',
                    'decision_id',
                ])
                ->on('decision_participants')
                ->restrictOnDelete();

            $table->foreign(
                ['membership_id', 'business_id'],
                'approvals_membership_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE authority_snapshots
             ADD CONSTRAINT authority_snapshots_source_hash_check
             CHECK (source_content_hash ~ '^[0-9a-f]{64}$')",
        );

        DB::statement(
            "ALTER TABLE authority_snapshots
             ADD CONSTRAINT authority_snapshots_hash_check
             CHECK (snapshot_hash ~ '^[0-9a-f]{64}$')",
        );

        DB::statement(
            'ALTER TABLE authority_snapshots
             ADD CONSTRAINT authority_snapshots_sequence_positive
             CHECK (source_rule_sequence > 0)',
        );

        DB::statement(
            "ALTER TABLE authority_snapshots
             ADD CONSTRAINT authority_snapshots_type_nonblank
             CHECK (
                 btrim(decision_type) <> ''
                 AND decision_type = btrim(decision_type)
             )",
        );

        DB::statement(
            "ALTER TABLE authority_snapshots
             ADD CONSTRAINT authority_snapshots_method_check
             CHECK (
                 decision_method IN (
                     'approval',
                     'vote',
                     'approval_and_vote'
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE authority_snapshots
             ADD CONSTRAINT authority_snapshots_threshold_check
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
            'ALTER TABLE authority_snapshots
             ADD CONSTRAINT authority_snapshots_quorum_positive
             CHECK (quorum_count > 0)',
        );

        DB::statement(
            'ALTER TABLE authority_snapshots
             ADD CONSTRAINT authority_snapshots_amount_range_check
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
            "ALTER TABLE decisions
             ADD CONSTRAINT decisions_status_check
             CHECK (status IN ('open', 'decided', 'cancelled'))",
        );

        DB::statement(
            "ALTER TABLE decisions
             ADD CONSTRAINT decisions_outcome_check
             CHECK (
                 outcome IS NULL
                 OR outcome IN ('approved', 'rejected')
             )",
        );

        DB::statement(
            'ALTER TABLE decisions
             ADD CONSTRAINT decisions_amount_nonnegative
             CHECK (decision_amount IS NULL OR decision_amount >= 0)',
        );

        DB::statement(
            "ALTER TABLE decisions
             ADD CONSTRAINT decisions_lifecycle_check
             CHECK (
                 (
                     status = 'open'
                     AND outcome IS NULL
                     AND resolved_at IS NULL
                 )
                 OR
                 (
                     status = 'decided'
                     AND outcome IN ('approved', 'rejected')
                     AND resolved_at IS NOT NULL
                 )
                 OR
                 (
                     status = 'cancelled'
                     AND outcome IS NULL
                     AND resolved_at IS NOT NULL
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE approval_requirements
             ADD CONSTRAINT approval_requirements_kind_check
             CHECK (requirement_kind IN ('approval', 'vote'))",
        );

        DB::statement(
            'ALTER TABLE approval_requirements
             ADD CONSTRAINT approval_requirements_sequence_positive
             CHECK (sequence > 0)',
        );

        DB::statement(
            'ALTER TABLE approval_requirements
             ADD CONSTRAINT approval_requirements_count_positive
             CHECK (required_count > 0)',
        );

        DB::statement(
            "ALTER TABLE approval_requirements
             ADD CONSTRAINT approval_requirements_quorum_check
             CHECK (
                 (
                     requirement_kind = 'approval'
                     AND quorum_count IS NULL
                 )
                 OR
                 (
                     requirement_kind = 'vote'
                     AND quorum_count IS NOT NULL
                     AND quorum_count > 0
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE decision_participants
             ADD CONSTRAINT decision_participants_capacity_check
             CHECK (
                 btrim(capacity) <> ''
                 AND capacity = btrim(capacity)
             )",
        );

        DB::statement(
            'ALTER TABLE decision_participants
             ADD CONSTRAINT decision_participants_capability_check
             CHECK (can_approve OR can_vote OR can_sign)',
        );

        DB::statement(
            "ALTER TABLE decision_participants
             ADD CONSTRAINT decision_participants_status_check
             CHECK (status IN ('eligible', 'recused'))",
        );

        DB::statement(
            "ALTER TABLE decision_participants
             ADD CONSTRAINT decision_participants_recusal_check
             CHECK (
                 (
                     status = 'eligible'
                     AND recusal_reason IS NULL
                     AND recused_by_membership_id IS NULL
                     AND recused_at IS NULL
                 )
                 OR
                 (
                     status = 'recused'
                     AND btrim(recusal_reason) <> ''
                     AND recused_by_membership_id IS NOT NULL
                     AND recused_at IS NOT NULL
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE votes
             ADD CONSTRAINT votes_choice_check
             CHECK (choice IN ('for', 'against', 'abstain', 'recused'))",
        );

        DB::statement(
            "ALTER TABLE approvals
             ADD CONSTRAINT approvals_outcome_check
             CHECK (outcome IN ('approved', 'rejected'))",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_authority_snapshot()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    source_record_type text;
    source_frozen_at timestamptz;
    source_hash text;
    latest_source_state text;

    rule_id uuid;
    rule_decision_type text;
    rule_decision_method text;
    rule_required_approvals integer;
    rule_required_votes integer;
    rule_quorum_count integer;
    rule_signature_required boolean;
    rule_reserved_matter boolean;
    rule_amount_min numeric;
    rule_amount_max numeric;

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

    IF source_record_type <> 'formation_authority_policy' THEN
        RAISE EXCEPTION 'F3-S1 authority snapshot requires Formation Authority Policy source';
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

    -- Temporary Formation Authority bootstrap is valid only before
    -- this Business has any Current Effective Formation Authority Policy.
    -- Once Effective authority exists, historical bootstrap authority
    -- must never authorize a new Authority Snapshot.
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

    SELECT EXISTS (
        SELECT 1
        FROM record_family_effective_heads head
        WHERE head.business_id = NEW.business_id
          AND head.formal_record_version_id =
              NEW.source_formal_record_version_id
    )
    INTO is_current_effective;

    IF NOT is_initial_bootstrap AND NOT is_current_effective THEN
        RAISE EXCEPTION 'authority source must be initial bootstrap authority or current Effective authority';
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
        RAISE EXCEPTION 'initial Formation Authority source is not in an authority-bearing bootstrap lifecycle state';
    END IF;

    IF is_current_effective AND latest_source_state <> 'effective' THEN
        RAISE EXCEPTION 'current authority source must be Effective';
    END IF;

    SELECT
        rule.id,
        rule.decision_type,
        rule.decision_method,
        rule.required_approvals,
        rule.required_votes,
        rule.quorum_count,
        rule.signature_required,
        rule.reserved_matter,
        rule.amount_min,
        rule.amount_max
    INTO
        rule_id,
        rule_decision_type,
        rule_decision_method,
        rule_required_approvals,
        rule_required_votes,
        rule_quorum_count,
        rule_signature_required,
        rule_reserved_matter,
        rule_amount_min,
        rule_amount_max
    FROM formation_authority_policy_rules rule
    WHERE rule.business_id = NEW.business_id
      AND rule.formal_record_version_id =
          NEW.source_formal_record_version_id
      AND rule.sequence = NEW.source_rule_sequence;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'authority source rule does not exist';
    END IF;

    IF
        NEW.decision_type IS DISTINCT FROM rule_decision_type
        OR NEW.decision_method IS DISTINCT FROM rule_decision_method
        OR NEW.required_approvals IS DISTINCT FROM rule_required_approvals
        OR NEW.required_votes IS DISTINCT FROM rule_required_votes
        OR NEW.quorum_count IS DISTINCT FROM rule_quorum_count
        OR NEW.signature_required IS DISTINCT FROM rule_signature_required
        OR NEW.reserved_matter IS DISTINCT FROM rule_reserved_matter
        OR NEW.amount_min IS DISTINCT FROM rule_amount_min
        OR NEW.amount_max IS DISTINCT FROM rule_amount_max
    THEN
        RAISE EXCEPTION 'authority snapshot must exactly capture the applicable authority rule';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER authority_snapshots_validate
BEFORE INSERT
ON authority_snapshots
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_authority_snapshot();

CREATE OR REPLACE FUNCTION pbr_protect_authority_snapshot()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Authority Snapshot is immutable';
END;
$$;

CREATE TRIGGER authority_snapshots_immutable
BEFORE UPDATE OR DELETE
ON authority_snapshots
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_authority_snapshot();

CREATE OR REPLACE FUNCTION pbr_validate_decision()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    snapshot_decision_type text;
    snapshot_amount_min numeric;
    snapshot_amount_max numeric;
BEGIN
    SELECT
        decision_type,
        amount_min,
        amount_max
    INTO
        snapshot_decision_type,
        snapshot_amount_min,
        snapshot_amount_max
    FROM authority_snapshots
    WHERE id = NEW.authority_snapshot_id
      AND business_id = NEW.business_id
      AND proposal_version_id = NEW.proposal_version_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Decision authority snapshot binding is invalid';
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

    RETURN NEW;
END;
$$;

CREATE TRIGGER decisions_validate_insert
BEFORE INSERT
ON decisions
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_decision();

CREATE OR REPLACE FUNCTION pbr_assert_decision_approval_ready(
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
    source_version_id uuid;
    source_rule_sequence integer;

    source_rule_id uuid;
    source_actor_count integer;
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
        snapshot.source_formal_record_version_id,
        snapshot.source_rule_sequence
    INTO
        snapshot_method,
        required_approvals_count,
        required_votes_count,
        required_quorum_count,
        source_version_id,
        source_rule_sequence
    FROM decisions decision_record
    JOIN authority_snapshots snapshot
      ON snapshot.id = decision_record.authority_snapshot_id
     AND snapshot.business_id = decision_record.business_id
     AND snapshot.proposal_version_id =
         decision_record.proposal_version_id
    WHERE decision_record.id = target_decision_id
      AND decision_record.business_id = target_business_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Decision approval authority snapshot is missing';
    END IF;

    SELECT id
    INTO source_rule_id
    FROM formation_authority_policy_rules
    WHERE business_id = target_business_id
      AND formal_record_version_id = source_version_id
      AND sequence = source_rule_sequence;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Decision approval source authority rule is missing';
    END IF;

    SELECT count(*)
    INTO source_actor_count
    FROM formation_authority_policy_actors
    WHERE business_id = target_business_id
      AND formation_authority_policy_rule_id = source_rule_id;

    SELECT count(*)
    INTO participant_count
    FROM decision_participants
    WHERE business_id = target_business_id
      AND decision_id = target_decision_id;

    IF source_actor_count < 1 THEN
        RAISE EXCEPTION 'Decision approval source has no explicit eligible actors';
    END IF;

    IF participant_count <> source_actor_count THEN
        RAISE EXCEPTION 'Decision must capture the complete eligible authority actor set before approval';
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

CREATE OR REPLACE FUNCTION pbr_protect_decision()
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
        PERFORM pbr_assert_decision_approval_ready(
            OLD.id,
            OLD.business_id
        );
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER decisions_protect_history
BEFORE UPDATE OR DELETE
ON decisions
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_decision();

CREATE OR REPLACE FUNCTION pbr_validate_approval_requirement()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    decision_status text;
    method text;
    approvals_required integer;
    votes_required integer;
    snapshot_quorum integer;
BEGIN
    SELECT status
    INTO decision_status
    FROM decisions
    WHERE id = NEW.decision_id
      AND business_id = NEW.business_id
      AND proposal_version_id = NEW.proposal_version_id
      AND authority_snapshot_id = NEW.authority_snapshot_id;

    IF decision_status IS DISTINCT FROM 'open' THEN
        RAISE EXCEPTION 'Approval Requirement requires an Open Decision';
    END IF;

    SELECT
        decision_method,
        required_approvals,
        required_votes,
        quorum_count
    INTO
        method,
        approvals_required,
        votes_required,
        snapshot_quorum
    FROM authority_snapshots
    WHERE id = NEW.authority_snapshot_id
      AND business_id = NEW.business_id
      AND proposal_version_id = NEW.proposal_version_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Approval Requirement Authority Snapshot does not exist';
    END IF;

    IF NEW.requirement_kind = 'approval' THEN
        IF method NOT IN ('approval', 'approval_and_vote') THEN
            RAISE EXCEPTION 'Approval requirement is not allowed by captured Decision Method';
        END IF;

        IF NEW.required_count <> approvals_required THEN
            RAISE EXCEPTION 'Approval requirement count must match Authority Snapshot';
        END IF;
    END IF;

    IF NEW.requirement_kind = 'vote' THEN
        IF method NOT IN ('vote', 'approval_and_vote') THEN
            RAISE EXCEPTION 'Vote requirement is not allowed by captured Decision Method';
        END IF;

        IF NEW.required_count <> votes_required THEN
            RAISE EXCEPTION 'Vote requirement count must match Authority Snapshot';
        END IF;

        IF NEW.quorum_count <> snapshot_quorum THEN
            RAISE EXCEPTION 'Vote quorum must match Authority Snapshot';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER approval_requirements_validate
BEFORE INSERT
ON approval_requirements
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_approval_requirement();

CREATE OR REPLACE FUNCTION pbr_protect_approval_requirement()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Approval Requirement is immutable';
END;
$$;

CREATE TRIGGER approval_requirements_immutable
BEFORE UPDATE OR DELETE
ON approval_requirements
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_approval_requirement();

CREATE OR REPLACE FUNCTION pbr_validate_decision_participant()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    decision_status text;
    source_version uuid;
    source_sequence integer;
    source_rule_id uuid;
    actor_capacity text;
    actor_can_approve boolean;
    actor_can_vote boolean;
    actor_can_sign boolean;
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

    SELECT
        source_formal_record_version_id,
        source_rule_sequence
    INTO
        source_version,
        source_sequence
    FROM authority_snapshots
    WHERE id = NEW.authority_snapshot_id
      AND business_id = NEW.business_id
      AND proposal_version_id = NEW.proposal_version_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Decision Participant Authority Snapshot does not exist';
    END IF;

    SELECT id
    INTO source_rule_id
    FROM formation_authority_policy_rules
    WHERE business_id = NEW.business_id
      AND formal_record_version_id = source_version
      AND sequence = source_sequence;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Decision Participant source authority rule does not exist';
    END IF;

    SELECT
        capacity,
        can_approve,
        can_vote,
        can_sign
    INTO
        actor_capacity,
        actor_can_approve,
        actor_can_vote,
        actor_can_sign
    FROM formation_authority_policy_actors
    WHERE business_id = NEW.business_id
      AND formation_authority_policy_rule_id = source_rule_id
      AND membership_id = NEW.membership_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Membership is not an eligible actor in captured Formation Authority';
    END IF;

    IF
        NEW.capacity IS DISTINCT FROM actor_capacity
        OR NEW.can_approve IS DISTINCT FROM actor_can_approve
        OR NEW.can_vote IS DISTINCT FROM actor_can_vote
        OR NEW.can_sign IS DISTINCT FROM actor_can_sign
    THEN
        RAISE EXCEPTION 'Decision Participant must exactly capture eligible actor authority';
    END IF;

    IF NEW.status <> 'eligible' THEN
        RAISE EXCEPTION 'Decision Participant must be created Eligible before any recusal';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER decision_participants_validate
BEFORE INSERT
ON decision_participants
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_decision_participant();

CREATE OR REPLACE FUNCTION pbr_protect_decision_participant()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    decision_status text;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Decision Participant history cannot be deleted';
    END IF;

    IF
        NEW.business_id IS DISTINCT FROM OLD.business_id
        OR NEW.decision_id IS DISTINCT FROM OLD.decision_id
        OR NEW.proposal_version_id IS DISTINCT FROM OLD.proposal_version_id
        OR NEW.authority_snapshot_id IS DISTINCT FROM OLD.authority_snapshot_id
        OR NEW.membership_id IS DISTINCT FROM OLD.membership_id
        OR NEW.capacity IS DISTINCT FROM OLD.capacity
        OR NEW.can_approve IS DISTINCT FROM OLD.can_approve
        OR NEW.can_vote IS DISTINCT FROM OLD.can_vote
        OR NEW.can_sign IS DISTINCT FROM OLD.can_sign
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Decision Participant eligibility snapshot is immutable';
    END IF;

    SELECT status
    INTO decision_status
    FROM decisions
    WHERE id = OLD.decision_id
      AND business_id = OLD.business_id;

    IF decision_status IS DISTINCT FROM 'open' THEN
        RAISE EXCEPTION 'Decision Participant may only be recused while Decision is Open';
    END IF;

    IF OLD.status <> 'eligible' OR NEW.status <> 'recused' THEN
        RAISE EXCEPTION 'Decision Participant may only transition Eligible to Recused';
    END IF;

    /*
     * Existing Approval/Vote rows are historical evidence and remain immutable.
     * A later conflict discovered before Decision resolution changes current
     * participant eligibility; threshold evaluation must ignore that evidence.
     */
    RETURN NEW;
END;
$$;

CREATE TRIGGER decision_participants_protect_history
BEFORE UPDATE OR DELETE
ON decision_participants
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_decision_participant();

CREATE OR REPLACE FUNCTION pbr_validate_vote()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    decision_status text;
    participant_membership uuid;
    participant_status text;
    participant_can_vote boolean;
    bound_requirement_kind text;
BEGIN
    SELECT status
    INTO decision_status
    FROM decisions
    WHERE id = NEW.decision_id
      AND business_id = NEW.business_id
      AND proposal_version_id = NEW.proposal_version_id
      AND authority_snapshot_id = NEW.authority_snapshot_id;

    IF decision_status IS DISTINCT FROM 'open' THEN
        RAISE EXCEPTION 'Vote requires an Open Decision';
    END IF;

    SELECT
        membership_id,
        status,
        can_vote
    INTO
        participant_membership,
        participant_status,
        participant_can_vote
    FROM decision_participants
    WHERE id = NEW.decision_participant_id
      AND business_id = NEW.business_id
      AND decision_id = NEW.decision_id;

    IF NOT FOUND OR participant_membership <> NEW.membership_id THEN
        RAISE EXCEPTION 'Vote must be cast by the exact Decision Participant Membership';
    END IF;

    SELECT requirement.requirement_kind
    INTO bound_requirement_kind
    FROM approval_requirements requirement
    WHERE requirement.id = NEW.approval_requirement_id
      AND requirement.business_id = NEW.business_id
      AND requirement.decision_id = NEW.decision_id;

    IF bound_requirement_kind IS DISTINCT FROM 'vote' THEN
        RAISE EXCEPTION 'Vote must bind a Vote requirement';
    END IF;

    IF NEW.choice = 'recused' THEN
        IF participant_status <> 'recused' THEN
            RAISE EXCEPTION 'Recused Vote evidence requires a Recused participant';
        END IF;

        RETURN NEW;
    END IF;

    IF participant_status <> 'eligible' OR NOT participant_can_vote THEN
        RAISE EXCEPTION 'Participant is not eligible to cast this Vote';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER votes_validate
BEFORE INSERT
ON votes
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_vote();

CREATE OR REPLACE FUNCTION pbr_protect_vote()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Vote evidence is append-only and immutable';
END;
$$;

CREATE TRIGGER votes_immutable
BEFORE UPDATE OR DELETE
ON votes
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_vote();

CREATE OR REPLACE FUNCTION pbr_validate_approval()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    decision_status text;
    participant_membership uuid;
    participant_status text;
    participant_can_approve boolean;
    bound_requirement_kind text;
BEGIN
    SELECT status
    INTO decision_status
    FROM decisions
    WHERE id = NEW.decision_id
      AND business_id = NEW.business_id
      AND proposal_version_id = NEW.proposal_version_id
      AND authority_snapshot_id = NEW.authority_snapshot_id;

    IF decision_status IS DISTINCT FROM 'open' THEN
        RAISE EXCEPTION 'Approval requires an Open Decision';
    END IF;

    SELECT
        membership_id,
        status,
        can_approve
    INTO
        participant_membership,
        participant_status,
        participant_can_approve
    FROM decision_participants
    WHERE id = NEW.decision_participant_id
      AND business_id = NEW.business_id
      AND decision_id = NEW.decision_id;

    IF NOT FOUND OR participant_membership <> NEW.membership_id THEN
        RAISE EXCEPTION 'Approval must be recorded by the exact Decision Participant Membership';
    END IF;

    SELECT requirement.requirement_kind
    INTO bound_requirement_kind
    FROM approval_requirements requirement
    WHERE requirement.id = NEW.approval_requirement_id
      AND requirement.business_id = NEW.business_id
      AND requirement.decision_id = NEW.decision_id;

    IF bound_requirement_kind IS DISTINCT FROM 'approval' THEN
        RAISE EXCEPTION 'Approval must bind an Approval requirement';
    END IF;

    IF participant_status <> 'eligible' OR NOT participant_can_approve THEN
        RAISE EXCEPTION 'Participant is not eligible to record this Approval';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER approvals_validate
BEFORE INSERT
ON approvals
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_approval();

CREATE OR REPLACE FUNCTION pbr_protect_approval()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Approval evidence is append-only and immutable';
END;
$$;

CREATE TRIGGER approvals_immutable
BEFORE UPDATE OR DELETE
ON approvals
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_approval();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('votes');
        Schema::dropIfExists('decision_participants');
        Schema::dropIfExists('approval_requirements');
        Schema::dropIfExists('decisions');
        Schema::dropIfExists('authority_snapshots');

        DB::unprepared('DROP FUNCTION IF EXISTS pbr_protect_approval();');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_validate_approval();');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_protect_vote();');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_validate_vote();');
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_decision_participant();',
        );
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_decision_participant();',
        );
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_approval_requirement();',
        );
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_approval_requirement();',
        );
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_protect_decision();');
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_assert_decision_approval_ready(uuid, uuid);',
        );
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_validate_decision();');
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_authority_snapshot();',
        );
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_authority_snapshot();',
        );
    }
};
