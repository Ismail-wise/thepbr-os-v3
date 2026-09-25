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
            'business_access_invitations',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->string('invited_email', 254);
                $table->char('token_fingerprint', 64);
                $table->char('token_last4', 4);
                $table->uuid('permission_profile_id');
                $table->uuid('invited_by_membership_id');
                $table->string('status', 24)->default('pending');
                $table->timestampTz('expires_at');
                $table->uuid('redeemed_by_user_id')->nullable();
                $table->uuid('redeemed_membership_id')->nullable();
                $table->timestampTz('redeemed_at')->nullable();
                $table->timestampTz('revoked_at')->nullable();
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['id', 'business_id'],
                    'business_access_invitations_id_business_unique',
                );

                $table->unique(
                    'token_fingerprint',
                    'business_access_invitations_token_unique',
                );

                $table->index(
                    ['business_id', 'invited_email', 'status'],
                    'business_access_invitations_lookup_index',
                );

                $table->foreign('business_id')
                    ->references('id')
                    ->on('businesses')
                    ->restrictOnDelete();

                $table->foreign(
                    ['permission_profile_id', 'business_id'],
                    'business_access_invitations_profile_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('permission_profiles')
                    ->restrictOnDelete();

                $table->foreign(
                    ['invited_by_membership_id', 'business_id'],
                    'business_access_invitations_inviter_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();

                $table->foreign('redeemed_by_user_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();

                $table->foreign(
                    ['redeemed_membership_id', 'business_id'],
                    'business_access_invitations_redeemed_membership_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        DB::statement(
            'ALTER TABLE business_access_invitations
             ADD CONSTRAINT business_access_invitations_email_canonical_check
             CHECK (
                 invited_email = lower(btrim(invited_email))
                 AND invited_email <> \'\'
             )',
        );

        DB::statement(
            "ALTER TABLE business_access_invitations
             ADD CONSTRAINT business_access_invitations_fingerprint_check
             CHECK (token_fingerprint ~ '^[0-9a-f]{64}$')",
        );

        DB::statement(
            "ALTER TABLE business_access_invitations
             ADD CONSTRAINT business_access_invitations_last4_check
             CHECK (token_last4 ~ '^[A-Z0-9]{4}$')",
        );

        DB::statement(
            "ALTER TABLE business_access_invitations
             ADD CONSTRAINT business_access_invitations_status_check
             CHECK (status IN ('pending', 'redeemed', 'revoked'))",
        );

        DB::statement(
            'ALTER TABLE business_access_invitations
             ADD CONSTRAINT business_access_invitations_expiry_check
             CHECK (expires_at > created_at)',
        );

        DB::statement(
            "ALTER TABLE business_access_invitations
             ADD CONSTRAINT business_access_invitations_lifecycle_check
             CHECK (
                 (
                     status = 'pending'
                     AND redeemed_by_user_id IS NULL
                     AND redeemed_membership_id IS NULL
                     AND redeemed_at IS NULL
                     AND revoked_at IS NULL
                 )
                 OR
                 (
                     status = 'redeemed'
                     AND redeemed_by_user_id IS NOT NULL
                     AND redeemed_membership_id IS NOT NULL
                     AND redeemed_at IS NOT NULL
                     AND revoked_at IS NULL
                 )
                 OR
                 (
                     status = 'revoked'
                     AND redeemed_by_user_id IS NULL
                     AND redeemed_membership_id IS NULL
                     AND redeemed_at IS NULL
                     AND revoked_at IS NOT NULL
                 )
             )",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_business_access_invitation_redemption()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    membership_user uuid;
BEGIN
    IF NEW.status <> 'redeemed' THEN
        RETURN NEW;
    END IF;

    SELECT user_id
    INTO membership_user
    FROM memberships
    WHERE id = NEW.redeemed_membership_id
      AND business_id = NEW.business_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'redeemed Membership does not exist in this Business';
    END IF;

    IF membership_user <> NEW.redeemed_by_user_id THEN
        RAISE EXCEPTION 'redeemed Membership must belong to redeemed User';
    END IF;

    IF NEW.redeemed_at >= NEW.expires_at THEN
        RAISE EXCEPTION 'expired business access invitation cannot be redeemed';
    END IF;

    IF NEW.redeemed_at < NEW.created_at THEN
        RAISE EXCEPTION 'business access invitation cannot be redeemed before creation';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER business_access_invitations_validate_redemption
BEFORE INSERT OR UPDATE
ON business_access_invitations
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_business_access_invitation_redemption();

CREATE OR REPLACE FUNCTION pbr_protect_business_access_invitation_history()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'business access invitation history cannot be deleted';
    END IF;

    IF OLD.status IN ('redeemed', 'revoked') THEN
        RAISE EXCEPTION 'completed business access invitation history is immutable';
    END IF;

    IF
        NEW.business_id IS DISTINCT FROM OLD.business_id
        OR NEW.invited_email IS DISTINCT FROM OLD.invited_email
        OR NEW.token_fingerprint IS DISTINCT FROM OLD.token_fingerprint
        OR NEW.token_last4 IS DISTINCT FROM OLD.token_last4
        OR NEW.permission_profile_id IS DISTINCT FROM OLD.permission_profile_id
        OR NEW.invited_by_membership_id IS DISTINCT FROM OLD.invited_by_membership_id
        OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'business access invitation identity cannot be rewritten';
    END IF;

    IF NEW.status NOT IN ('pending', 'redeemed', 'revoked') THEN
        RAISE EXCEPTION 'invalid business access invitation lifecycle transition';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER business_access_invitations_protect_history
BEFORE UPDATE OR DELETE
ON business_access_invitations
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_business_access_invitation_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_access_invitations');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_business_access_invitation_redemption();',
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_business_access_invitation_history();',
        );
    }
};
