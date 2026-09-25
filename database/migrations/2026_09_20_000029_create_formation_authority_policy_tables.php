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
        Schema::create(
            'formation_authority_policy_rules',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('formal_record_version_id');
                $table->unsignedInteger('sequence');
                $table->string('decision_type', 160);
                $table->string('decision_method', 32);
                $table->unsignedSmallInteger('required_approvals')
                    ->default(0);
                $table->unsignedSmallInteger('required_votes')
                    ->default(0);
                $table->unsignedSmallInteger('quorum_count')
                    ->default(1);
                $table->boolean('signature_required')->default(false);
                $table->boolean('reserved_matter')->default(false);
                $table->decimal('amount_min', 20, 2)->nullable();
                $table->decimal('amount_max', 20, 2)->nullable();
                $table->timestampsTz();

                $table->unique(
                    ['id', 'business_id'],
                    'formation_authority_rules_id_business_unique',
                );

                $table->unique(
                    ['formal_record_version_id', 'sequence'],
                    'formation_authority_rules_version_sequence_unique',
                );

                $table->foreign(
                    ['formal_record_version_id', 'business_id'],
                    'formation_authority_rules_version_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('formal_record_versions')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'formation_authority_policy_actors',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('formation_authority_policy_rule_id');
                $table->uuid('membership_id');
                $table->string('capacity', 120);
                $table->boolean('can_approve')->default(false);
                $table->boolean('can_vote')->default(false);
                $table->boolean('can_sign')->default(false);
                $table->timestampsTz();

                $table->unique(
                    ['id', 'business_id'],
                    'formation_authority_actors_id_business_unique',
                );

                $table->unique(
                    [
                        'formation_authority_policy_rule_id',
                        'membership_id',
                    ],
                    'formation_authority_actors_rule_membership_unique',
                );

                $table->foreign(
                    [
                        'formation_authority_policy_rule_id',
                        'business_id',
                    ],
                    'formation_authority_actors_rule_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('formation_authority_policy_rules')
                    ->restrictOnDelete();

                $table->foreign(
                    ['membership_id', 'business_id'],
                    'formation_authority_actors_membership_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'formation_authority_establishments',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('formal_record_version_id');
                $table->uuid('established_by_membership_id');
                $table->char('establishment_hash', 64);
                $table->timestampTz('established_at');
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    'business_id',
                    'formation_authority_establishments_business_unique',
                );

                $table->unique(
                    'formal_record_version_id',
                    'formation_authority_establishments_version_unique',
                );

                $table->foreign(
                    ['formal_record_version_id', 'business_id'],
                    'formation_authority_establishments_version_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('formal_record_versions')
                    ->restrictOnDelete();

                $table->foreign(
                    ['established_by_membership_id', 'business_id'],
                    'formation_authority_establishments_membership_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        DB::statement(
            'ALTER TABLE formation_authority_policy_rules
             ADD CONSTRAINT formation_authority_rules_sequence_positive
             CHECK (sequence > 0)',
        );

        DB::statement(
            "ALTER TABLE formation_authority_policy_rules
             ADD CONSTRAINT formation_authority_rules_decision_type_check
             CHECK (
                 btrim(decision_type) <> ''
                 AND decision_type = btrim(decision_type)
             )",
        );

        DB::statement(
            "ALTER TABLE formation_authority_policy_rules
             ADD CONSTRAINT formation_authority_rules_method_check
             CHECK (
                 decision_method IN (
                     'approval',
                     'vote',
                     'approval_and_vote'
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE formation_authority_policy_rules
             ADD CONSTRAINT formation_authority_rules_threshold_check
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
            'ALTER TABLE formation_authority_policy_rules
             ADD CONSTRAINT formation_authority_rules_quorum_positive
             CHECK (quorum_count > 0)',
        );

        DB::statement(
            'ALTER TABLE formation_authority_policy_rules
             ADD CONSTRAINT formation_authority_rules_amount_range_check
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
            "ALTER TABLE formation_authority_policy_actors
             ADD CONSTRAINT formation_authority_actors_capacity_check
             CHECK (
                 btrim(capacity) <> ''
                 AND capacity = btrim(capacity)
             )",
        );

        DB::statement(
            'ALTER TABLE formation_authority_policy_actors
             ADD CONSTRAINT formation_authority_actors_capability_check
             CHECK (can_approve OR can_vote OR can_sign)',
        );

        DB::statement(
            "ALTER TABLE formation_authority_establishments
             ADD CONSTRAINT formation_authority_establishment_hash_check
             CHECK (establishment_hash ~ '^[0-9a-f]{64}$')",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_assert_formation_authority_policy_mutable(
    target_record_version uuid
)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    target_frozen_at timestamptz;
    target_record_type text;
BEGIN
    SELECT
        version.frozen_at,
        family.record_type
    INTO
        target_frozen_at,
        target_record_type
    FROM formal_record_versions version
    JOIN formal_record_families family
      ON family.id = version.formal_record_family_id
     AND family.business_id = version.business_id
    WHERE version.id = target_record_version;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Formation Authority formal record version does not exist';
    END IF;

    IF target_record_type <> 'formation_authority_policy' THEN
        RAISE EXCEPTION 'Formation Authority rules require a Formation Authority Policy record';
    END IF;

    IF target_frozen_at IS NOT NULL THEN
        RAISE EXCEPTION 'frozen Formation Authority policy content is immutable';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION pbr_protect_formation_authority_rule()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        PERFORM pbr_assert_formation_authority_policy_mutable(
            NEW.formal_record_version_id
        );

        RETURN NEW;
    END IF;

    PERFORM pbr_assert_formation_authority_policy_mutable(
        OLD.formal_record_version_id
    );

    IF
        TG_OP = 'UPDATE'
        AND NEW.formal_record_version_id IS DISTINCT FROM OLD.formal_record_version_id
    THEN
        PERFORM pbr_assert_formation_authority_policy_mutable(
            NEW.formal_record_version_id
        );
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER formation_authority_rules_protect_frozen
BEFORE INSERT OR UPDATE OR DELETE
ON formation_authority_policy_rules
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_formation_authority_rule();

CREATE OR REPLACE FUNCTION pbr_protect_formation_authority_actor()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    old_version uuid;
    new_version uuid;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        SELECT formal_record_version_id
        INTO old_version
        FROM formation_authority_policy_rules
        WHERE id = OLD.formation_authority_policy_rule_id
          AND business_id = OLD.business_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Formation Authority rule does not exist';
        END IF;

        PERFORM pbr_assert_formation_authority_policy_mutable(old_version);
    END IF;

    IF TG_OP <> 'DELETE' THEN
        SELECT formal_record_version_id
        INTO new_version
        FROM formation_authority_policy_rules
        WHERE id = NEW.formation_authority_policy_rule_id
          AND business_id = NEW.business_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Formation Authority rule does not exist';
        END IF;

        PERFORM pbr_assert_formation_authority_policy_mutable(new_version);
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER formation_authority_actors_protect_frozen
BEFORE INSERT OR UPDATE OR DELETE
ON formation_authority_policy_actors
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_formation_authority_actor();

CREATE OR REPLACE FUNCTION pbr_validate_formation_authority_establishment()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    family_record_type text;
    version_frozen_at timestamptz;
    version_effective_from timestamptz;
    version_content_hash text;
    latest_state text;
    rule_count bigint;
    actorless_rule_count bigint;
BEGIN
    SELECT
        family.record_type,
        version.frozen_at,
        version.effective_from,
        version.content_hash
    INTO
        family_record_type,
        version_frozen_at,
        version_effective_from,
        version_content_hash
    FROM formal_record_versions version
    JOIN formal_record_families family
      ON family.id = version.formal_record_family_id
     AND family.business_id = version.business_id
    WHERE version.id = NEW.formal_record_version_id
      AND version.business_id = NEW.business_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Formation Authority record version does not exist in this Business';
    END IF;

    IF family_record_type <> 'formation_authority_policy' THEN
        RAISE EXCEPTION 'initial Formation Authority must bind a Formation Authority Policy record';
    END IF;

    IF version_frozen_at IS NULL THEN
        RAISE EXCEPTION 'initial Formation Authority policy must be frozen';
    END IF;

    IF version_effective_from IS NULL THEN
        RAISE EXCEPTION 'initial Formation Authority policy requires effective_from before bootstrap establishment';
    END IF;

    IF NEW.establishment_hash IS DISTINCT FROM version_content_hash THEN
        RAISE EXCEPTION 'Formation Authority establishment must bind the exact policy content hash';
    END IF;

    SELECT to_state
    INTO latest_state
    FROM record_version_state_transitions
    WHERE formal_record_version_id = NEW.formal_record_version_id
    ORDER BY sequence DESC
    LIMIT 1;

    IF latest_state IS DISTINCT FROM 'ready_for_review' THEN
        RAISE EXCEPTION 'initial Formation Authority bootstrap must bind the frozen Ready for Review policy version';
    END IF;

    SELECT count(*)
    INTO rule_count
    FROM formation_authority_policy_rules
    WHERE formal_record_version_id = NEW.formal_record_version_id
      AND business_id = NEW.business_id;

    IF rule_count < 1 THEN
        RAISE EXCEPTION 'initial Formation Authority policy requires at least one authority rule';
    END IF;

    SELECT count(*)
    INTO actorless_rule_count
    FROM formation_authority_policy_rules rule
    WHERE rule.formal_record_version_id = NEW.formal_record_version_id
      AND rule.business_id = NEW.business_id
      AND NOT EXISTS (
          SELECT 1
          FROM formation_authority_policy_actors actor
          WHERE actor.formation_authority_policy_rule_id = rule.id
            AND actor.business_id = rule.business_id
      );

    IF actorless_rule_count > 0 THEN
        RAISE EXCEPTION 'every Formation Authority rule requires at least one explicit eligible actor';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER formation_authority_establishments_validate
BEFORE INSERT
ON formation_authority_establishments
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_formation_authority_establishment();

CREATE OR REPLACE FUNCTION pbr_protect_formation_authority_establishment()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'initial Formation Authority establishment is immutable';
END;
$$;

CREATE TRIGGER formation_authority_establishments_immutable
BEFORE UPDATE OR DELETE
ON formation_authority_establishments
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_formation_authority_establishment();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('formation_authority_establishments');
        Schema::dropIfExists('formation_authority_policy_actors');
        Schema::dropIfExists('formation_authority_policy_rules');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_formation_authority_establishment();',
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_formation_authority_establishment();',
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_formation_authority_actor();',
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_formation_authority_rule();',
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_assert_formation_authority_policy_mutable(uuid);',
        );
    }
};
