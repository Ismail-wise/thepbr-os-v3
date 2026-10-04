<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'business_model_operating_profiles',
            function (Blueprint $table): void {
                $table->text('competition_alternatives')
                    ->nullable()
                    ->after('location');
            },
        );
    }

    public function down(): void
    {
        Schema::table(
            'business_model_operating_profiles',
            function (Blueprint $table): void {
                $table->dropColumn('competition_alternatives');
            },
        );
    }
};
