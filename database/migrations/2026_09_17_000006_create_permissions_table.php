<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 160)->unique();
            $table->timestampsTz();
        });

        DB::statement(
            'ALTER TABLE permissions
             ADD CONSTRAINT permissions_key_nonblank_check
             CHECK (
                 btrim("key") <> \'\'
                 AND "key" = btrim("key")
             )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
