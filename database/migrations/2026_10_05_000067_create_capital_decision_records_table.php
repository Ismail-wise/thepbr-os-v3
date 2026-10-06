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
        Schema::create('capital_decision_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('contract_version', 80);
            $table->uuid('capital_approval_snapshot_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('proposal_version_id');
            $table->uuid('governance_decision_id');
            $table->char('approved_content_hash', 64);
            $table->uuid('decision_owner_membership_id');
            $table->date('effective_date');
            $table->date('review_date');
            $table->text('decision_summary');
            $table->jsonb('evidence_references');
            $table->uuid('created_by_membership_id');
            $table->timestampTz('created_at');

            $table->unique(['id', 'business_id'], 'capital_decision_records_id_business_uq');
            $table->unique('capital_approval_snapshot_id', 'capital_decision_records_snapshot_uq');
            $table->unique('formal_record_version_id', 'capital_decision_records_formal_uq');
            $table->unique('governance_decision_id', 'capital_decision_records_decision_uq');

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign(
                ['capital_approval_snapshot_id', 'business_id'],
                'capital_decision_records_snapshot_fk',
            )->references(['id', 'business_id'])
                ->on('capital_approval_snapshots')
                ->restrictOnDelete();
            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'capital_decision_records_formal_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();
            $table->foreign(
                ['proposal_version_id', 'business_id'],
                'capital_decision_records_proposal_fk',
            )->references(['id', 'business_id'])
                ->on('proposal_versions')
                ->restrictOnDelete();
            $table->foreign(
                ['governance_decision_id', 'business_id'],
                'capital_decision_records_decision_fk',
            )->references(['id', 'business_id'])
                ->on('decisions')
                ->restrictOnDelete();
            $table->foreign(
                ['decision_owner_membership_id', 'business_id'],
                'capital_decision_records_owner_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'capital_decision_records_creator_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE capital_decision_records
             ADD CONSTRAINT capital_decision_records_contract_ck
             CHECK (contract_version = 'capital-decision-record-v1')",
        );
        DB::statement(
            "ALTER TABLE capital_decision_records
             ADD CONSTRAINT capital_decision_records_hash_ck
             CHECK (approved_content_hash ~ '^[0-9a-f]{64}$')",
        );
        DB::statement(
            'ALTER TABLE capital_decision_records
             ADD CONSTRAINT capital_decision_records_dates_ck
             CHECK (review_date >= effective_date)',
        );
        DB::statement(
            'ALTER TABLE capital_decision_records
             ADD CONSTRAINT capital_decision_records_summary_ck
             CHECK (length(btrim(decision_summary)) > 0)',
        );
        DB::statement(
            "ALTER TABLE capital_decision_records
             ADD CONSTRAINT capital_decision_records_evidence_ck
             CHECK (jsonb_typeof(evidence_references) = 'array')",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_capital_decision_record()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    snapshot_formal uuid;
    snapshot_proposal uuid;
    snapshot_hash text;
    decision_proposal uuid;
    decision_status text;
    decision_outcome text;
    decision_resolved timestamptz;
    record_hash text;
    record_frozen timestamptz;
    proposal_hash text;
    proposal_frozen timestamptz;
    latest_state text;
    owner_status text;
BEGIN
    SELECT formal_record_version_id, proposal_version_id, content_hash
      INTO snapshot_formal, snapshot_proposal, snapshot_hash
      FROM capital_approval_snapshots
     WHERE id = NEW.capital_approval_snapshot_id
       AND business_id = NEW.business_id;

    IF NOT FOUND
       OR snapshot_formal IS DISTINCT FROM NEW.formal_record_version_id
       OR snapshot_proposal IS DISTINCT FROM NEW.proposal_version_id
       OR snapshot_hash IS DISTINCT FROM NEW.approved_content_hash
    THEN
        RAISE EXCEPTION 'Capital Decision Record must pin the exact approved Capital snapshot';
    END IF;

    SELECT proposal_version_id, status, outcome, resolved_at
      INTO decision_proposal, decision_status, decision_outcome, decision_resolved
      FROM decisions
     WHERE id = NEW.governance_decision_id
       AND business_id = NEW.business_id
       AND decision_type = 'capital_plan_approval';

    IF NOT FOUND
       OR decision_proposal IS DISTINCT FROM NEW.proposal_version_id
       OR decision_status <> 'decided'
       OR decision_outcome <> 'approved'
       OR decision_resolved IS NULL
    THEN
        RAISE EXCEPTION 'Capital Decision Record requires the resolved approved Governance Decision';
    END IF;

    SELECT content_hash, frozen_at
      INTO record_hash, record_frozen
      FROM formal_record_versions
     WHERE id = NEW.formal_record_version_id
       AND business_id = NEW.business_id;

    SELECT proposal_content_hash, frozen_at
      INTO proposal_hash, proposal_frozen
      FROM proposal_versions
     WHERE id = NEW.proposal_version_id
       AND business_id = NEW.business_id;

    IF record_frozen IS NULL
       OR proposal_frozen IS NULL
       OR record_hash IS DISTINCT FROM NEW.approved_content_hash
       OR proposal_hash IS DISTINCT FROM NEW.approved_content_hash
    THEN
        RAISE EXCEPTION 'Capital Decision Record source hashes must match the frozen approved Capital content';
    END IF;

    SELECT to_state
      INTO latest_state
      FROM record_version_state_transitions
     WHERE business_id = NEW.business_id
       AND formal_record_version_id = NEW.formal_record_version_id
     ORDER BY sequence DESC
     LIMIT 1;

    IF latest_state IS DISTINCT FROM 'approved' THEN
        RAISE EXCEPTION 'Capital Decision Record may be created only from the approved Formal Record state';
    END IF;

    SELECT access_status
      INTO owner_status
      FROM memberships
     WHERE id = NEW.decision_owner_membership_id
       AND business_id = NEW.business_id;

    IF NOT FOUND OR owner_status IS DISTINCT FROM 'active' THEN
        RAISE EXCEPTION 'Decision Owner must be an active Business member';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER capital_decision_records_validate
BEFORE INSERT ON capital_decision_records
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_capital_decision_record();

CREATE OR REPLACE FUNCTION pbr_protect_capital_decision_record()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Capital Decision Records are immutable';
END;
$$;

CREATE TRIGGER capital_decision_records_immutable
BEFORE UPDATE OR DELETE ON capital_decision_records
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_capital_decision_record();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('capital_decision_records');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_validate_capital_decision_record();');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_protect_capital_decision_record();');
    }
};
