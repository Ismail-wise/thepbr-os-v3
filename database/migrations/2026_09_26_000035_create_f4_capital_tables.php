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
        Schema::create('capital_scenarios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('scenario_kind', 24);
            $table->string('name', 160);
            $table->decimal('pre_opening_costs', 20, 2)->default(0);
            $table->decimal('initial_assets_inventory', 20, 2)->default(0);
            $table->decimal('working_capital', 20, 2)->default(0);
            $table->decimal('contingency_reserve', 20, 2)->default(0);
            $table->decimal('available_funding', 20, 2)->default(0);
            $table->decimal('total_requirement', 20, 2);
            $table->decimal('funding_gap', 20, 2);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();

            $table->unique(
                ['business_id', 'scenario_kind'],
                'capital_scenarios_business_kind_unique',
            );

            $table->unique(
                ['id', 'business_id'],
                'capital_scenarios_id_business_unique',
            );

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();
        });

        Schema::create('capital_plan_promotions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('capital_scenario_id');
            $table->unsignedBigInteger('scenario_revision');
            $table->uuid('formal_record_family_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('proposal_id');
            $table->uuid('proposal_version_id');
            $table->char('content_hash', 64);
            $table->uuid('created_by_membership_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['capital_scenario_id', 'scenario_revision'],
                'capital_promotions_scenario_revision_unique',
            );

            $table->unique(
                ['formal_record_version_id'],
                'capital_promotions_record_version_unique',
            );

            $table->unique(
                ['proposal_version_id'],
                'capital_promotions_proposal_version_unique',
            );

            $table->foreign(
                ['capital_scenario_id', 'business_id'],
                'capital_promotions_scenario_business_fk',
            )->references(['id', 'business_id'])->on('capital_scenarios')->restrictOnDelete();

            $table->foreign(
                ['formal_record_family_id', 'business_id'],
                'capital_promotions_family_business_fk',
            )->references(['id', 'business_id'])->on('formal_record_families')->restrictOnDelete();

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'capital_promotions_version_business_fk',
            )->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();

            $table->foreign(
                ['proposal_id', 'business_id'],
                'capital_promotions_proposal_business_fk',
            )->references(['id', 'business_id'])->on('proposals')->restrictOnDelete();

            $table->foreign(
                ['proposal_version_id', 'business_id'],
                'capital_promotions_proposal_version_business_fk',
            )->references(['id', 'business_id'])->on('proposal_versions')->restrictOnDelete();

            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'capital_promotions_membership_business_fk',
            )->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE capital_scenarios
             ADD CONSTRAINT capital_scenarios_kind_check
             CHECK (scenario_kind IN ('lean','base','growth'))",
        );

        DB::statement(
            'ALTER TABLE capital_scenarios
             ADD CONSTRAINT capital_scenarios_non_negative_check
             CHECK (
                pre_opening_costs >= 0
                AND initial_assets_inventory >= 0
                AND working_capital >= 0
                AND contingency_reserve >= 0
                AND available_funding >= 0
                AND total_requirement >= 0
                AND funding_gap >= 0
             )',
        );

        DB::statement(
            'ALTER TABLE capital_scenarios
             ADD CONSTRAINT capital_scenarios_total_invariant_check
             CHECK (
                total_requirement =
                    pre_opening_costs
                    + initial_assets_inventory
                    + working_capital
                    + contingency_reserve
             )',
        );

        DB::statement(
            'ALTER TABLE capital_scenarios
             ADD CONSTRAINT capital_scenarios_gap_invariant_check
             CHECK (
                funding_gap = GREATEST(total_requirement - available_funding, 0)
             )',
        );

        DB::statement(
            'ALTER TABLE capital_scenarios
             ADD CONSTRAINT capital_scenarios_revision_positive
             CHECK (revision > 0)',
        );

        DB::statement(
            "ALTER TABLE capital_plan_promotions
             ADD CONSTRAINT capital_promotions_hash_check
             CHECK (content_hash ~ '^[0-9a-f]{64}$')",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_protect_capital_plan_promotion()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'capital plan promotion history is immutable';
END;
$$;

CREATE TRIGGER capital_plan_promotions_immutable
BEFORE UPDATE OR DELETE
ON capital_plan_promotions
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_capital_plan_promotion();
SQL);
    }

    public function down(): void
    {
        /*
         * capital_plan_promotions owns a trigger that depends on
         * pbr_protect_capital_plan_promotion(). Drop the table first so the
         * trigger is removed before its shared function, without CASCADE.
         */
        Schema::dropIfExists('capital_plan_promotions');
        Schema::dropIfExists('capital_scenarios');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_capital_plan_promotion();',
        );
    }
};
