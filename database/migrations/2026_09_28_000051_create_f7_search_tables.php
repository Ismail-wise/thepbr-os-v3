<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private array $profileNames = [
        'Workspace Owner',
        'Partner',
        'Managing Partner / CEO',
        'Finance Owner',
        'Governance Secretary / PBR Administrator',
        'Advisor / Consultant',
        'Auditor / Viewer',
        'External Accountant / Legal Advisor',
    ];

    public function up(): void
    {
        Schema::create('search_index_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('source_type', 80);
            $table->uuid('source_id');
            $table->text('title');
            $table->text('snippet')->nullable();
            $table->text('search_text');
            $table->string('route', 240)->nullable();
            $table->timestampTz('indexed_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['business_id', 'source_type', 'source_id'],
                'search_index_source_business_uq',
            );

            $table->index(
                ['business_id', 'source_type'],
                'search_index_business_type_idx',
            );

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
ALTER TABLE search_index_entries
ADD COLUMN search_vector tsvector
GENERATED ALWAYS AS (
    to_tsvector(
        'simple',
        coalesce(title, '') || ' ' || coalesce(search_text, '')
    )
) STORED
SQL);

        DB::statement(<<<'SQL'
CREATE INDEX search_index_entries_fts_idx
ON search_index_entries
USING GIN (search_vector)
SQL);

        $this->backfillSearchCapability();
    }

    private function backfillSearchCapability(): void
    {
        $permissionId = DB::table('permissions')
            ->where('key', 'search.view')
            ->value('id');

        if ($permissionId === null) {
            $permissionId = (string) Str::uuid7();

            DB::table('permissions')->insert([
                'id' => $permissionId,
                'key' => 'search.view',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $profiles = DB::table('permission_profiles')
            ->whereIn('name', $this->profileNames)
            ->get(['id', 'business_id']);

        foreach ($profiles as $profile) {
            DB::table('permission_profile_permissions')->insertOrIgnore([
                'business_id' => $profile->business_id,
                'permission_profile_id' => $profile->id,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_index_entries');
    }
};
