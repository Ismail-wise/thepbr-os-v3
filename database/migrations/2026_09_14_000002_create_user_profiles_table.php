<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->uuid('user_id')->primary();
            $table->string('display_name', 120);
            $table->string('language_mode', 16)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->timestampsTz();

            $table
                ->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });

        DB::statement(
            "ALTER TABLE user_profiles
             ADD CONSTRAINT user_profiles_language_mode_check
             CHECK (language_mode IN ('en', 'my', 'mixed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
