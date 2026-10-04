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
            'partner_dynamics_personal_assessments',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('assessment_version', 40);
                $table->string('status', 20)->default('draft');
                $table->json('answers')->nullable();
                $table->json('dimension_scores')->nullable();
                $table->json('behaviour_profile_scores')->nullable();
                $table->json('scenario_scores')->nullable();
                $table->json('scenario_counts')->nullable();
                $table->json('profile_scores')->nullable();
                $table->string('primary_profile', 40)->nullable();
                $table->decimal('primary_score', 6, 2)->nullable();
                $table->string('secondary_profile', 40)->nullable();
                $table->decimal('secondary_score', 6, 2)->nullable();
                $table->boolean('is_blended')->default(false);
                $table->string('result_confidence', 20)->nullable();
                $table->json('consistency_data')->nullable();
                $table->timestampTz('started_at')->nullable();
                $table->timestampTz('completed_at')->nullable();
                $table->timestampsTz();

                $table->index(
                    ['user_id', 'status', 'completed_at'],
                    'pd_personal_user_status_completed_idx',
                );

                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            },
        );

        DB::statement(
            "ALTER TABLE partner_dynamics_personal_assessments
             ADD CONSTRAINT pd_personal_status_check
             CHECK (status IN ('draft','completed'))",
        );

        DB::statement(
            "ALTER TABLE partner_dynamics_personal_assessments
             ADD CONSTRAINT pd_personal_primary_profile_check
             CHECK (
                primary_profile IS NULL
                OR primary_profile IN (
                    'visionary','builder','connector','analyst',
                    'operator','guardian','negotiator','optimizer'
                )
             )",
        );

        DB::statement(
            "ALTER TABLE partner_dynamics_personal_assessments
             ADD CONSTRAINT pd_personal_secondary_profile_check
             CHECK (
                secondary_profile IS NULL
                OR secondary_profile IN (
                    'visionary','builder','connector','analyst',
                    'operator','guardian','negotiator','optimizer'
                )
             )",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_dynamics_personal_assessments');
    }
};
