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
            'ownership_governance_submissions',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');

                $table->uuid('ownership_scenario_id');
                $table->unsignedBigInteger('scenario_revision');

                $table->uuid('formal_record_family_id');
                $table->uuid('formal_record_version_id');

                $table->uuid('proposal_id');
                $table->uuid('proposal_version_id');

                $table->char('content_hash', 64);

                $table->timestampTz('effective_from');
                $table->timestampTz('effective_until')->nullable();

                $table->uuid('created_by_membership_id');

                $table->uuid('effective_register_version_id')->nullable();

                $table->timestampTz('created_at')->useCurrent();

                $table->foreign('business_id')
                    ->references('id')
                    ->on('businesses')
                    ->restrictOnDelete();

                $table->foreign('ownership_scenario_id')
                    ->references('id')
                    ->on('ownership_scenarios')
                    ->restrictOnDelete();

                $table->foreign('formal_record_family_id')
                    ->references('id')
                    ->on('formal_record_families')
                    ->restrictOnDelete();

                $table->foreign('formal_record_version_id')
                    ->references('id')
                    ->on('formal_record_versions')
                    ->restrictOnDelete();

                $table->foreign('proposal_id')
                    ->references('id')
                    ->on('proposals')
                    ->restrictOnDelete();

                $table->foreign('proposal_version_id')
                    ->references('id')
                    ->on('proposal_versions')
                    ->restrictOnDelete();

                $table->foreign('created_by_membership_id')
                    ->references('id')
                    ->on('memberships')
                    ->restrictOnDelete();

                $table->foreign('effective_register_version_id')
                    ->references('id')
                    ->on('ownership_register_versions')
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'ownership_scenario_id',
                        'scenario_revision',
                    ],
                    'ownership_governance_scenario_revision_unique',
                );

                $table->unique(
                    ['proposal_version_id'],
                    'ownership_governance_proposal_version_unique',
                );

                $table->unique(
                    ['formal_record_version_id'],
                    'ownership_governance_record_version_unique',
                );

                $table->index(
                    ['business_id', 'created_at'],
                    'ownership_governance_business_created_index',
                );
            },
        );

        DB::statement(<<<'SQL'
ALTER TABLE ownership_governance_submissions
ADD CONSTRAINT ownership_governance_revision_positive
CHECK (scenario_revision > 0)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE ownership_governance_submissions
ADD CONSTRAINT ownership_governance_hash_check
CHECK (content_hash ~ '^[0-9a-f]{64}$')
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE ownership_governance_submissions
ADD CONSTRAINT ownership_governance_effective_period_check
CHECK (
    effective_until IS NULL
    OR effective_until > effective_from
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_f5_ownership_governance_submission_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    scenario_business uuid;
    scenario_revision_value bigint;
    scenario_status text;

    family_business uuid;

    record_business uuid;
    record_family uuid;
    record_hash text;

    proposal_business uuid;
    proposal_hash text;

    proposal_version_business uuid;
    proposal_parent uuid;
    proposal_version_hash text;

    record_binding_exists boolean;

    membership_business uuid;

    register_business uuid;
    register_scenario uuid;
    register_proposal uuid;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION
            'Ownership Governance Submission history cannot be deleted';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF OLD.business_id IS DISTINCT FROM NEW.business_id
           OR OLD.ownership_scenario_id
                IS DISTINCT FROM NEW.ownership_scenario_id
           OR OLD.scenario_revision
                IS DISTINCT FROM NEW.scenario_revision
           OR OLD.formal_record_family_id
                IS DISTINCT FROM NEW.formal_record_family_id
           OR OLD.formal_record_version_id
                IS DISTINCT FROM NEW.formal_record_version_id
           OR OLD.proposal_id
                IS DISTINCT FROM NEW.proposal_id
           OR OLD.proposal_version_id
                IS DISTINCT FROM NEW.proposal_version_id
           OR OLD.content_hash
                IS DISTINCT FROM NEW.content_hash
           OR OLD.effective_from
                IS DISTINCT FROM NEW.effective_from
           OR OLD.effective_until
                IS DISTINCT FROM NEW.effective_until
           OR OLD.created_by_membership_id
                IS DISTINCT FROM NEW.created_by_membership_id
           OR OLD.created_at
                IS DISTINCT FROM NEW.created_at THEN
            RAISE EXCEPTION
                'Ownership Governance Submission snapshot is immutable';
        END IF;

        IF OLD.effective_register_version_id IS NOT NULL
           AND OLD.effective_register_version_id
                IS DISTINCT FROM NEW.effective_register_version_id THEN
            RAISE EXCEPTION
                'Ownership Governance Submission effect binding is immutable';
        END IF;
    END IF;

    SELECT business_id, revision, status
      INTO scenario_business, scenario_revision_value, scenario_status
      FROM ownership_scenarios
     WHERE id = NEW.ownership_scenario_id;

    IF scenario_business IS NULL
       OR scenario_business <> NEW.business_id
       OR scenario_revision_value <> NEW.scenario_revision
       OR scenario_status <> 'frozen' THEN
        RAISE EXCEPTION
            'Ownership Governance Submission Scenario mismatch';
    END IF;

    SELECT business_id
      INTO family_business
      FROM formal_record_families
     WHERE id = NEW.formal_record_family_id;

    SELECT business_id, formal_record_family_id, content_hash
      INTO record_business, record_family, record_hash
      FROM formal_record_versions
     WHERE id = NEW.formal_record_version_id;

    SELECT business_id, content_hash
      INTO proposal_business, proposal_hash
      FROM proposals
     WHERE id = NEW.proposal_id;

    SELECT business_id, proposal_id, proposal_content_hash
      INTO proposal_version_business,
           proposal_parent,
           proposal_version_hash
      FROM proposal_versions
     WHERE id = NEW.proposal_version_id;

    SELECT EXISTS (
        SELECT 1
          FROM proposal_version_records
         WHERE business_id = NEW.business_id
           AND proposal_version_id = NEW.proposal_version_id
           AND formal_record_version_id =
               NEW.formal_record_version_id
           AND captured_content_hash = NEW.content_hash
    )
      INTO record_binding_exists;

    SELECT business_id
      INTO membership_business
      FROM memberships
     WHERE id = NEW.created_by_membership_id;

    IF family_business <> NEW.business_id
       OR record_business <> NEW.business_id
       OR proposal_business <> NEW.business_id
       OR proposal_version_business <> NEW.business_id
       OR membership_business <> NEW.business_id
       OR record_family <> NEW.formal_record_family_id
       OR proposal_parent <> NEW.proposal_id
       OR record_hash <> NEW.content_hash
       OR proposal_hash <> NEW.content_hash
       OR proposal_version_hash <> NEW.content_hash
       OR NOT record_binding_exists THEN
        RAISE EXCEPTION
            'Ownership Governance Submission binding mismatch';
    END IF;

    IF NEW.effective_register_version_id IS NOT NULL THEN
        SELECT business_id,
               source_ownership_scenario_id,
               proposal_version_id
          INTO register_business,
               register_scenario,
               register_proposal
          FROM ownership_register_versions
         WHERE id = NEW.effective_register_version_id;

        IF register_business IS NULL
           OR register_business <> NEW.business_id
           OR register_scenario <> NEW.ownership_scenario_id
           OR register_proposal <> NEW.proposal_version_id THEN
            RAISE EXCEPTION
                'Ownership Governance Submission Register binding mismatch';
        END IF;
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER ownership_governance_submissions_guard
BEFORE INSERT OR UPDATE OR DELETE
ON ownership_governance_submissions
FOR EACH ROW
EXECUTE FUNCTION thepbr_f5_ownership_governance_submission_guard()
SQL);
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS
             ownership_governance_submissions_guard
             ON ownership_governance_submissions',
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS
             thepbr_f5_ownership_governance_submission_guard()',
        );

        Schema::dropIfExists(
            'ownership_governance_submissions',
        );
    }
};
