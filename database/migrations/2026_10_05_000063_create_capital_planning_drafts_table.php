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
        Schema::create('capital_planning_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('contract_version', 80);
            $table->jsonb('input_payload');
            $table->unsignedBigInteger('revision');
            $table->uuid('created_by_membership_id');
            $table->uuid('updated_by_membership_id');
            $table->timestampsTz();

            $table->unique(
                ['business_id'],
                'capital_planning_drafts_business_unique',
            );

            $table->unique(
                ['id', 'business_id'],
                'capital_planning_drafts_id_business_unique',
            );

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'capital_planning_drafts_creator_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign(
                ['updated_by_membership_id', 'business_id'],
                'capital_planning_drafts_updater_business_fk',
            )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE capital_planning_drafts
             ADD CONSTRAINT capital_planning_drafts_contract_check
             CHECK (contract_version = 'capital-planning-draft-v1')",
        );

        DB::statement(
            "ALTER TABLE capital_planning_drafts
             ADD CONSTRAINT capital_planning_drafts_payload_object_check
             CHECK (jsonb_typeof(input_payload) = 'object')",
        );

        DB::statement(
            'ALTER TABLE capital_planning_drafts
             ADD CONSTRAINT capital_planning_drafts_revision_positive
             CHECK (revision > 0)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('capital_planning_drafts');
    }
};
