<?php

namespace Tests\Feature\Businesses;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BusinessSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_schema_uses_postgresql_and_exact_core_columns(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $this->assertSame([
            'id',
            'name',
            'origin_type',
            'business_stage',
            'setup_phase',
            'workspace_status',
            'base_currency',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('businesses'));

        $this->assertFalse(Schema::hasColumn('businesses', 'status'));
        $this->assertFalse(Schema::hasColumn('businesses', 'owner_user_id'));
    }

    public function test_database_rejects_blank_business_name(): void
    {
        $this->expectException(QueryException::class);

        $this->insertBusiness([
            'name' => '   ',
        ]);
    }

    public function test_database_rejects_unknown_origin_type(): void
    {
        $this->expectException(QueryException::class);

        $this->insertBusiness([
            'origin_type' => 'legacy_import',
        ]);
    }

    public function test_database_rejects_unknown_business_stage(): void
    {
        $this->expectException(QueryException::class);

        $this->insertBusiness([
            'business_stage' => 'formation',
        ]);
    }

    public function test_database_rejects_unknown_setup_phase(): void
    {
        $this->expectException(QueryException::class);

        $this->insertBusiness([
            'setup_phase' => 'operating',
        ]);
    }

    public function test_database_rejects_unknown_workspace_status(): void
    {
        $this->expectException(QueryException::class);

        $this->insertBusiness([
            'workspace_status' => 'disabled',
        ]);
    }

    public function test_database_rejects_noncanonical_base_currency(): void
    {
        $this->expectException(QueryException::class);

        $this->insertBusiness([
            'base_currency' => 'usd',
        ]);
    }

    private function insertBusiness(array $overrides = []): void
    {
        $now = now();

        DB::table('businesses')->insert(array_merge([
            'id' => (string) Str::uuid7(),
            'name' => 'Example Business',
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'idea',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }
}
