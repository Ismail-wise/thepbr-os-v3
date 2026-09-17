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
            'proposal_version_records',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('proposal_version_id');
                $table->uuid('formal_record_version_id');
                $table->char('captured_content_hash', 64);
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    [
                        'proposal_version_id',
                        'formal_record_version_id',
                    ],
                    'proposal_version_records_binding_unique',
                );

                $table->foreign(
                    ['proposal_version_id', 'business_id'],
                    'proposal_version_records_proposal_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('proposal_versions')
                    ->restrictOnDelete();

                $table->foreign(
                    ['formal_record_version_id', 'business_id'],
                    'proposal_version_records_record_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('formal_record_versions')
                    ->restrictOnDelete();
            },
        );

        DB::statement(
            "ALTER TABLE proposal_version_records
             ADD CONSTRAINT proposal_version_records_hash_sha256
             CHECK (captured_content_hash ~ '^[0-9a-f]{64}$')",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_proposal_version_record()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    record_content_hash text;
BEGIN
    SELECT content_hash
    INTO record_content_hash
    FROM formal_record_versions
    WHERE id = NEW.formal_record_version_id
      AND business_id = NEW.business_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'formal record version does not exist in this Business';
    END IF;

    IF NEW.captured_content_hash IS DISTINCT FROM record_content_hash THEN
        RAISE EXCEPTION 'captured record content identity must match the formal record version at freeze';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER proposal_version_records_validate
BEFORE INSERT
ON proposal_version_records
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_proposal_version_record();

CREATE OR REPLACE FUNCTION pbr_protect_proposal_version_record()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'frozen proposal-version record bindings are immutable';
END;
$$;

CREATE TRIGGER proposal_version_records_immutable
BEFORE UPDATE OR DELETE
ON proposal_version_records
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_proposal_version_record();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_version_records');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_proposal_version_record();',
        );
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_proposal_version_record();',
        );
    }
};
