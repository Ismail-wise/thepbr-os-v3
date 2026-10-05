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
            'deep_feasibility_assessment_runs',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->string('contract_version', 80);
                $table->string('engine_version', 80);
                $table->jsonb('source_provenance');
                $table->jsonb('input_snapshot');
                $table->char('input_hash', 64);
                $table->jsonb('result_snapshot');
                $table->char('result_hash', 64);
                $table->string('confidence_level', 24);
                $table->string('evidence_quality', 24);
                $table->decimal('overall_score', 6, 2)->nullable();
                $table->string('recommendation', 32)->nullable();
                $table->uuid('created_by_membership_id');
                $table->timestampTz('created_at')->useCurrent();

                $table->foreign('business_id')
                    ->references('id')
                    ->on('businesses')
                    ->restrictOnDelete();

                $table->foreign(
                    ['created_by_membership_id', 'business_id'],
                    'deep_feasibility_runs_creator_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();

                $table->unique(
                    ['id', 'business_id'],
                    'deep_feasibility_runs_id_business_unique',
                );

                $table->index(
                    ['business_id', 'created_at'],
                    'deep_feasibility_runs_business_created_index',
                );
            },
        );

        DB::statement(
            "ALTER TABLE deep_feasibility_assessment_runs
             ADD CONSTRAINT deep_feasibility_runs_contract_nonblank
             CHECK (btrim(contract_version) <> '')",
        );

        DB::statement(
            "ALTER TABLE deep_feasibility_assessment_runs
             ADD CONSTRAINT deep_feasibility_runs_engine_nonblank
             CHECK (btrim(engine_version) <> '')",
        );

        DB::statement(
            "ALTER TABLE deep_feasibility_assessment_runs
             ADD CONSTRAINT deep_feasibility_runs_input_hash_sha256
             CHECK (input_hash ~ '^[0-9a-f]{64}$')",
        );

        DB::statement(
            "ALTER TABLE deep_feasibility_assessment_runs
             ADD CONSTRAINT deep_feasibility_runs_result_hash_sha256
             CHECK (result_hash ~ '^[0-9a-f]{64}$')",
        );

        DB::statement(
            "ALTER TABLE deep_feasibility_assessment_runs
             ADD CONSTRAINT deep_feasibility_runs_confidence_check
             CHECK (confidence_level IN ('low','medium','high'))",
        );

        DB::statement(
            "ALTER TABLE deep_feasibility_assessment_runs
             ADD CONSTRAINT deep_feasibility_runs_evidence_quality_check
             CHECK (evidence_quality IN ('limited','traceable','documented'))",
        );

        DB::statement(
            'ALTER TABLE deep_feasibility_assessment_runs
             ADD CONSTRAINT deep_feasibility_runs_score_range_check
             CHECK (
                 overall_score IS NULL
                 OR (overall_score >= 0 AND overall_score <= 100)
             )',
        );

        DB::statement(
            "ALTER TABLE deep_feasibility_assessment_runs
             ADD CONSTRAINT deep_feasibility_runs_recommendation_check
             CHECK (
                 recommendation IS NULL
                 OR recommendation IN (
                     'GO',
                     'CONDITIONAL GO',
                     'HOLD',
                     'NO-GO'
                 )
             )",
        );

        foreach ([
            'source_provenance',
            'input_snapshot',
            'result_snapshot',
        ] as $column) {
            DB::statement(
                "ALTER TABLE deep_feasibility_assessment_runs
                 ADD CONSTRAINT deep_feasibility_runs_{$column}_object
                 CHECK (jsonb_typeof({$column}) = 'object')",
            );
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.thepbr_protect_deep_feasibility_assessment_run()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'deep feasibility assessment runs are immutable';
END;
$$;

CREATE TRIGGER deep_feasibility_assessment_runs_prevent_update_delete
BEFORE UPDATE OR DELETE ON deep_feasibility_assessment_runs
FOR EACH ROW
EXECUTE FUNCTION public.thepbr_protect_deep_feasibility_assessment_run();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('deep_feasibility_assessment_runs');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS public.thepbr_protect_deep_feasibility_assessment_run()',
        );
    }
};
