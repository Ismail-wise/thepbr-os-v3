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
        Schema::create('business_ideas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id')->unique();
            $table->text('summary')->nullable();
            $table->text('problem')->nullable();
            $table->text('target_customer')->nullable();
            $table->text('proposed_solution')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('business_model_canvases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id')->unique();
            $table->text('customer_segments')->nullable();
            $table->text('value_propositions')->nullable();
            $table->text('channels')->nullable();
            $table->text('customer_relationships')->nullable();
            $table->text('revenue_streams')->nullable();
            $table->text('key_resources')->nullable();
            $table->text('key_activities')->nullable();
            $table->text('key_partnerships')->nullable();
            $table->text('cost_structure')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('formation_assumptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('category', 80);
            $table->text('statement');
            $table->string('status', 24)->default('planned');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();
            $table->unique(['id', 'business_id'], 'formation_assumptions_id_business_unique');
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('validation_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('assumption_id')->nullable();
            $table->string('method', 160);
            $table->text('result_summary')->nullable();
            $table->string('status', 24)->default('planned');
            $table->date('occurred_on')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();
            $table->unique(['id', 'business_id'], 'validation_activities_id_business_unique');
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign(
                ['assumption_id', 'business_id'],
                'validation_activities_assumption_business_fk',
            )->references(['id', 'business_id'])->on('formation_assumptions')->restrictOnDelete();
        });

        Schema::create('validation_evidence_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('validation_activity_id');
            $table->uuid('evidence_id');
            $table->uuid('created_by_membership_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(
                ['validation_activity_id', 'evidence_id'],
                'validation_evidence_activity_evidence_unique',
            );
            $table->foreign(
                ['validation_activity_id', 'business_id'],
                'validation_evidence_activity_business_fk',
            )->references(['id', 'business_id'])->on('validation_activities')->restrictOnDelete();
            $table->foreign(
                ['evidence_id', 'business_id'],
                'validation_evidence_evidence_business_fk',
            )->references(['id', 'business_id'])->on('evidence')->restrictOnDelete();
            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'validation_evidence_membership_business_fk',
            )->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
        });

        Schema::create('feasibility_scenarios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('name', 160);
            $table->decimal('projected_monthly_revenue', 20, 2)->default(0);
            $table->decimal('projected_monthly_cost', 20, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('partnership_fit_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id')->unique();
            $table->text('goals_alignment')->nullable();
            $table->text('role_expectations')->nullable();
            $table->text('decision_process')->nullable();
            $table->text('risk_tolerance')->nullable();
            $table->text('unresolved_questions')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('formation_direction_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('direction', 24);
            $table->text('rationale');
            $table->uuid('decided_by_membership_id');
            $table->timestampTz('decided_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign(
                ['decided_by_membership_id', 'business_id'],
                'formation_direction_membership_business_fk',
            )->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
        });

        Schema::create('existing_business_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id')->unique();
            $table->date('operating_since')->nullable();
            $table->text('summary')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('financial_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->date('as_of_date');
            $table->decimal('revenue', 20, 2)->default(0);
            $table->decimal('expenses', 20, 2)->default(0);
            $table->decimal('cash', 20, 2)->default(0);
            $table->decimal('receivables', 20, 2)->default(0);
            $table->decimal('payables', 20, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('business_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('name', 160);
            $table->decimal('estimated_value', 20, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('business_liabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('name', 160);
            $table->decimal('outstanding_amount', 20, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('existing_owner_positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('owner_name', 160);
            $table->decimal('baseline_percent', 7, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('current_obligations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('title', 160);
            $table->text('details')->nullable();
            $table->date('due_date')->nullable();
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('current_risk_control_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('risk', 160);
            $table->string('control_status', 80);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('agreement_constraints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('title', 160);
            $table->text('details')->nullable();
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('gap_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id')->unique();
            $table->text('gaps')->nullable();
            $table->text('priorities')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('partnership_conversion_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id')->unique();
            $table->text('plan')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        Schema::create('valuations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->date('as_of_date');
            $table->decimal('amount', 20, 2);
            $table->string('method', 160);
            $table->string('review_state', 24)->default('draft');
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE formation_assumptions
             ADD CONSTRAINT formation_assumptions_status_check
             CHECK (status IN ('planned','testing','validated','invalidated'))",
        );

        DB::statement(
            "ALTER TABLE validation_activities
             ADD CONSTRAINT validation_activities_status_check
             CHECK (status IN ('planned','in_progress','completed'))",
        );

        DB::statement(
            "ALTER TABLE formation_direction_decisions
             ADD CONSTRAINT formation_direction_check
             CHECK (direction IN ('go','revise','hold','no_go'))",
        );

        DB::statement(
            'ALTER TABLE existing_owner_positions
             ADD CONSTRAINT existing_owner_positions_percent_check
             CHECK (
                 baseline_percent IS NULL
                 OR (baseline_percent >= 0 AND baseline_percent <= 100)
             )',
        );

        DB::statement(
            "ALTER TABLE valuations
             ADD CONSTRAINT valuations_review_state_check
             CHECK (review_state IN ('draft','reviewed'))",
        );

        foreach ([
            'business_ideas',
            'business_model_canvases',
            'formation_assumptions',
            'validation_activities',
            'feasibility_scenarios',
            'partnership_fit_assessments',
            'existing_business_profiles',
            'gap_assessments',
            'partnership_conversion_plans',
        ] as $table) {
            DB::statement(
                "ALTER TABLE {$table}
                 ADD CONSTRAINT {$table}_revision_positive
                 CHECK (revision > 0)",
            );
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_protect_formation_direction_history()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'formation direction decision history is append-only';
END;
$$;

CREATE TRIGGER formation_direction_history_immutable
BEFORE UPDATE OR DELETE
ON formation_direction_decisions
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_formation_direction_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('valuations');
        Schema::dropIfExists('partnership_conversion_plans');
        Schema::dropIfExists('gap_assessments');
        Schema::dropIfExists('agreement_constraints');
        Schema::dropIfExists('current_risk_control_snapshots');
        Schema::dropIfExists('current_obligations');
        Schema::dropIfExists('existing_owner_positions');
        Schema::dropIfExists('business_liabilities');
        Schema::dropIfExists('business_assets');
        Schema::dropIfExists('financial_snapshots');
        Schema::dropIfExists('existing_business_profiles');
        Schema::dropIfExists('formation_direction_decisions');
        Schema::dropIfExists('partnership_fit_assessments');
        Schema::dropIfExists('feasibility_scenarios');
        Schema::dropIfExists('validation_evidence_links');
        Schema::dropIfExists('validation_activities');
        Schema::dropIfExists('formation_assumptions');
        Schema::dropIfExists('business_model_canvases');
        Schema::dropIfExists('business_ideas');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_formation_direction_history();',
        );
    }
};
