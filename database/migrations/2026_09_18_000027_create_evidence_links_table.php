<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('evidence_id');
            $table->string('target_type', 120);
            $table->uuid('target_id');
            $table->uuid('created_by_membership_id');
            $table->timestampTz('created_at')->useCurrent();

            $table
                ->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table
                ->foreign(
                    ['evidence_id', 'business_id'],
                    'evidence_links_evidence_business_fk',
                )
                ->references(['id', 'business_id'])
                ->on('evidence')
                ->restrictOnDelete();

            $table
                ->foreign(
                    ['created_by_membership_id', 'business_id'],
                    'evidence_links_creator_membership_business_fk',
                )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->unique(
                ['evidence_id', 'target_type', 'target_id'],
                'evidence_links_target_unique',
            );

            $table->index(
                ['business_id', 'target_type', 'target_id'],
                'evidence_links_business_target_index',
            );
        });

        DB::statement(
            "ALTER TABLE evidence_links
             ADD CONSTRAINT evidence_links_target_type_nonblank_check
             CHECK (btrim(target_type) <> '')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_links');
    }
};
