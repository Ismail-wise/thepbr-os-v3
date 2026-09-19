<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('membership_id');
            $table->uuid('document_id');
            $table->string('right', 32);
            $table->string('effect', 16);
            $table->timestampTz('created_at')->useCurrent();

            $table
                ->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table
                ->foreign(
                    ['membership_id', 'business_id'],
                    'document_access_grants_membership_business_fk',
                )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table
                ->foreign(
                    ['document_id', 'business_id'],
                    'document_access_grants_document_business_fk',
                )
                ->references(['id', 'business_id'])
                ->on('documents')
                ->restrictOnDelete();

            $table->unique(
                [
                    'business_id',
                    'membership_id',
                    'document_id',
                    'right',
                    'effect',
                ],
                'document_access_grants_scope_unique',
            );

            $table->index(
                ['business_id', 'membership_id', 'document_id'],
                'document_access_grants_lookup_index',
            );
        });

        DB::statement(
            "ALTER TABLE document_access_grants
             ADD CONSTRAINT document_access_grants_right_check
             CHECK (\"right\" IN ('view', 'manage'))"
        );

        DB::statement(
            "ALTER TABLE document_access_grants
             ADD CONSTRAINT document_access_grants_effect_check
             CHECK (effect IN ('allow', 'deny'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_grants');
    }
};
