<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'permission_profile_permissions',
            function (Blueprint $table): void {
                $table->uuid('business_id');
                $table->uuid('permission_profile_id');
                $table->uuid('permission_id');
                $table->timestampsTz();

                $table
                    ->foreign('business_id')
                    ->references('id')
                    ->on('businesses')
                    ->restrictOnDelete();

                $table
                    ->foreign(
                        ['permission_profile_id', 'business_id'],
                        'profile_permissions_profile_business_foreign',
                    )
                    ->references(['id', 'business_id'])
                    ->on('permission_profiles')
                    ->restrictOnDelete();

                $table
                    ->foreign('permission_id')
                    ->references('id')
                    ->on('permissions')
                    ->restrictOnDelete();

                $table->primary(
                    ['permission_profile_id', 'permission_id'],
                    'permission_profile_permissions_primary',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_profile_permissions');
    }
};
