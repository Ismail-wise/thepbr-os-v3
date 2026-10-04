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
        Schema::create('business_model_operating_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id')->unique();
            $table->text('business_purpose')->nullable();
            $table->text('market')->nullable();
            $table->text('location')->nullable();
            $table->text('operating_model')->nullable();
            $table->text('excluded_activities')->nullable();
            $table->text('pricing_notes')->nullable();
            $table->string('unit_name', 80)->nullable();
            $table->decimal('average_selling_price', 20, 2)->nullable();
            $table->decimal('variable_cost_per_unit', 20, 2)->nullable();
            $table->decimal('monthly_fixed_cost', 20, 2)->nullable();
            $table->decimal('expected_monthly_units', 20, 2)->nullable();
            $table->text('scalability_strategy')->nullable();
            $table->text('scalability_constraints')->nullable();
            $table->text('first_12_month_plan')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();
        });

        foreach ([
            'average_selling_price',
            'variable_cost_per_unit',
            'monthly_fixed_cost',
            'expected_monthly_units',
        ] as $column) {
            DB::statement(
                "ALTER TABLE business_model_operating_profiles
                 ADD CONSTRAINT business_model_operating_profiles_{$column}_nonnegative
                 CHECK ({$column} IS NULL OR {$column} >= 0)",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_model_operating_profiles');
    }
};
