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
        Schema::create('proposal_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('proposal_id');
            $table->unsignedBigInteger('version_number');
            $table->unsignedBigInteger('proposal_revision');
            $table->char('proposal_content_hash', 64);
            $table->char('snapshot_hash', 64);
            $table->uuid('frozen_by_user_id');
            $table->timestampTz('frozen_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['id', 'business_id'],
                'proposal_versions_id_business_unique',
            );

            $table->unique(
                ['proposal_id', 'version_number'],
                'proposal_versions_proposal_version_unique',
            );

            $table->foreign(
                ['proposal_id', 'business_id'],
                'proposal_versions_proposal_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('proposals')
                ->restrictOnDelete();

            $table->foreign('frozen_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE proposal_versions
             ADD CONSTRAINT proposal_versions_number_positive
             CHECK (version_number > 0)',
        );

        DB::statement(
            'ALTER TABLE proposal_versions
             ADD CONSTRAINT proposal_versions_revision_positive
             CHECK (proposal_revision > 0)',
        );

        DB::statement(
            "ALTER TABLE proposal_versions
             ADD CONSTRAINT proposal_versions_content_hash_sha256
             CHECK (proposal_content_hash ~ '^[0-9a-f]{64}$')",
        );

        DB::statement(
            "ALTER TABLE proposal_versions
             ADD CONSTRAINT proposal_versions_snapshot_hash_sha256
             CHECK (snapshot_hash ~ '^[0-9a-f]{64}$')",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_proposal_version_snapshot()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    current_revision bigint;
    current_content_hash text;
BEGIN
    SELECT revision, content_hash
    INTO current_revision, current_content_hash
    FROM proposals
    WHERE id = NEW.proposal_id
      AND business_id = NEW.business_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'proposal does not exist in this Business';
    END IF;

    IF NEW.proposal_revision <> current_revision THEN
        RAISE EXCEPTION 'proposal snapshot revision must match the current locked proposal revision';
    END IF;

    IF NEW.proposal_content_hash IS DISTINCT FROM current_content_hash THEN
        RAISE EXCEPTION 'proposal snapshot content identity must match the proposal at freeze';
    END IF;

    IF NEW.frozen_at IS NULL THEN
        RAISE EXCEPTION 'proposal version must be frozen at creation';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER proposal_versions_validate_snapshot
BEFORE INSERT
ON proposal_versions
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_proposal_version_snapshot();

CREATE OR REPLACE FUNCTION pbr_protect_proposal_version()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'frozen proposal versions are immutable';
END;
$$;

CREATE TRIGGER proposal_versions_immutable
BEFORE UPDATE OR DELETE
ON proposal_versions
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_proposal_version();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_versions');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_proposal_version_snapshot();',
        );
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_proposal_version();',
        );
    }
};
