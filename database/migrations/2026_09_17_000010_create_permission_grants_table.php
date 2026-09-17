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
            'permission_grants',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('membership_id');
                $table->uuid('permission_id');
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
                        'permission_grants_membership_business_foreign',
                    )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();

                $table
                    ->foreign('permission_id')
                    ->references('id')
                    ->on('permissions')
                    ->restrictOnDelete();

                $table->unique(
                    ['business_id', 'membership_id', 'permission_id'],
                    'permission_grants_subject_permission_unique',
                );
            },
        );

        DB::statement(
            "ALTER TABLE permission_grants
             ADD CONSTRAINT permission_grants_effect_check
             CHECK (effect IN ('allow', 'deny'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_grants');
    }
};
