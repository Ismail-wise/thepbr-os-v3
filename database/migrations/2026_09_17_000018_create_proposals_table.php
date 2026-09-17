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
        Schema::create('proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->unsignedBigInteger('revision')->default(1);
            $table->char('content_hash', 64);
            $table->uuid('created_by_user_id');
            $table->uuid('last_changed_by_user_id');
            $table->timestampsTz();

            $table->unique(
                ['id', 'business_id'],
                'proposals_id_business_unique',
            );

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign('last_changed_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE proposals
             ADD CONSTRAINT proposals_revision_positive
             CHECK (revision > 0)',
        );

        DB::statement(
            "ALTER TABLE proposals
             ADD CONSTRAINT proposals_content_hash_sha256
             CHECK (content_hash ~ '^[0-9a-f]{64}$')",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
