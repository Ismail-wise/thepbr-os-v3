<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('origin_type', 64);
            $table->string('business_stage', 32);
            $table->string('setup_phase', 32)->nullable();
            $table->string('workspace_status', 32)->default('active');
            $table->string('base_currency', 3);
            $table->timestampsTz();
        });

        DB::statement(
            "ALTER TABLE businesses
             ADD CONSTRAINT businesses_name_nonblank_check
             CHECK (btrim(name) <> '')"
        );

        DB::statement(
            "ALTER TABLE businesses
             ADD CONSTRAINT businesses_origin_type_check
             CHECK (origin_type IN (
                 'started_through_pbr',
                 'existing_business_imported_into_pbr'
             ))"
        );

        DB::statement(
            "ALTER TABLE businesses
             ADD CONSTRAINT businesses_stage_check
             CHECK (business_stage IN (
                 'idea',
                 'validation',
                 'planning',
                 'pre_launch',
                 'operating',
                 'growth',
                 'restructuring',
                 'exit'
             ))"
        );

        DB::statement(
            "ALTER TABLE businesses
             ADD CONSTRAINT businesses_setup_phase_check
             CHECK (
                 setup_phase IS NULL
                 OR setup_phase IN ('formation')
             )"
        );

        DB::statement(
            "ALTER TABLE businesses
             ADD CONSTRAINT businesses_workspace_status_check
             CHECK (workspace_status IN (
                 'active',
                 'restricted',
                 'archived',
                 'closed'
             ))"
        );

        DB::statement(
            "ALTER TABLE businesses
             ADD CONSTRAINT businesses_base_currency_check
             CHECK (base_currency ~ '^[A-Z]{3}$')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
