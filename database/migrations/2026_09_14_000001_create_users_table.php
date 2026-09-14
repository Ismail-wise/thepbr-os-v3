<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email', 254)->unique();
            $table->string('password', 255);
            $table->string('status', 32)->default('provisioned');
            $table->timestampTz('password_changed_at');
            $table->timestampsTz();
        });

        DB::statement(
            'ALTER TABLE users
             ADD CONSTRAINT users_email_canonical_check
             CHECK (email = lower(btrim(email)))'
        );

        DB::statement(
            "ALTER TABLE users
             ADD CONSTRAINT users_status_check
             CHECK (status IN ('provisioned', 'active', 'suspended', 'disabled'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
