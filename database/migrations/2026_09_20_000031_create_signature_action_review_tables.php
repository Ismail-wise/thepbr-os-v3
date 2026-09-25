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
        Schema::create('signature_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('decision_id');
            $table->uuid('proposal_version_id');
            $table->uuid('authority_snapshot_id');
            $table->uuid('document_version_id');
            $table->char('document_content_sha256', 64);
            $table->string('status', 24)->default('pending');
            $table->uuid('requested_by_membership_id');
            $table->timestampTz('requested_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['id', 'business_id'],
                'signature_requests_id_business_unique',
            );

            $table->unique(
                ['decision_id', 'document_version_id'],
                'signature_requests_decision_document_unique',
            );

            $table->foreign(
                [
                    'decision_id',
                    'business_id',
                    'proposal_version_id',
                    'authority_snapshot_id',
                ],
                'signature_requests_decision_fk',
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
                ['document_version_id', 'business_id'],
                'signature_requests_document_version_fk',
            )
                ->references(['id', 'business_id'])
                ->on('document_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['requested_by_membership_id', 'business_id'],
                'signature_requests_requester_membership_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create(
            'signature_participants',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('signature_request_id');
                $table->uuid('decision_participant_id');
                $table->uuid('membership_id');
                $table->unsignedInteger('sequence');
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['id', 'business_id'],
                    'signature_participants_id_business_unique',
                );

                $table->unique(
                    ['id', 'business_id', 'signature_request_id'],
                    'signature_participants_request_binding_unique',
                );

                $table->unique(
                    ['signature_request_id', 'decision_participant_id'],
                    'signature_participants_decision_participant_unique',
                );

                $table->unique(
                    ['signature_request_id', 'membership_id'],
                    'signature_participants_membership_unique',
                );

                $table->unique(
                    ['signature_request_id', 'sequence'],
                    'signature_participants_sequence_unique',
                );

                $table->foreign(
                    ['signature_request_id', 'business_id'],
                    'signature_participants_request_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('signature_requests')
                    ->restrictOnDelete();

                $table->foreign(
                    ['decision_participant_id', 'business_id'],
                    'signature_participants_decision_participant_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('decision_participants')
                    ->restrictOnDelete();

                $table->foreign(
                    ['membership_id', 'business_id'],
                    'signature_participants_membership_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        Schema::create('signatures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('signature_request_id');
            $table->uuid('signature_participant_id');
            $table->uuid('membership_id');
            $table->uuid('document_version_id');
            $table->char('document_content_sha256', 64);
            $table->string('signature_method', 64);
            $table->text('consent_statement');
            $table->char('signing_session_hash', 64);
            $table->timestampTz('signed_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['signature_request_id', 'signature_participant_id'],
                'signatures_participant_unique',
            );

            $table->foreign(
                ['signature_request_id', 'business_id'],
                'signatures_request_fk',
            )
                ->references(['id', 'business_id'])
                ->on('signature_requests')
                ->restrictOnDelete();

            $table->foreign(
                [
                    'signature_participant_id',
                    'business_id',
                    'signature_request_id',
                ],
                'signatures_participant_fk',
            )
                ->references([
                    'id',
                    'business_id',
                    'signature_request_id',
                ])
                ->on('signature_participants')
                ->restrictOnDelete();

            $table->foreign(
                ['membership_id', 'business_id'],
                'signatures_membership_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign(
                ['document_version_id', 'business_id'],
                'signatures_document_version_fk',
            )
                ->references(['id', 'business_id'])
                ->on('document_versions')
                ->restrictOnDelete();
        });

        Schema::create('actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('decision_id')->nullable();
            $table->uuid('formal_record_version_id')->nullable();
            $table->uuid('assigned_membership_id');
            $table->uuid('created_by_membership_id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('open');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['id', 'business_id'],
                'actions_id_business_unique',
            );

            $table->foreign(
                ['decision_id', 'business_id'],
                'actions_decision_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('decisions')
                ->restrictOnDelete();

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'actions_record_version_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['assigned_membership_id', 'business_id'],
                'actions_assignee_membership_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'actions_creator_membership_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
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
                'reviews_id_business_unique',
            );

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'reviews_record_version_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['reviewer_membership_id', 'business_id'],
                'reviews_reviewer_membership_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'reviews_creator_membership_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create(
            'amendment_requests',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('formal_record_version_id');
                $table->uuid('review_id')->nullable();
                $table->uuid('requested_by_membership_id');
                $table->text('reason');
                $table->string('status', 24)->default('open');
                $table->uuid('resolved_by_membership_id')->nullable();
                $table->timestampTz('resolved_at')->nullable();
                $table->timestampsTz();

                $table->unique(
                    ['id', 'business_id'],
                    'amendment_requests_id_business_unique',
                );

                $table->foreign(
                    ['formal_record_version_id', 'business_id'],
                    'amendment_requests_record_version_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('formal_record_versions')
                    ->restrictOnDelete();

                $table->foreign(
                    ['review_id', 'business_id'],
                    'amendment_requests_review_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('reviews')
                    ->restrictOnDelete();

                $table->foreign(
                    ['requested_by_membership_id', 'business_id'],
                    'amendment_requests_requester_membership_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();

                $table->foreign(
                    ['resolved_by_membership_id', 'business_id'],
                    'amendment_requests_resolver_membership_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        DB::statement(
            "ALTER TABLE signature_requests
             ADD CONSTRAINT signature_requests_hash_check
             CHECK (document_content_sha256 ~ '^[0-9a-f]{64}$')",
        );

        DB::statement(
            "ALTER TABLE signature_requests
             ADD CONSTRAINT signature_requests_status_check
             CHECK (status IN ('pending', 'completed', 'cancelled'))",
        );

        DB::statement(
            "ALTER TABLE signature_requests
             ADD CONSTRAINT signature_requests_lifecycle_check
             CHECK (
                 (
                     status = 'pending'
                     AND completed_at IS NULL
                     AND cancelled_at IS NULL
                 )
                 OR
                 (
                     status = 'completed'
                     AND completed_at IS NOT NULL
                     AND cancelled_at IS NULL
                 )
                 OR
                 (
                     status = 'cancelled'
                     AND completed_at IS NULL
                     AND cancelled_at IS NOT NULL
                 )
             )",
        );

        DB::statement(
            'ALTER TABLE signature_participants
             ADD CONSTRAINT signature_participants_sequence_positive
             CHECK (sequence > 0)',
        );

        DB::statement(
            "ALTER TABLE signatures
             ADD CONSTRAINT signatures_document_hash_check
             CHECK (document_content_sha256 ~ '^[0-9a-f]{64}$')",
        );

        DB::statement(
            "ALTER TABLE signatures
             ADD CONSTRAINT signatures_session_hash_check
             CHECK (signing_session_hash ~ '^[0-9a-f]{64}$')",
        );

        DB::statement(
            "ALTER TABLE signatures
             ADD CONSTRAINT signatures_method_nonblank
             CHECK (
                 btrim(signature_method) <> ''
                 AND signature_method = btrim(signature_method)
             )",
        );

        DB::statement(
            "ALTER TABLE signatures
             ADD CONSTRAINT signatures_consent_nonblank
             CHECK (btrim(consent_statement) <> '')",
        );

        DB::statement(
            "ALTER TABLE actions
             ADD CONSTRAINT actions_title_nonblank
             CHECK (btrim(title) <> '')",
        );

        DB::statement(
            'ALTER TABLE actions
             ADD CONSTRAINT actions_target_check
             CHECK (
                 decision_id IS NOT NULL
                 OR formal_record_version_id IS NOT NULL
             )',
        );

        DB::statement(
            "ALTER TABLE actions
             ADD CONSTRAINT actions_status_check
             CHECK (
                 status IN (
                     'open',
                     'in_progress',
                     'completed',
                     'cancelled'
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE actions
             ADD CONSTRAINT actions_lifecycle_check
             CHECK (
                 (
                     status IN ('open', 'in_progress', 'cancelled')
                     AND completed_at IS NULL
                 )
                 OR
                 (
                     status = 'completed'
                     AND completed_at IS NOT NULL
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE reviews
             ADD CONSTRAINT reviews_status_check
             CHECK (status IN ('open', 'completed', 'cancelled'))",
        );

        DB::statement(
            "ALTER TABLE reviews
             ADD CONSTRAINT reviews_outcome_check
             CHECK (
                 outcome IS NULL
                 OR outcome IN (
                     'remains_valid',
                     'amendment_required',
                     'no_longer_applicable'
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE reviews
             ADD CONSTRAINT reviews_lifecycle_check
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
                 OR
                 (
                     status = 'cancelled'
                     AND outcome IS NULL
                     AND resolved_at IS NOT NULL
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE amendment_requests
             ADD CONSTRAINT amendment_requests_reason_nonblank
             CHECK (btrim(reason) <> '')",
        );

        DB::statement(
            "ALTER TABLE amendment_requests
             ADD CONSTRAINT amendment_requests_status_check
             CHECK (status IN ('open', 'accepted', 'rejected'))",
        );

        DB::statement(
            "ALTER TABLE amendment_requests
             ADD CONSTRAINT amendment_requests_lifecycle_check
             CHECK (
                 (
                     status = 'open'
                     AND resolved_by_membership_id IS NULL
                     AND resolved_at IS NULL
                 )
                 OR
                 (
                     status IN ('accepted', 'rejected')
                     AND resolved_by_membership_id IS NOT NULL
                     AND resolved_at IS NOT NULL
                 )
             )",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_signature_request()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    decision_status_value text;
    decision_outcome_value text;
    signature_required_value boolean;
    actual_document_hash text;
BEGIN
    SELECT
        decision_record.status,
        decision_record.outcome,
        snapshot.signature_required
    INTO
        decision_status_value,
        decision_outcome_value,
        signature_required_value
    FROM decisions decision_record
    JOIN authority_snapshots snapshot
      ON snapshot.id = decision_record.authority_snapshot_id
     AND snapshot.business_id = decision_record.business_id
     AND snapshot.proposal_version_id =
         decision_record.proposal_version_id
    WHERE decision_record.id = NEW.decision_id
      AND decision_record.business_id = NEW.business_id
      AND decision_record.proposal_version_id =
          NEW.proposal_version_id
      AND decision_record.authority_snapshot_id =
          NEW.authority_snapshot_id;

    IF
        decision_status_value IS DISTINCT FROM 'decided'
        OR decision_outcome_value IS DISTINCT FROM 'approved'
    THEN
        RAISE EXCEPTION 'Signature Request requires an Approved Decision';
    END IF;

    IF signature_required_value IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'Signature Request requires captured signature authority';
    END IF;

    SELECT document_version.content_sha256
    INTO actual_document_hash
    FROM document_versions document_version
    WHERE document_version.id = NEW.document_version_id
      AND document_version.business_id = NEW.business_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Signature Request Document Version does not exist in this Business';
    END IF;

    IF NEW.document_content_sha256 IS DISTINCT FROM actual_document_hash THEN
        RAISE EXCEPTION 'Signature Request must bind exact Document Version hash';
    END IF;

    IF NEW.status <> 'pending' THEN
        RAISE EXCEPTION 'Signature Request must begin Pending';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER signature_requests_validate
BEFORE INSERT
ON signature_requests
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_signature_request();

CREATE OR REPLACE FUNCTION pbr_protect_signature_request()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    eligible_signer_count integer;
    request_participant_count integer;
    completed_signature_count integer;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Signature Request history cannot be deleted';
    END IF;

    IF
        NEW.business_id IS DISTINCT FROM OLD.business_id
        OR NEW.decision_id IS DISTINCT FROM OLD.decision_id
        OR NEW.proposal_version_id IS DISTINCT FROM OLD.proposal_version_id
        OR NEW.authority_snapshot_id IS DISTINCT FROM OLD.authority_snapshot_id
        OR NEW.document_version_id IS DISTINCT FROM OLD.document_version_id
        OR NEW.document_content_sha256 IS DISTINCT FROM OLD.document_content_sha256
        OR NEW.requested_by_membership_id IS DISTINCT FROM OLD.requested_by_membership_id
        OR NEW.requested_at IS DISTINCT FROM OLD.requested_at
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Signature Request frozen bindings are immutable';
    END IF;

    IF OLD.status <> 'pending' THEN
        RAISE EXCEPTION 'Completed or Cancelled Signature Request is immutable';
    END IF;

    IF NEW.status NOT IN ('completed', 'cancelled') THEN
        RAISE EXCEPTION 'Signature Request may only resolve from Pending';
    END IF;

    IF NEW.status = 'completed' THEN
        SELECT count(*)
        INTO eligible_signer_count
        FROM decision_participants participant
        WHERE participant.business_id = OLD.business_id
          AND participant.decision_id = OLD.decision_id
          AND participant.status = 'eligible'
          AND participant.can_sign = true;

        SELECT count(*)
        INTO request_participant_count
        FROM signature_participants participant
        WHERE participant.business_id = OLD.business_id
          AND participant.signature_request_id = OLD.id;

        SELECT count(*)
        INTO completed_signature_count
        FROM signatures signature_record
        WHERE signature_record.business_id = OLD.business_id
          AND signature_record.signature_request_id = OLD.id;

        IF eligible_signer_count < 1 THEN
            RAISE EXCEPTION 'Signature Request has no eligible required signers';
        END IF;

        IF request_participant_count <> eligible_signer_count THEN
            RAISE EXCEPTION 'Signature Request must capture the complete eligible signer set';
        END IF;

        IF completed_signature_count <> request_participant_count THEN
            RAISE EXCEPTION 'Signature Request cannot complete until every participant has signed';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER signature_requests_protect_history
BEFORE UPDATE OR DELETE
ON signature_requests
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_signature_request();

CREATE OR REPLACE FUNCTION pbr_validate_signature_participant()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    request_status_value text;
    request_decision_id uuid;
    participant_membership_id uuid;
    participant_status_value text;
    participant_can_sign boolean;
BEGIN
    SELECT
        request.status,
        request.decision_id
    INTO
        request_status_value,
        request_decision_id
    FROM signature_requests request
    WHERE request.id = NEW.signature_request_id
      AND request.business_id = NEW.business_id;

    IF request_status_value IS DISTINCT FROM 'pending' THEN
        RAISE EXCEPTION 'Signature Participant requires a Pending Signature Request';
    END IF;

    SELECT
        participant.membership_id,
        participant.status,
        participant.can_sign
    INTO
        participant_membership_id,
        participant_status_value,
        participant_can_sign
    FROM decision_participants participant
    WHERE participant.id = NEW.decision_participant_id
      AND participant.business_id = NEW.business_id
      AND participant.decision_id = request_decision_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Signature Participant must belong to the Signature Request Decision';
    END IF;

    IF participant_membership_id <> NEW.membership_id THEN
        RAISE EXCEPTION 'Signature Participant Membership must match Decision Participant';
    END IF;

    IF
        participant_status_value <> 'eligible'
        OR participant_can_sign IS DISTINCT FROM true
    THEN
        RAISE EXCEPTION 'Signature Participant must be an eligible signer';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER signature_participants_validate
BEFORE INSERT
ON signature_participants
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_signature_participant();

CREATE OR REPLACE FUNCTION pbr_protect_signature_participant()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Signature Participant snapshot is immutable';
END;
$$;

CREATE TRIGGER signature_participants_immutable
BEFORE UPDATE OR DELETE
ON signature_participants
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_signature_participant();

CREATE OR REPLACE FUNCTION pbr_validate_signature()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    request_status_value text;
    request_document_version_id uuid;
    request_document_hash text;
    participant_membership_id uuid;
    participant_sequence integer;
    actual_document_hash text;
    lower_participant_count integer;
    lower_signature_count integer;
BEGIN
    SELECT
        request.status,
        request.document_version_id,
        request.document_content_sha256
    INTO
        request_status_value,
        request_document_version_id,
        request_document_hash
    FROM signature_requests request
    WHERE request.id = NEW.signature_request_id
      AND request.business_id = NEW.business_id;

    IF request_status_value IS DISTINCT FROM 'pending' THEN
        RAISE EXCEPTION 'Signature requires a Pending Signature Request';
    END IF;

    SELECT
        participant.membership_id,
        participant.sequence
    INTO
        participant_membership_id,
        participant_sequence
    FROM signature_participants participant
    WHERE participant.id = NEW.signature_participant_id
      AND participant.business_id = NEW.business_id
      AND participant.signature_request_id =
          NEW.signature_request_id;

    IF NOT FOUND OR participant_membership_id <> NEW.membership_id THEN
        RAISE EXCEPTION 'Signature must be made by the exact Signature Participant Membership';
    END IF;

    IF NEW.document_version_id IS DISTINCT FROM request_document_version_id THEN
        RAISE EXCEPTION 'Signature must bind the Signature Request Document Version';
    END IF;

    IF NEW.document_content_sha256 IS DISTINCT FROM request_document_hash THEN
        RAISE EXCEPTION 'Signature must bind the Signature Request Document hash';
    END IF;

    SELECT document_version.content_sha256
    INTO actual_document_hash
    FROM document_versions document_version
    WHERE document_version.id = NEW.document_version_id
      AND document_version.business_id = NEW.business_id;

    IF
        NOT FOUND
        OR actual_document_hash IS DISTINCT FROM
            NEW.document_content_sha256
    THEN
        RAISE EXCEPTION 'Signature Document Version/hash binding is invalid';
    END IF;

    SELECT count(*)
    INTO lower_participant_count
    FROM signature_participants participant
    WHERE participant.business_id = NEW.business_id
      AND participant.signature_request_id =
          NEW.signature_request_id
      AND participant.sequence < participant_sequence;

    SELECT count(*)
    INTO lower_signature_count
    FROM signatures signature_record
    JOIN signature_participants participant
      ON participant.id =
          signature_record.signature_participant_id
     AND participant.business_id =
          signature_record.business_id
     AND participant.signature_request_id =
          signature_record.signature_request_id
    WHERE signature_record.business_id = NEW.business_id
      AND signature_record.signature_request_id =
          NEW.signature_request_id
      AND participant.sequence < participant_sequence;

    IF lower_signature_count <> lower_participant_count THEN
        RAISE EXCEPTION 'Signature signing order has not been satisfied';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER signatures_validate
BEFORE INSERT
ON signatures
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_signature();

CREATE OR REPLACE FUNCTION pbr_protect_signature()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Signature evidence is immutable';
END;
$$;

CREATE TRIGGER signatures_immutable
BEFORE UPDATE OR DELETE
ON signatures
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_signature();

CREATE OR REPLACE FUNCTION pbr_validate_action()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    decision_status_value text;
    decision_outcome_value text;
    record_state_value text;
BEGIN
    IF NEW.decision_id IS NOT NULL THEN
        SELECT
            decision_record.status,
            decision_record.outcome
        INTO
            decision_status_value,
            decision_outcome_value
        FROM decisions decision_record
        WHERE decision_record.id = NEW.decision_id
          AND decision_record.business_id = NEW.business_id;

        IF
            decision_status_value IS DISTINCT FROM 'decided'
            OR decision_outcome_value IS DISTINCT FROM 'approved'
        THEN
            RAISE EXCEPTION 'Action Decision source must be Approved';
        END IF;
    END IF;

    IF NEW.formal_record_version_id IS NOT NULL THEN
        SELECT transition.to_state
        INTO record_state_value
        FROM record_version_state_transitions transition
        WHERE transition.formal_record_version_id =
            NEW.formal_record_version_id
          AND transition.business_id = NEW.business_id
        ORDER BY transition.sequence DESC
        LIMIT 1;

        IF record_state_value IS DISTINCT FROM 'effective' THEN
            RAISE EXCEPTION 'Action Formal Record source must be Effective';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER actions_validate
BEFORE INSERT
ON actions
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_action();

CREATE OR REPLACE FUNCTION pbr_protect_action()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Action history cannot be deleted';
    END IF;

    IF
        NEW.business_id IS DISTINCT FROM OLD.business_id
        OR NEW.decision_id IS DISTINCT FROM OLD.decision_id
        OR NEW.formal_record_version_id IS DISTINCT FROM OLD.formal_record_version_id
        OR NEW.assigned_membership_id IS DISTINCT FROM OLD.assigned_membership_id
        OR NEW.created_by_membership_id IS DISTINCT FROM OLD.created_by_membership_id
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Action source and assignment identity are immutable';
    END IF;

    IF OLD.status IN ('completed', 'cancelled') THEN
        RAISE EXCEPTION 'Completed or Cancelled Action is immutable';
    END IF;

    IF
        OLD.status = 'in_progress'
        AND NEW.status = 'open'
    THEN
        RAISE EXCEPTION 'Action cannot move backward to Open';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER actions_protect_history
BEFORE UPDATE OR DELETE
ON actions
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_action();

CREATE OR REPLACE FUNCTION pbr_validate_review()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    record_state_value text;
BEGIN
    SELECT transition.to_state
    INTO record_state_value
    FROM record_version_state_transitions transition
    WHERE transition.formal_record_version_id =
        NEW.formal_record_version_id
      AND transition.business_id = NEW.business_id
    ORDER BY transition.sequence DESC
    LIMIT 1;

    IF record_state_value IS DISTINCT FROM 'effective' THEN
        RAISE EXCEPTION 'Review requires an Effective Formal Record Version';
    END IF;

    IF NEW.status <> 'open' THEN
        RAISE EXCEPTION 'Review must begin Open';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER reviews_validate
BEFORE INSERT
ON reviews
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_review();

CREATE OR REPLACE FUNCTION pbr_protect_review()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Review history cannot be deleted';
    END IF;

    IF
        NEW.business_id IS DISTINCT FROM OLD.business_id
        OR NEW.formal_record_version_id IS DISTINCT FROM OLD.formal_record_version_id
        OR NEW.reviewer_membership_id IS DISTINCT FROM OLD.reviewer_membership_id
        OR NEW.created_by_membership_id IS DISTINCT FROM OLD.created_by_membership_id
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Review source identity is immutable';
    END IF;

    IF OLD.status IN ('completed', 'cancelled') THEN
        RAISE EXCEPTION 'Completed or Cancelled Review is immutable';
    END IF;

    IF NEW.status NOT IN ('open', 'completed', 'cancelled') THEN
        RAISE EXCEPTION 'Invalid Review lifecycle transition';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER reviews_protect_history
BEFORE UPDATE OR DELETE
ON reviews
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_review();

CREATE OR REPLACE FUNCTION pbr_validate_amendment_request()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    record_state_value text;
    review_record_version_id uuid;
    review_status_value text;
    review_outcome_value text;
BEGIN
    SELECT transition.to_state
    INTO record_state_value
    FROM record_version_state_transitions transition
    WHERE transition.formal_record_version_id =
        NEW.formal_record_version_id
      AND transition.business_id = NEW.business_id
    ORDER BY transition.sequence DESC
    LIMIT 1;

    IF record_state_value IS DISTINCT FROM 'effective' THEN
        RAISE EXCEPTION 'Amendment Request requires an Effective Formal Record Version';
    END IF;

    IF NEW.review_id IS NOT NULL THEN
        SELECT
            review_record.formal_record_version_id,
            review_record.status,
            review_record.outcome
        INTO
            review_record_version_id,
            review_status_value,
            review_outcome_value
        FROM reviews review_record
        WHERE review_record.id = NEW.review_id
          AND review_record.business_id = NEW.business_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Amendment Request Review does not exist in this Business';
        END IF;

        IF
            review_record_version_id IS DISTINCT FROM
                NEW.formal_record_version_id
            OR review_status_value IS DISTINCT FROM 'completed'
            OR review_outcome_value IS DISTINCT FROM
                'amendment_required'
        THEN
            RAISE EXCEPTION 'Amendment Request Review must be completed with Amendment Required for the same record version';
        END IF;
    END IF;

    IF NEW.status <> 'open' THEN
        RAISE EXCEPTION 'Amendment Request must begin Open';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER amendment_requests_validate
BEFORE INSERT
ON amendment_requests
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_amendment_request();

CREATE OR REPLACE FUNCTION pbr_protect_amendment_request()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Amendment Request history cannot be deleted';
    END IF;

    IF
        NEW.business_id IS DISTINCT FROM OLD.business_id
        OR NEW.formal_record_version_id IS DISTINCT FROM OLD.formal_record_version_id
        OR NEW.review_id IS DISTINCT FROM OLD.review_id
        OR NEW.requested_by_membership_id IS DISTINCT FROM OLD.requested_by_membership_id
        OR NEW.reason IS DISTINCT FROM OLD.reason
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Amendment Request identity and reason are immutable';
    END IF;

    IF OLD.status <> 'open' THEN
        RAISE EXCEPTION 'Resolved Amendment Request is immutable';
    END IF;

    IF NEW.status NOT IN ('accepted', 'rejected') THEN
        RAISE EXCEPTION 'Amendment Request may only resolve from Open';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER amendment_requests_protect_history
BEFORE UPDATE OR DELETE
ON amendment_requests
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_amendment_request();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('amendment_requests');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('actions');
        Schema::dropIfExists('signatures');
        Schema::dropIfExists('signature_participants');
        Schema::dropIfExists('signature_requests');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_amendment_request();',
        );
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_amendment_request();',
        );
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_protect_review();');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_validate_review();');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_protect_action();');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_validate_action();');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_protect_signature();');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_validate_signature();');
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_signature_participant();',
        );
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_signature_participant();',
        );
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_signature_request();',
        );
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_signature_request();',
        );
    }
};
