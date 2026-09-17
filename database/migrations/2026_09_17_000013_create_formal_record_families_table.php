<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formal_record_families', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('record_type', 160);
            $table->string('subject_type', 160);
            $table->string('subject_id', 191);
            $table->timestampsTz();

            $table->unique(
                ['id', 'business_id'],
                'formal_record_families_id_business_unique',
            );

            $table->unique(
                [
                    'business_id',
                    'record_type',
                    'subject_type',
                    'subject_id',
                ],
                'formal_record_families_scope_unique',
            );

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formal_record_families');
    }
};
