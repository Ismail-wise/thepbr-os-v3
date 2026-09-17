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
        Schema::create('formal_record_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_family_id');
            $table->unsignedBigInteger('version_number');
            $table->uuid('predecessor_version_id')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->text('change_summary');
            $table->uuid('created_by_user_id');
            $table->uuid('last_changed_by_user_id');
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_until')
                ->nullable()
                ->comment(
                    'Planned end known before freeze; actual supersession boundary is stored separately.',
                );
            $table->timestampTz('review_due_at')->nullable();
            $table->char('content_hash', 64);
            $table->timestampTz('frozen_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['id', 'business_id'],
                'record_versions_id_business_unique',
            );

            $table->unique(
                ['formal_record_family_id', 'version_number'],
                'record_versions_family_version_unique',
            );

            $table->foreign(
                ['formal_record_family_id', 'business_id'],
                'record_versions_family_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('formal_record_families')
                ->restrictOnDelete();

            $table->foreign(
                ['predecessor_version_id', 'business_id'],
                'record_versions_predecessor_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign('last_changed_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE formal_record_versions
             ADD CONSTRAINT record_versions_version_positive
             CHECK (version_number > 0)',
        );

        DB::statement(
            'ALTER TABLE formal_record_versions
             ADD CONSTRAINT record_versions_revision_positive
             CHECK (revision > 0)',
        );

        DB::statement(
            "ALTER TABLE formal_record_versions
             ADD CONSTRAINT record_versions_content_hash_sha256
             CHECK (content_hash ~ '^[0-9a-f]{64}$')",
        );

        DB::statement(
            'ALTER TABLE formal_record_versions
             ADD CONSTRAINT record_versions_effective_range_valid
             CHECK (
                 effective_until IS NULL
                 OR (
                     effective_from IS NOT NULL
                     AND effective_until > effective_from
                 )
             )',
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_formal_record_version_lineage()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    predecessor_family uuid;
    predecessor_business uuid;
    predecessor_number bigint;
BEGIN
    IF NEW.predecessor_version_id IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT
        formal_record_family_id,
        business_id,
        version_number
    INTO
        predecessor_family,
        predecessor_business,
        predecessor_number
    FROM formal_record_versions
    WHERE id = NEW.predecessor_version_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'formal record predecessor does not exist';
    END IF;

    IF predecessor_business <> NEW.business_id THEN
        RAISE EXCEPTION 'formal record predecessor must remain in the same Business';
    END IF;

    IF predecessor_family <> NEW.formal_record_family_id THEN
        RAISE EXCEPTION 'formal record predecessor must remain in the same family';
    END IF;

    IF NEW.version_number <= predecessor_number THEN
        RAISE EXCEPTION 'formal record version number must increase beyond its predecessor';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER formal_record_versions_validate_lineage
BEFORE INSERT OR UPDATE OF
    predecessor_version_id,
    business_id,
    formal_record_family_id,
    version_number
ON formal_record_versions
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_formal_record_version_lineage();

CREATE OR REPLACE FUNCTION pbr_protect_frozen_formal_record_version()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.frozen_at IS NOT NULL THEN
            RAISE EXCEPTION 'frozen formal record versions are immutable';
        END IF;

        RETURN OLD;
    END IF;

    IF OLD.frozen_at IS NOT NULL THEN
        RAISE EXCEPTION 'frozen formal record versions are immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER formal_record_versions_protect_frozen
BEFORE UPDATE OR DELETE
ON formal_record_versions
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_frozen_formal_record_version();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('formal_record_versions');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_formal_record_version_lineage();',
        );
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_frozen_formal_record_version();',
        );
    }
};
