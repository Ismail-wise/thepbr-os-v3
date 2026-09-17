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
        Schema::create('record_version_supersessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_family_id');
            $table->uuid('superseded_version_id');
            $table->uuid('superseding_version_id');
            $table->timestampTz('superseded_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['superseded_version_id'],
                'record_supersessions_old_version_unique',
            );

            $table->foreign(
                ['formal_record_family_id', 'business_id'],
                'record_supersessions_family_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('formal_record_families')
                ->restrictOnDelete();

            $table->foreign(
                ['superseded_version_id', 'business_id'],
                'record_supersessions_old_version_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['superseding_version_id', 'business_id'],
                'record_supersessions_new_version_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_validate_record_supersession()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    old_family uuid;
    old_business uuid;
    old_effective_from timestamptz;
    new_family uuid;
    new_business uuid;
    new_effective_from timestamptz;
    old_state text;
    new_state text;
BEGIN
    IF NEW.superseded_version_id = NEW.superseding_version_id THEN
        RAISE EXCEPTION 'a version cannot supersede itself';
    END IF;

    SELECT formal_record_family_id, business_id, effective_from
    INTO old_family, old_business, old_effective_from
    FROM formal_record_versions
    WHERE id = NEW.superseded_version_id;

    SELECT formal_record_family_id, business_id, effective_from
    INTO new_family, new_business, new_effective_from
    FROM formal_record_versions
    WHERE id = NEW.superseding_version_id;

    IF old_business <> NEW.business_id OR new_business <> NEW.business_id THEN
        RAISE EXCEPTION 'supersession must remain in the same Business';
    END IF;

    IF (
        old_family <> NEW.formal_record_family_id
        OR new_family <> NEW.formal_record_family_id
    ) THEN
        RAISE EXCEPTION 'supersession must remain in the same record family';
    END IF;

    IF new_effective_from IS NULL THEN
        RAISE EXCEPTION 'superseding version requires effective_from';
    END IF;

    IF old_effective_from IS NULL OR new_effective_from <= old_effective_from THEN
        RAISE EXCEPTION 'superseding effective boundary must move forward';
    END IF;

    IF NEW.superseded_at IS DISTINCT FROM new_effective_from THEN
        RAISE EXCEPTION 'supersession boundary must equal the new effective_from';
    END IF;

    SELECT to_state INTO old_state
    FROM record_version_state_transitions
    WHERE formal_record_version_id = NEW.superseded_version_id
    ORDER BY sequence DESC
    LIMIT 1;

    SELECT to_state INTO new_state
    FROM record_version_state_transitions
    WHERE formal_record_version_id = NEW.superseding_version_id
    ORDER BY sequence DESC
    LIMIT 1;

    IF old_state IS DISTINCT FROM 'effective' THEN
        RAISE EXCEPTION 'superseded head must still be Effective at supersession creation';
    END IF;

    IF new_state IS DISTINCT FROM 'effective' THEN
        RAISE EXCEPTION 'superseding version must already be mechanically Effective';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER record_supersessions_validate
BEFORE INSERT
ON record_version_supersessions
FOR EACH ROW
EXECUTE FUNCTION pbr_validate_record_supersession();

CREATE OR REPLACE FUNCTION pbr_protect_record_supersession()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'formal record supersession history is append-only';
END;
$$;

CREATE TRIGGER record_supersessions_append_only
BEFORE UPDATE OR DELETE
ON record_version_supersessions
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_record_supersession();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('record_version_supersessions');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_validate_record_supersession();',
        );
        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_record_supersession();',
        );
    }
};
