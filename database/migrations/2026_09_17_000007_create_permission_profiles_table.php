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
            'permission_profiles',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->string('name', 120);
                $table->timestampsTz();

                $table
                    ->foreign('business_id')
                    ->references('id')
                    ->on('businesses')
                    ->restrictOnDelete();

                $table->unique(
                    ['id', 'business_id'],
                    'permission_profiles_id_business_unique',
                );

                $table->unique(
                    ['business_id', 'name'],
                    'permission_profiles_business_name_unique',
                );
            },
        );

        DB::statement(
            'ALTER TABLE permission_profiles
             ADD CONSTRAINT permission_profiles_name_nonblank_check
             CHECK (
                 btrim(name) <> \'\'
                 AND name = btrim(name)
             )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_profiles');
    }
};
