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
        Schema::create('pbr_access_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('token_fingerprint', 64)->unique();
            $table->char('token_last4', 4);
            $table->string('status', 24)->default('pending');
            $table->string('bound_email', 254)->nullable();
            $table->string('client_reference', 160)->nullable();
            $table->string('batch_reference', 160)->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('created_by_label', 160);
            $table->text('creation_reason');
            $table->timestampTz('created_at')->useCurrent();
            $table->uuid('redeemed_by_user_id')->nullable();
            $table->timestampTz('redeemed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_by_label', 160)->nullable();
            $table->text('revocation_reason')->nullable();

            $table->foreign('redeemed_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index(
                ['status', 'expires_at'],
                'pbr_access_codes_status_expiry_index',
            );

            $table->index(
                ['bound_email', 'status'],
                'pbr_access_codes_email_status_index',
            );
        });

        DB::statement(
            "ALTER TABLE pbr_access_codes
             ADD CONSTRAINT pbr_access_codes_fingerprint_check
             CHECK (token_fingerprint ~ '^[0-9a-f]{64}$')",
        );

        DB::statement(
            "ALTER TABLE pbr_access_codes
             ADD CONSTRAINT pbr_access_codes_last4_check
             CHECK (token_last4 ~ '^[A-Z0-9]{4}$')",
        );

        DB::statement(
            "ALTER TABLE pbr_access_codes
             ADD CONSTRAINT pbr_access_codes_status_check
             CHECK (status IN ('pending', 'redeemed', 'revoked'))",
        );

        DB::statement(
            "ALTER TABLE pbr_access_codes
             ADD CONSTRAINT pbr_access_codes_bound_email_check
             CHECK (
                 bound_email IS NULL
                 OR (
                     bound_email = lower(btrim(bound_email))
                     AND bound_email <> ''
                 )
             )",
        );

        DB::statement(
            'ALTER TABLE pbr_access_codes
             ADD CONSTRAINT pbr_access_codes_expiry_check
             CHECK (expires_at IS NULL OR expires_at > created_at)',
        );

        DB::statement(
            "ALTER TABLE pbr_access_codes
             ADD CONSTRAINT pbr_access_codes_creation_evidence_check
             CHECK (
                 btrim(created_by_label) <> ''
                 AND btrim(creation_reason) <> ''
             )",
        );

        DB::statement(
            "ALTER TABLE pbr_access_codes
             ADD CONSTRAINT pbr_access_codes_lifecycle_check
             CHECK (
                 (
                     status = 'pending'
                     AND redeemed_by_user_id IS NULL
                     AND redeemed_at IS NULL
                     AND revoked_at IS NULL
                     AND revoked_by_label IS NULL
                     AND revocation_reason IS NULL
                 )
                 OR
                 (
                     status = 'redeemed'
                     AND redeemed_by_user_id IS NOT NULL
                     AND redeemed_at IS NOT NULL
                     AND revoked_at IS NULL
                     AND revoked_by_label IS NULL
                     AND revocation_reason IS NULL
                     AND (
                         expires_at IS NULL
                         OR redeemed_at < expires_at
                     )
                 )
                 OR
                 (
                     status = 'revoked'
                     AND redeemed_by_user_id IS NULL
                     AND redeemed_at IS NULL
                     AND revoked_at IS NOT NULL
                     AND btrim(revoked_by_label) <> ''
                     AND btrim(revocation_reason) <> ''
                 )
             )",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_protect_access_code_history()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'PBR access code history cannot be deleted';
    END IF;

    IF OLD.status IN ('redeemed', 'revoked') THEN
        RAISE EXCEPTION 'completed PBR access code history is immutable';
    END IF;

    IF
        NEW.token_fingerprint IS DISTINCT FROM OLD.token_fingerprint
        OR NEW.token_last4 IS DISTINCT FROM OLD.token_last4
        OR NEW.bound_email IS DISTINCT FROM OLD.bound_email
        OR NEW.client_reference IS DISTINCT FROM OLD.client_reference
        OR NEW.batch_reference IS DISTINCT FROM OLD.batch_reference
        OR NEW.notes IS DISTINCT FROM OLD.notes
        OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
        OR NEW.created_by_label IS DISTINCT FROM OLD.created_by_label
        OR NEW.creation_reason IS DISTINCT FROM OLD.creation_reason
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'PBR access code identity cannot be rewritten';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER pbr_access_codes_protect_history
BEFORE UPDATE OR DELETE
ON pbr_access_codes
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_access_code_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pbr_access_codes');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_access_code_history();',
        );
    }
};
