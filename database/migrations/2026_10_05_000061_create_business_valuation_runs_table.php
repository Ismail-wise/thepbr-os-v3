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
        Schema::table('valuations', function (Blueprint $table): void {
            $table->unique(
                ['id', 'business_id'],
                'valuations_id_business_unique',
            );
        });

        Schema::create('business_valuation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('valuation_id');
            $table->date('as_of_date');
            $table->string('formula_version', 80);
            $table->string('review_state', 24)->default('draft');
            $table->decimal('range_low', 20, 2);
            $table->decimal('base_value', 20, 2);
            $table->decimal('range_high', 20, 2);
            $table->string('confidence_level', 24);
            $table->jsonb('historical_inputs');
            $table->jsonb('assumptions');
            $table->jsonb('source_provenance');
            $table->jsonb('method_results');
            $table->jsonb('warnings');
            $table->jsonb('semantics');
            $table->uuid('created_by_membership_id');
            $table->timestampTz('created_at')->useCurrent();

            $table
                ->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table->foreign(
                ['valuation_id', 'business_id'],
                'business_valuation_runs_baseline_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('valuations')
                ->restrictOnDelete();

            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'business_valuation_runs_creator_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->unique(
                ['id', 'business_id'],
                'business_valuation_runs_id_business_unique',
            );

            $table->unique(
                'valuation_id',
                'business_valuation_runs_valuation_unique',
            );

            $table->index(
                ['business_id', 'as_of_date', 'created_at'],
                'business_valuation_runs_business_date_index',
            );
        });

        DB::statement(
            "ALTER TABLE business_valuation_runs
             ADD CONSTRAINT business_valuation_runs_review_state_check
             CHECK (review_state IN ('draft','reviewed'))",
        );

        DB::statement(
            "ALTER TABLE business_valuation_runs
             ADD CONSTRAINT business_valuation_runs_confidence_check
             CHECK (confidence_level IN ('low','medium','high'))",
        );

        DB::statement(
            "ALTER TABLE business_valuation_runs
             ADD CONSTRAINT business_valuation_runs_formula_version_nonblank
             CHECK (btrim(formula_version) <> '')",
        );

        DB::statement(
            'ALTER TABLE business_valuation_runs
             ADD CONSTRAINT business_valuation_runs_range_check
             CHECK (
                 range_low >= 0
                 AND base_value >= range_low
                 AND range_high >= base_value
             )',
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.thepbr_protect_business_valuation_run()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'business valuation runs are immutable';
END;
$$;

CREATE TRIGGER business_valuation_runs_prevent_update_delete
BEFORE UPDATE OR DELETE ON business_valuation_runs
FOR EACH ROW
EXECUTE FUNCTION public.thepbr_protect_business_valuation_run();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_valuation_runs');

        Schema::table('valuations', function (Blueprint $table): void {
            $table->dropUnique('valuations_id_business_unique');
        });

        DB::unprepared(
            'DROP FUNCTION IF EXISTS public.thepbr_protect_business_valuation_run()',
        );
    }
};
