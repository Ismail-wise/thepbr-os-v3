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
        Schema::table('signature_requests', function (Blueprint $table): void {
            $table->timestampTz('sent_at')->nullable();
            $table->uuid('cancelled_by_membership_id')->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->uuid('declined_by_membership_id')->nullable();
            $table->timestampTz('expired_at')->nullable();

            $table->foreign(
                ['cancelled_by_membership_id', 'business_id'],
                'signature_requests_canceller_membership_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign(
                ['declined_by_membership_id', 'business_id'],
                'signature_requests_decliner_membership_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::table('actions', function (Blueprint $table): void {
            $table->text('blocked_reason')->nullable();
        });

        Schema::create('governance_delegations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('delegator_membership_id');
            $table->uuid('delegate_membership_id');
            $table->string('decision_type', 160)->nullable();
            $table->string('scope', 255);
            $table->string('status', 24)->default('active');
            $table->timestampTz('effective_from');
            $table->timestampTz('expires_at')->nullable();
            $table->uuid('created_by_membership_id');
            $table->uuid('revoked_by_membership_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'governance_delegations_id_business_unique');

            foreach ([
                ['delegator_membership_id', 'governance_delegations_delegator_fk'],
                ['delegate_membership_id', 'governance_delegations_delegate_fk'],
                ['created_by_membership_id', 'governance_delegations_creator_fk'],
                ['revoked_by_membership_id', 'governance_delegations_revoker_fk'],
            ] as [$column, $name]) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            }

            $table->index(
                ['business_id', 'delegate_membership_id', 'status'],
                'governance_delegations_delegate_status_index',
            );
        });

        Schema::create('emergency_authority_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('grantee_membership_id');
            $table->string('decision_type', 160)->nullable();
            $table->string('scope', 255);
            $table->text('reason');
            $table->string('status', 24)->default('active');
            $table->timestampTz('effective_from');
            $table->timestampTz('expires_at');
            $table->uuid('created_by_membership_id');
            $table->uuid('revoked_by_membership_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'emergency_authority_grants_id_business_unique');

            foreach ([
                ['grantee_membership_id', 'emergency_authority_grants_grantee_fk'],
                ['created_by_membership_id', 'emergency_authority_grants_creator_fk'],
                ['revoked_by_membership_id', 'emergency_authority_grants_revoker_fk'],
            ] as [$column, $name]) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            }

            $table->index(
                ['business_id', 'grantee_membership_id', 'status'],
                'emergency_authority_grants_grantee_status_index',
            );
        });

        Schema::create('governance_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('recipient_membership_id');
            $table->string('kind', 96);
            $table->string('subject_type', 96);
            $table->uuid('subject_id');
            $table->string('status', 16)->default('unread');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'governance_notifications_id_business_unique');

            $table->foreign(
                ['recipient_membership_id', 'business_id'],
                'governance_notifications_recipient_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->index(
                ['business_id', 'recipient_membership_id', 'status', 'created_at'],
                'governance_notifications_recipient_status_index',
            );
        });

        DB::statement('ALTER TABLE signature_requests DROP CONSTRAINT IF EXISTS signature_requests_status_check');
        DB::statement('ALTER TABLE signature_requests DROP CONSTRAINT IF EXISTS signature_requests_lifecycle_check');
        DB::statement("ALTER TABLE signature_requests ALTER COLUMN status SET DEFAULT 'draft'");

        DB::statement(
            "ALTER TABLE signature_requests
             ADD CONSTRAINT signature_requests_status_check
             CHECK (status IN (
                 'draft',
                 'sent',
                 'partially_signed',
                 'fully_signed',
                 'completed',
                 'expired',
                 'cancelled',
                 'declined'
             ))",
        );

        DB::statement(
            "ALTER TABLE signature_requests
             ADD CONSTRAINT signature_requests_lifecycle_check
             CHECK (
                 (
                     status = 'draft'
                     AND sent_at IS NULL
                     AND completed_at IS NULL
                     AND cancelled_at IS NULL
                     AND cancelled_by_membership_id IS NULL
                     AND declined_at IS NULL
                     AND declined_by_membership_id IS NULL
                     AND expired_at IS NULL
                 )
                 OR
                 (
                     status IN ('sent', 'partially_signed', 'fully_signed')
                     AND sent_at IS NOT NULL
                     AND completed_at IS NULL
                     AND cancelled_at IS NULL
                     AND cancelled_by_membership_id IS NULL
                     AND declined_at IS NULL
                     AND declined_by_membership_id IS NULL
                     AND expired_at IS NULL
                 )
                 OR
                 (
                     status = 'completed'
                     AND sent_at IS NOT NULL
                     AND completed_at IS NOT NULL
                     AND cancelled_at IS NULL
                     AND cancelled_by_membership_id IS NULL
                     AND declined_at IS NULL
                     AND declined_by_membership_id IS NULL
                     AND expired_at IS NULL
                 )
                 OR
                 (
                     status = 'cancelled'
                     AND completed_at IS NULL
                     AND cancelled_at IS NOT NULL
                     AND cancelled_by_membership_id IS NOT NULL
                     AND declined_at IS NULL
                     AND declined_by_membership_id IS NULL
                     AND expired_at IS NULL
                 )
                 OR
                 (
                     status = 'declined'
                     AND sent_at IS NOT NULL
                     AND completed_at IS NULL
                     AND cancelled_at IS NULL
                     AND cancelled_by_membership_id IS NULL
                     AND declined_at IS NOT NULL
                     AND declined_by_membership_id IS NOT NULL
                     AND expired_at IS NULL
                 )
                 OR
                 (
                     status = 'expired'
                     AND completed_at IS NULL
                     AND cancelled_at IS NULL
                     AND cancelled_by_membership_id IS NULL
                     AND declined_at IS NULL
                     AND declined_by_membership_id IS NULL
                     AND expired_at IS NOT NULL
                 )
             )",
        );

        DB::statement('ALTER TABLE actions DROP CONSTRAINT IF EXISTS actions_status_check');
        DB::statement('ALTER TABLE actions DROP CONSTRAINT IF EXISTS actions_lifecycle_check');

        DB::statement(
            "ALTER TABLE actions
             ADD CONSTRAINT actions_status_check
             CHECK (status IN (
                 'open',
                 'in_progress',
                 'blocked',
                 'completed',
                 'cancelled'
             ))",
        );

        DB::statement(
            "ALTER TABLE actions
             ADD CONSTRAINT actions_lifecycle_check
             CHECK (
                 (
                     status IN ('open', 'in_progress', 'cancelled')
                     AND completed_at IS NULL
                     AND blocked_reason IS NULL
                 )
                 OR
                 (
                     status = 'blocked'
                     AND completed_at IS NULL
                     AND blocked_reason IS NOT NULL
                     AND btrim(blocked_reason) <> ''
                 )
                 OR
                 (
                     status = 'completed'
                     AND completed_at IS NOT NULL
                     AND blocked_reason IS NULL
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE governance_delegations
             ADD CONSTRAINT governance_delegations_members_distinct
             CHECK (delegator_membership_id <> delegate_membership_id)",
        );
        DB::statement(
            "ALTER TABLE governance_delegations
             ADD CONSTRAINT governance_delegations_scope_nonblank
             CHECK (btrim(scope) <> '' AND scope = btrim(scope))",
        );
        DB::statement(
            "ALTER TABLE governance_delegations
             ADD CONSTRAINT governance_delegations_decision_type_canonical
             CHECK (
                 decision_type IS NULL
                 OR (btrim(decision_type) <> '' AND decision_type = btrim(decision_type))
             )",
        );
        DB::statement(
            "ALTER TABLE governance_delegations
             ADD CONSTRAINT governance_delegations_status_check
             CHECK (status IN ('active', 'revoked'))",
        );
        DB::statement(
            "ALTER TABLE governance_delegations
             ADD CONSTRAINT governance_delegations_effective_range
             CHECK (expires_at IS NULL OR expires_at > effective_from)",
        );
        DB::statement(
            "ALTER TABLE governance_delegations
             ADD CONSTRAINT governance_delegations_revocation_state
             CHECK (
                 (status = 'active' AND revoked_by_membership_id IS NULL AND revoked_at IS NULL)
                 OR
                 (status = 'revoked' AND revoked_by_membership_id IS NOT NULL AND revoked_at IS NOT NULL)
             )",
        );

        DB::statement(
            "ALTER TABLE emergency_authority_grants
             ADD CONSTRAINT emergency_authority_scope_nonblank
             CHECK (btrim(scope) <> '' AND scope = btrim(scope))",
        );
        DB::statement(
            "ALTER TABLE emergency_authority_grants
             ADD CONSTRAINT emergency_authority_reason_nonblank
             CHECK (btrim(reason) <> '')",
        );
        DB::statement(
            "ALTER TABLE emergency_authority_grants
             ADD CONSTRAINT emergency_authority_decision_type_canonical
             CHECK (
                 decision_type IS NULL
                 OR (btrim(decision_type) <> '' AND decision_type = btrim(decision_type))
             )",
        );
        DB::statement(
            "ALTER TABLE emergency_authority_grants
             ADD CONSTRAINT emergency_authority_status_check
             CHECK (status IN ('active', 'revoked'))",
        );
        DB::statement(
            "ALTER TABLE emergency_authority_grants
             ADD CONSTRAINT emergency_authority_effective_range
             CHECK (expires_at > effective_from)",
        );
        DB::statement(
            "ALTER TABLE emergency_authority_grants
             ADD CONSTRAINT emergency_authority_revocation_state
             CHECK (
                 (status = 'active' AND revoked_by_membership_id IS NULL AND revoked_at IS NULL)
                 OR
                 (status = 'revoked' AND revoked_by_membership_id IS NOT NULL AND revoked_at IS NOT NULL)
             )",
        );

        DB::statement(
            "ALTER TABLE governance_notifications
             ADD CONSTRAINT governance_notifications_kind_check
             CHECK (kind ~ '^[a-z][a-z0-9_.]{0,95}$')",
        );
        DB::statement(
            "ALTER TABLE governance_notifications
             ADD CONSTRAINT governance_notifications_subject_type_check
             CHECK (subject_type ~ '^[a-z][a-z0-9_.]{0,95}$')",
        );
        DB::statement(
            "ALTER TABLE governance_notifications
             ADD CONSTRAINT governance_notifications_status_check
             CHECK (status IN ('unread', 'read'))",
        );
        DB::statement(
            "ALTER TABLE governance_notifications
             ADD CONSTRAINT governance_notifications_lifecycle_check
             CHECK (
                 (status = 'unread' AND read_at IS NULL)
                 OR
                 (status = 'read' AND read_at IS NOT NULL)
             )",
        );

        DB::unprepared('DROP TRIGGER IF EXISTS signature_requests_validate ON signature_requests');
        DB::unprepared('DROP TRIGGER IF EXISTS signature_requests_protect_history ON signature_requests');
        DB::unprepared('DROP TRIGGER IF EXISTS signature_participants_validate ON signature_participants');
        DB::unprepared('DROP TRIGGER IF EXISTS signatures_validate ON signatures');
        DB::unprepared('DROP TRIGGER IF EXISTS actions_protect_history ON actions');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f3_validate_signature_request_v2()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    decision_status_value text;
    decision_outcome_value text;
    signature_required_value boolean;
    actual_document_hash text;
BEGIN
    SELECT d.status, d.outcome, s.signature_required
    INTO decision_status_value, decision_outcome_value, signature_required_value
    FROM decisions d
    JOIN authority_snapshots s
      ON s.id = d.authority_snapshot_id
     AND s.business_id = d.business_id
     AND s.proposal_version_id = d.proposal_version_id
    WHERE d.id = NEW.decision_id
      AND d.business_id = NEW.business_id
      AND d.proposal_version_id = NEW.proposal_version_id
      AND d.authority_snapshot_id = NEW.authority_snapshot_id;

    IF decision_status_value IS DISTINCT FROM 'decided'
       OR decision_outcome_value IS DISTINCT FROM 'approved'
    THEN
        RAISE EXCEPTION 'Signature Request requires an Approved Decision';
    END IF;

    IF signature_required_value IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'Signature Request requires captured signature authority';
    END IF;

    SELECT content_sha256
    INTO actual_document_hash
    FROM document_versions
    WHERE id = NEW.document_version_id
      AND business_id = NEW.business_id;

    IF NOT FOUND OR NEW.document_content_sha256 IS DISTINCT FROM actual_document_hash THEN
        RAISE EXCEPTION 'Signature Request must bind exact Document Version/hash';
    END IF;

    IF NEW.status <> 'draft' THEN
        RAISE EXCEPTION 'Signature Request must begin Draft';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER signature_requests_validate
BEFORE INSERT ON signature_requests
FOR EACH ROW
EXECUTE FUNCTION pbr_f3_validate_signature_request_v2();

CREATE OR REPLACE FUNCTION pbr_f3_protect_signature_request_v2()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    eligible_signer_count integer;
    participant_count integer;
    signature_count integer;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Signature Request history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
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

    IF OLD.status IN ('completed', 'expired', 'cancelled', 'declined') THEN
        RAISE EXCEPTION 'Resolved Signature Request is immutable';
    END IF;

    IF OLD.status = 'draft' AND NEW.status NOT IN ('sent', 'cancelled', 'expired') THEN
        RAISE EXCEPTION 'Invalid Draft Signature Request transition';
    END IF;

    IF OLD.status = 'sent' AND NEW.status NOT IN ('partially_signed', 'fully_signed', 'cancelled', 'declined', 'expired') THEN
        RAISE EXCEPTION 'Invalid Sent Signature Request transition';
    END IF;

    IF OLD.status = 'partially_signed'
       AND NEW.status NOT IN ('partially_signed', 'fully_signed', 'cancelled', 'declined', 'expired')
    THEN
        RAISE EXCEPTION 'Invalid Partially Signed transition';
    END IF;

    IF OLD.status = 'fully_signed' AND NEW.status <> 'completed' THEN
        RAISE EXCEPTION 'Fully Signed Signature Request may only complete';
    END IF;

    SELECT count(*)
    INTO eligible_signer_count
    FROM decision_participants
    WHERE business_id = OLD.business_id
      AND decision_id = OLD.decision_id
      AND status = 'eligible'
      AND can_sign = true;

    SELECT count(*)
    INTO participant_count
    FROM signature_participants
    WHERE business_id = OLD.business_id
      AND signature_request_id = OLD.id;

    SELECT count(*)
    INTO signature_count
    FROM signatures
    WHERE business_id = OLD.business_id
      AND signature_request_id = OLD.id;

    IF NEW.status = 'sent' THEN
        IF eligible_signer_count < 1 OR participant_count <> eligible_signer_count THEN
            RAISE EXCEPTION 'Signature Request must capture complete eligible signer set before Sent';
        END IF;
    END IF;

    IF NEW.status = 'partially_signed' THEN
        IF signature_count < 1 OR signature_count >= participant_count THEN
            RAISE EXCEPTION 'Partially Signed state does not match signature evidence';
        END IF;
    END IF;

    IF NEW.status IN ('fully_signed', 'completed') THEN
        IF participant_count < 1 OR signature_count <> participant_count THEN
            RAISE EXCEPTION 'Fully Signed/Completed state requires every participant signature';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER signature_requests_protect_history
BEFORE UPDATE OR DELETE ON signature_requests
FOR EACH ROW
EXECUTE FUNCTION pbr_f3_protect_signature_request_v2();

CREATE OR REPLACE FUNCTION pbr_f3_validate_signature_participant_v2()
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
    SELECT status, decision_id
    INTO request_status_value, request_decision_id
    FROM signature_requests
    WHERE id = NEW.signature_request_id
      AND business_id = NEW.business_id;

    IF request_status_value IS DISTINCT FROM 'draft' THEN
        RAISE EXCEPTION 'Signature Participant requires a Draft Signature Request';
    END IF;

    SELECT membership_id, status, can_sign
    INTO participant_membership_id, participant_status_value, participant_can_sign
    FROM decision_participants
    WHERE id = NEW.decision_participant_id
      AND business_id = NEW.business_id
      AND decision_id = request_decision_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Signature Participant must belong to the Signature Request Decision';
    END IF;

    IF participant_membership_id <> NEW.membership_id THEN
        RAISE EXCEPTION 'Signature Participant Membership must match Decision Participant';
    END IF;

    IF participant_status_value <> 'eligible' OR participant_can_sign IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'Signature Participant must be an eligible signer';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER signature_participants_validate
BEFORE INSERT ON signature_participants
FOR EACH ROW
EXECUTE FUNCTION pbr_f3_validate_signature_participant_v2();

CREATE OR REPLACE FUNCTION pbr_f3_validate_signature_v2()
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
    SELECT status, document_version_id, document_content_sha256
    INTO request_status_value, request_document_version_id, request_document_hash
    FROM signature_requests
    WHERE id = NEW.signature_request_id
      AND business_id = NEW.business_id;

    IF request_status_value NOT IN ('sent', 'partially_signed') THEN
        RAISE EXCEPTION 'Signature requires a Sent or Partially Signed Signature Request';
    END IF;

    SELECT membership_id, sequence
    INTO participant_membership_id, participant_sequence
    FROM signature_participants
    WHERE id = NEW.signature_participant_id
      AND business_id = NEW.business_id
      AND signature_request_id = NEW.signature_request_id;

    IF NOT FOUND OR participant_membership_id <> NEW.membership_id THEN
        RAISE EXCEPTION 'Signature must be made by the exact Signature Participant Membership';
    END IF;

    IF NEW.document_version_id IS DISTINCT FROM request_document_version_id
       OR NEW.document_content_sha256 IS DISTINCT FROM request_document_hash
    THEN
        RAISE EXCEPTION 'Signature must bind exact Signature Request Document Version/hash';
    END IF;

    SELECT content_sha256
    INTO actual_document_hash
    FROM document_versions
    WHERE id = NEW.document_version_id
      AND business_id = NEW.business_id;

    IF NOT FOUND OR actual_document_hash IS DISTINCT FROM NEW.document_content_sha256 THEN
        RAISE EXCEPTION 'Signature Document Version/hash binding is invalid';
    END IF;

    SELECT count(*)
    INTO lower_participant_count
    FROM signature_participants
    WHERE business_id = NEW.business_id
      AND signature_request_id = NEW.signature_request_id
      AND sequence < participant_sequence;

    SELECT count(*)
    INTO lower_signature_count
    FROM signatures s
    JOIN signature_participants p
      ON p.id = s.signature_participant_id
     AND p.business_id = s.business_id
     AND p.signature_request_id = s.signature_request_id
    WHERE s.business_id = NEW.business_id
      AND s.signature_request_id = NEW.signature_request_id
      AND p.sequence < participant_sequence;

    IF lower_signature_count <> lower_participant_count THEN
        RAISE EXCEPTION 'Signature signing order has not been satisfied';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER signatures_validate
BEFORE INSERT ON signatures
FOR EACH ROW
EXECUTE FUNCTION pbr_f3_validate_signature_v2();

CREATE OR REPLACE FUNCTION pbr_f3_protect_action_v2()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Action history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
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

    IF OLD.status = 'open' AND NEW.status NOT IN ('in_progress', 'blocked', 'completed', 'cancelled') THEN
        RAISE EXCEPTION 'Invalid Open Action transition';
    END IF;

    IF OLD.status = 'in_progress' AND NEW.status NOT IN ('blocked', 'completed', 'cancelled') THEN
        RAISE EXCEPTION 'Invalid In Progress Action transition';
    END IF;

    IF OLD.status = 'blocked' AND NEW.status NOT IN ('in_progress', 'completed', 'cancelled') THEN
        RAISE EXCEPTION 'Invalid Blocked Action transition';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER actions_protect_history
BEFORE UPDATE OR DELETE ON actions
FOR EACH ROW
EXECUTE FUNCTION pbr_f3_protect_action_v2();

CREATE OR REPLACE FUNCTION pbr_f3_protect_delegation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Governance Delegation history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.delegator_membership_id IS DISTINCT FROM OLD.delegator_membership_id
       OR NEW.delegate_membership_id IS DISTINCT FROM OLD.delegate_membership_id
       OR NEW.decision_type IS DISTINCT FROM OLD.decision_type
       OR NEW.scope IS DISTINCT FROM OLD.scope
       OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
       OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
       OR NEW.created_by_membership_id IS DISTINCT FROM OLD.created_by_membership_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Governance Delegation scope and source are immutable';
    END IF;

    IF OLD.status <> 'active' OR NEW.status <> 'revoked' THEN
        RAISE EXCEPTION 'Governance Delegation may only be revoked once';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER governance_delegations_protect_history
BEFORE UPDATE OR DELETE ON governance_delegations
FOR EACH ROW
EXECUTE FUNCTION pbr_f3_protect_delegation();

CREATE OR REPLACE FUNCTION pbr_f3_protect_emergency_authority()
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
       OR NEW.reason IS DISTINCT FROM OLD.reason
       OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
       OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
       OR NEW.created_by_membership_id IS DISTINCT FROM OLD.created_by_membership_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Emergency Authority scope and source are immutable';
    END IF;

    IF OLD.status <> 'active' OR NEW.status <> 'revoked' THEN
        RAISE EXCEPTION 'Emergency Authority may only be revoked once';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER emergency_authority_grants_protect_history
BEFORE UPDATE OR DELETE ON emergency_authority_grants
FOR EACH ROW
EXECUTE FUNCTION pbr_f3_protect_emergency_authority();

CREATE OR REPLACE FUNCTION pbr_f3_protect_governance_notification()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Governance Notification history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.recipient_membership_id IS DISTINCT FROM OLD.recipient_membership_id
       OR NEW.kind IS DISTINCT FROM OLD.kind
       OR NEW.subject_type IS DISTINCT FROM OLD.subject_type
       OR NEW.subject_id IS DISTINCT FROM OLD.subject_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Governance Notification identity is immutable';
    END IF;

    IF OLD.status <> 'unread' OR NEW.status <> 'read' OR NEW.read_at IS NULL THEN
        RAISE EXCEPTION 'Governance Notification may only move Unread to Read';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER governance_notifications_protect_history
BEFORE UPDATE OR DELETE ON governance_notifications
FOR EACH ROW
EXECUTE FUNCTION pbr_f3_protect_governance_notification();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS governance_notifications_protect_history ON governance_notifications');
        DB::unprepared('DROP TRIGGER IF EXISTS emergency_authority_grants_protect_history ON emergency_authority_grants');
        DB::unprepared('DROP TRIGGER IF EXISTS governance_delegations_protect_history ON governance_delegations');

        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f3_protect_governance_notification()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f3_protect_emergency_authority()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f3_protect_delegation()');

        DB::unprepared('DROP TRIGGER IF EXISTS actions_protect_history ON actions');
        DB::unprepared('DROP TRIGGER IF EXISTS signatures_validate ON signatures');
        DB::unprepared('DROP TRIGGER IF EXISTS signature_participants_validate ON signature_participants');
        DB::unprepared('DROP TRIGGER IF EXISTS signature_requests_protect_history ON signature_requests');
        DB::unprepared('DROP TRIGGER IF EXISTS signature_requests_validate ON signature_requests');

        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f3_protect_action_v2()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f3_validate_signature_v2()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f3_validate_signature_participant_v2()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f3_protect_signature_request_v2()');
        DB::unprepared('DROP FUNCTION IF EXISTS pbr_f3_validate_signature_request_v2()');

        Schema::dropIfExists('governance_notifications');
        Schema::dropIfExists('emergency_authority_grants');
        Schema::dropIfExists('governance_delegations');

        DB::statement('ALTER TABLE actions DROP CONSTRAINT IF EXISTS actions_status_check');
        DB::statement('ALTER TABLE actions DROP CONSTRAINT IF EXISTS actions_lifecycle_check');

        Schema::table('actions', function (Blueprint $table): void {
            $table->dropColumn('blocked_reason');
        });

        DB::statement(
            "ALTER TABLE actions
             ADD CONSTRAINT actions_status_check
             CHECK (status IN ('open', 'in_progress', 'completed', 'cancelled'))",
        );
        DB::statement(
            "ALTER TABLE actions
             ADD CONSTRAINT actions_lifecycle_check
             CHECK (
                 (status IN ('open', 'in_progress', 'cancelled') AND completed_at IS NULL)
                 OR (status = 'completed' AND completed_at IS NOT NULL)
             )",
        );

        DB::statement('ALTER TABLE signature_requests DROP CONSTRAINT IF EXISTS signature_requests_status_check');
        DB::statement('ALTER TABLE signature_requests DROP CONSTRAINT IF EXISTS signature_requests_lifecycle_check');
        DB::statement("ALTER TABLE signature_requests ALTER COLUMN status SET DEFAULT 'pending'");

        Schema::table('signature_requests', function (Blueprint $table): void {
            $table->dropForeign('signature_requests_canceller_membership_fk');
            $table->dropForeign('signature_requests_decliner_membership_fk');
            $table->dropColumn([
                'sent_at',
                'cancelled_by_membership_id',
                'declined_at',
                'declined_by_membership_id',
                'expired_at',
            ]);
        });

        DB::statement(
            "ALTER TABLE signature_requests
             ADD CONSTRAINT signature_requests_status_check
             CHECK (status IN ('pending', 'completed', 'cancelled'))",
        );
        DB::statement(
            "ALTER TABLE signature_requests
             ADD CONSTRAINT signature_requests_lifecycle_check
             CHECK (
                 (status = 'pending' AND completed_at IS NULL AND cancelled_at IS NULL)
                 OR
                 (status = 'completed' AND completed_at IS NOT NULL AND cancelled_at IS NULL)
                 OR
                 (status = 'cancelled' AND completed_at IS NULL AND cancelled_at IS NOT NULL)
             )",
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER signature_requests_validate
BEFORE INSERT ON signature_requests
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_signature_request();

CREATE TRIGGER signature_requests_protect_history
BEFORE UPDATE OR DELETE ON signature_requests
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_signature_request();

CREATE TRIGGER signature_participants_validate
BEFORE INSERT ON signature_participants
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_signature_participant();

CREATE TRIGGER signatures_validate
BEFORE INSERT ON signatures
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_signature();

CREATE TRIGGER actions_protect_history
BEFORE UPDATE OR DELETE ON actions
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_action();
SQL);
    }
};
