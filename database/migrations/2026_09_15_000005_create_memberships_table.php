<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('business_id');
            $table->string('access_status', 32);
            $table->timestampsTz();

            $table
                ->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table
                ->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table->unique(['user_id', 'business_id']);
        });

        DB::statement(
            "ALTER TABLE memberships
             ADD CONSTRAINT memberships_access_status_check
             CHECK (access_status IN ('active'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
