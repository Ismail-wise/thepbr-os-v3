<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->unique(
                ['id', 'business_id'],
                'memberships_id_business_id_unique',
            );
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('title', 255);
            $table->string('category', 64);
            $table->uuid('created_by_membership_id');
            $table->timestampsTz();

            $table
                ->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table
                ->foreign(
                    ['created_by_membership_id', 'business_id'],
                    'documents_creator_membership_business_fk',
                )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->unique(
                ['id', 'business_id'],
                'documents_id_business_id_unique',
            );

            $table->index(
                ['business_id', 'category'],
                'documents_business_category_index',
            );
        });

        DB::statement(
            "ALTER TABLE documents
             ADD CONSTRAINT documents_title_nonblank_check
             CHECK (btrim(title) <> '')"
        );

        DB::statement(
            "ALTER TABLE documents
             ADD CONSTRAINT documents_category_check
             CHECK (category IN (
                 'corporate_legal',
                 'partners_ownership',
                 'agreements_contracts',
                 'finance_tax',
                 'governance_decisions',
                 'risk_insurance',
                 'operations',
                 'pbr_generated'
             ))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');

        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropUnique('memberships_id_business_id_unique');
        });
    }
};
