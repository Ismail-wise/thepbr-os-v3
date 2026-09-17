<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->unique(
                ['id', 'business_id'],
                'memberships_id_business_unique',
            );
        });

        Schema::create(
            'membership_permission_profiles',
            function (Blueprint $table): void {
                $table->uuid('business_id');
                $table->uuid('membership_id');
                $table->uuid('permission_profile_id');
                $table->timestampsTz();

                $table
                    ->foreign('business_id')
                    ->references('id')
                    ->on('businesses')
                    ->restrictOnDelete();

                $table
                    ->foreign(
                        ['membership_id', 'business_id'],
                        'membership_profiles_membership_business_foreign',
                    )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();

                $table
                    ->foreign(
                        ['permission_profile_id', 'business_id'],
                        'membership_profiles_profile_business_foreign',
                    )
                    ->references(['id', 'business_id'])
                    ->on('permission_profiles')
                    ->restrictOnDelete();

                $table->primary(
                    ['membership_id', 'permission_profile_id'],
                    'membership_permission_profiles_primary',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_permission_profiles');

        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropUnique('memberships_id_business_unique');
        });
    }
};
