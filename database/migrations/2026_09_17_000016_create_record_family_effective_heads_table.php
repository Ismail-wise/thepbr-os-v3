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
        Schema::create('record_family_effective_heads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_family_id');
            $table->uuid('formal_record_version_id');
            $table->timestampTz('activated_at');
            $table->timestampsTz();

            $table->unique(
                ['formal_record_family_id'],
                'record_effective_heads_family_unique',
            );

            $table->unique(
                ['formal_record_version_id'],
                'record_effective_heads_version_unique',
            );

            $table->foreign(
                ['formal_record_family_id', 'business_id'],
                'record_effective_heads_family_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('formal_record_families')
                ->restrictOnDelete();

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'record_effective_heads_version_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_effective_head()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    version_family uuid;
    version_business uuid;
    version_frozen timestamptz;
    version_effective_from timestamptz;
    version_effective_until timestamptz;
    latest_state text;
BEGIN
    SELECT
        formal_record_family_id,
        business_id,
        frozen_at,
        effective_from,
        effective_until
    INTO
        version_family,
        version_business,
        version_frozen,
        version_effective_from,
        version_effective_until
    FROM formal_record_versions
    WHERE id = NEW.formal_record_version_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'effective head version does not exist';
    END IF;

    IF version_business <> NEW.business_id THEN
        RAISE EXCEPTION 'effective head must remain in the same Business';
    END IF;

    IF version_family <> NEW.formal_record_family_id THEN
        RAISE EXCEPTION 'effective head must remain in the same record family';
    END IF;

    IF version_frozen IS NULL THEN
        RAISE EXCEPTION 'effective head must reference a frozen version';
    END IF;

    IF version_effective_from IS NULL OR version_effective_from > CURRENT_TIMESTAMP THEN
        RAISE EXCEPTION 'future-effective version cannot become current early';
    END IF;

    IF (
        version_effective_until IS NOT NULL
        AND version_effective_until <= CURRENT_TIMESTAMP
    ) THEN
        RAISE EXCEPTION 'expired planned effective period cannot be current';
    END IF;

    SELECT to_state
    INTO latest_state
    FROM record_version_state_transitions
    WHERE formal_record_version_id = NEW.formal_record_version_id
    ORDER BY sequence DESC
    LIMIT 1;

    IF latest_state IS DISTINCT FROM 'effective' THEN
        RAISE EXCEPTION 'effective head must reference an Effective version';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER record_effective_heads_validate
BEFORE INSERT OR UPDATE
ON record_family_effective_heads
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_effective_head();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('record_family_effective_heads');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_effective_head();',
        );
    }
};
