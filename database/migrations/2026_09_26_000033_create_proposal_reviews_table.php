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
        Schema::create('proposal_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('proposal_version_id');
            $table->uuid('reviewer_membership_id');
            $table->uuid('created_by_membership_id');
            $table->string('status', 24)->default('open');
            $table->string('outcome', 32)->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['id', 'business_id'],
                'proposal_reviews_id_business_unique',
            );

            $table->unique(
                ['proposal_version_id'],
                'proposal_reviews_version_unique',
            );

            $table->foreign(
                ['proposal_version_id', 'business_id'],
                'proposal_reviews_version_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('proposal_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['reviewer_membership_id', 'business_id'],
                'proposal_reviews_reviewer_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'proposal_reviews_creator_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE proposal_reviews
             ADD CONSTRAINT proposal_reviews_status_check
             CHECK (status IN ('open', 'completed'))",
        );

        DB::statement(
            "ALTER TABLE proposal_reviews
             ADD CONSTRAINT proposal_reviews_outcome_check
             CHECK (
                 outcome IS NULL
                 OR outcome IN (
                     'approved',
                     'changes_requested',
                     'rejected'
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE proposal_reviews
             ADD CONSTRAINT proposal_reviews_lifecycle_check
             CHECK (
                 (
                     status = 'open'
                     AND outcome IS NULL
                     AND resolved_at IS NULL
                 )
                 OR
                 (
                     status = 'completed'
                     AND outcome IS NOT NULL
                     AND resolved_at IS NOT NULL
                 )
             )",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_proposal_review_insert()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.status <> 'open' THEN
        RAISE EXCEPTION 'Proposal Review must begin Open';
    END IF;

    IF NEW.outcome IS NOT NULL OR NEW.resolved_at IS NOT NULL THEN
        RAISE EXCEPTION 'Open Proposal Review cannot have an outcome';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER proposal_reviews_validate_insert
BEFORE INSERT
ON proposal_reviews
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_proposal_review_insert();

CREATE OR REPLACE FUNCTION pbr_protect_proposal_review()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Proposal Review history cannot be deleted';
    END IF;

    IF
        NEW.business_id IS DISTINCT FROM OLD.business_id
        OR NEW.proposal_version_id IS DISTINCT FROM OLD.proposal_version_id
        OR NEW.reviewer_membership_id IS DISTINCT FROM OLD.reviewer_membership_id
        OR NEW.created_by_membership_id IS DISTINCT FROM OLD.created_by_membership_id
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Proposal Review frozen identity is immutable';
    END IF;

    IF OLD.status <> 'open' THEN
        RAISE EXCEPTION 'Completed Proposal Review is immutable';
    END IF;

    IF
        NEW.status <> 'completed'
        OR NEW.outcome IS NULL
        OR NEW.resolved_at IS NULL
    THEN
        RAISE EXCEPTION 'Proposal Review may only resolve Open to Completed with an outcome';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER proposal_reviews_protect_history
BEFORE UPDATE OR DELETE
ON proposal_reviews
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_proposal_review();

CREATE OR REPLACE FUNCTION pbr_require_approved_proposal_review()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    review_status_value text;
    review_outcome_value text;
BEGIN
    SELECT
        review_record.status,
        review_record.outcome
    INTO
        review_status_value,
        review_outcome_value
    FROM proposal_reviews review_record
    WHERE review_record.business_id = NEW.business_id
      AND review_record.proposal_version_id =
          NEW.proposal_version_id;

    IF
        NOT FOUND
        OR review_status_value IS DISTINCT FROM 'completed'
        OR review_outcome_value IS DISTINCT FROM 'approved'
    THEN
        RAISE EXCEPTION 'Decision requires an Approved Review of the exact Frozen Proposal Version';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER decisions_require_approved_proposal_review
BEFORE INSERT
ON decisions
FOR EACH ROW
EXECUTE FUNCTION pbr_require_approved_proposal_review();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS decisions_require_approved_proposal_review ON decisions;',
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_require_approved_proposal_review();',
        );

        Schema::dropIfExists('proposal_reviews');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_proposal_review();',
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_proposal_review_insert();',
        );
    }
};
