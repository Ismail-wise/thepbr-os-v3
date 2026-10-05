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
        Schema::create('capital_approval_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('contract_version', 80);
            $table->uuid('capital_planning_draft_id');
            $table->unsignedBigInteger('capital_planning_revision');
            $table->uuid('capital_rule_draft_id');
            $table->unsignedBigInteger('capital_rule_revision');
            $table->uuid('capital_comparison_draft_id');
            $table->unsignedBigInteger('capital_comparison_revision');
            $table->string('preferred_plan', 16);
            $table->jsonb('snapshot_payload');
            $table->char('content_hash', 64);
            $table->uuid('formal_record_version_id');
            $table->uuid('proposal_version_id');
            $table->uuid('prepared_by_membership_id');
            $table->timestampTz('prepared_at');

            $table->unique(['id', 'business_id'], 'capital_approval_snapshots_id_business_uq');
            $table->unique('formal_record_version_id', 'capital_approval_snapshots_record_uq');
            $table->unique('proposal_version_id', 'capital_approval_snapshots_proposal_uq');
            $table->unique(
                [
                    'business_id',
                    'capital_planning_revision',
                    'capital_rule_revision',
                    'capital_comparison_revision',
                    'preferred_plan',
                    'content_hash',
                ],
                'capital_approval_snapshots_source_identity_uq',
            );

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table->foreign(
                ['capital_planning_draft_id', 'business_id'],
                'capital_approval_snapshots_planning_fk',
            )->references(['id', 'business_id'])
                ->on('capital_planning_drafts')
                ->restrictOnDelete();

            $table->foreign(
                ['capital_rule_draft_id', 'business_id'],
                'capital_approval_snapshots_rule_fk',
            )->references(['id', 'business_id'])
                ->on('capital_rule_drafts')
                ->restrictOnDelete();

            $table->foreign(
                ['capital_comparison_draft_id', 'business_id'],
                'capital_approval_snapshots_comparison_fk',
            )->references(['id', 'business_id'])
                ->on('capital_comparison_drafts')
                ->restrictOnDelete();

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'capital_approval_snapshots_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['proposal_version_id', 'business_id'],
                'capital_approval_snapshots_proposal_fk',
            )->references(['id', 'business_id'])
                ->on('proposal_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['prepared_by_membership_id', 'business_id'],
                'capital_approval_snapshots_preparer_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE capital_approval_snapshots
             ADD CONSTRAINT capital_approval_snapshots_contract_ck
             CHECK (contract_version = 'capital-approval-v1')",
        );

        DB::statement(
            "ALTER TABLE capital_approval_snapshots
             ADD CONSTRAINT capital_approval_snapshots_plan_ck
             CHECK (preferred_plan IN ('lean', 'base', 'growth'))",
        );

        DB::statement(
            "ALTER TABLE capital_approval_snapshots
             ADD CONSTRAINT capital_approval_snapshots_payload_ck
             CHECK (jsonb_typeof(snapshot_payload) = 'object')",
        );

        DB::statement(
            "ALTER TABLE capital_approval_snapshots
             ADD CONSTRAINT capital_approval_snapshots_hash_ck
             CHECK (content_hash ~ '^[0-9a-f]{64}$')",
        );

        DB::statement(
            'ALTER TABLE capital_approval_snapshots
             ADD CONSTRAINT capital_approval_snapshots_revisions_ck
             CHECK (
                 capital_planning_revision > 0
                 AND capital_rule_revision > 0
                 AND capital_comparison_revision > 0
             )',
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_capital_approval_snapshot()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    record_hash text;
    record_frozen timestamptz;
    record_type_value text;
    proposal_hash text;
    proposal_frozen timestamptz;
    binding_hash text;
BEGIN
    SELECT v.content_hash, v.frozen_at, f.record_type
      INTO record_hash, record_frozen, record_type_value
      FROM formal_record_versions v
      JOIN formal_record_families f
        ON f.id = v.formal_record_family_id
       AND f.business_id = v.business_id
     WHERE v.id = NEW.formal_record_version_id
       AND v.business_id = NEW.business_id;

    IF NOT FOUND
       OR record_type_value <> 'capital_plan'
       OR record_frozen IS NULL
       OR record_hash IS DISTINCT FROM NEW.content_hash
    THEN
        RAISE EXCEPTION 'Capital approval snapshot must bind the exact frozen Capital Plan record/hash';
    END IF;

    SELECT proposal_content_hash, frozen_at
      INTO proposal_hash, proposal_frozen
      FROM proposal_versions
     WHERE id = NEW.proposal_version_id
       AND business_id = NEW.business_id;

    IF NOT FOUND
       OR proposal_frozen IS NULL
       OR proposal_hash IS DISTINCT FROM NEW.content_hash
    THEN
        RAISE EXCEPTION 'Capital approval snapshot must bind the exact Frozen Proposal Version/hash';
    END IF;

    SELECT captured_content_hash
      INTO binding_hash
      FROM proposal_version_records
     WHERE proposal_version_id = NEW.proposal_version_id
       AND formal_record_version_id = NEW.formal_record_version_id
       AND business_id = NEW.business_id;

    IF NOT FOUND OR binding_hash IS DISTINCT FROM NEW.content_hash THEN
        RAISE EXCEPTION 'Capital approval Proposal Version must bind the same frozen Capital Plan version/hash';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER capital_approval_snapshots_validate
BEFORE INSERT ON capital_approval_snapshots
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_capital_approval_snapshot();

CREATE OR REPLACE FUNCTION pbr_protect_capital_approval_snapshot()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Capital approval snapshots are immutable';
END;
$$;

CREATE TRIGGER capital_approval_snapshots_immutable
BEFORE UPDATE OR DELETE ON capital_approval_snapshots
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_capital_approval_snapshot();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('capital_approval_snapshots');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_capital_approval_snapshot();',
        );
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_capital_approval_snapshot();',
        );
    }
};
