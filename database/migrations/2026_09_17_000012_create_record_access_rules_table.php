<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'record_access_rules',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('membership_id')->nullable();
                $table->uuid('permission_profile_id')->nullable();
                $table->uuid('permission_id');
                $table->string('resource_type', 160);
                $table->uuid('resource_id');
                $table->string('effect', 16);
                $table->timestampsTz();

                $table
                    ->foreign('business_id')
                    ->references('id')
                    ->on('businesses')
                    ->restrictOnDelete();

                $table
                    ->foreign(
                        ['membership_id', 'business_id'],
                        'record_rules_membership_business_foreign',
                    )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();

                $table
                    ->foreign(
                        ['permission_profile_id', 'business_id'],
                        'record_rules_profile_business_foreign',
                    )
                    ->references(['id', 'business_id'])
                    ->on('permission_profiles')
                    ->restrictOnDelete();

                $table
                    ->foreign('permission_id')
                    ->references('id')
                    ->on('permissions')
                    ->restrictOnDelete();
            },
        );

        DB::statement(
            'ALTER TABLE record_access_rules
             ADD CONSTRAINT record_access_rules_subject_check
             CHECK (
                 (membership_id IS NOT NULL)
                 <> (permission_profile_id IS NOT NULL)
             )'
        );

        DB::statement(
            "ALTER TABLE record_access_rules
             ADD CONSTRAINT record_access_rules_resource_type_check
             CHECK (
                 btrim(resource_type) <> ''
                 AND resource_type = btrim(resource_type)
             )"
        );

        DB::statement(
            "ALTER TABLE record_access_rules
             ADD CONSTRAINT record_access_rules_effect_check
             CHECK (effect IN ('allow', 'deny'))"
        );

        DB::statement(
            'CREATE UNIQUE INDEX record_access_rules_membership_unique
             ON record_access_rules (
                 business_id,
                 membership_id,
                 permission_id,
                 resource_type,
                 resource_id
             )
             WHERE membership_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX record_access_rules_profile_unique
             ON record_access_rules (
                 business_id,
                 permission_profile_id,
                 permission_id,
                 resource_type,
                 resource_id
             )
             WHERE permission_profile_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('record_access_rules');
    }
};
