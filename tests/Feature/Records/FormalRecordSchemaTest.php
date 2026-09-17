<?php

declare(strict_types=1);

namespace Tests\Feature\Records;

use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FormalRecordSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_round_two_record_tables_and_columns_exist(): void
    {
        foreach ([
            'formal_record_families',
            'formal_record_versions',
            'record_version_state_transitions',
            'record_family_effective_heads',
            'record_version_supersessions',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertTrue(
            Schema::hasColumns(
                'formal_record_versions',
                [
                    'business_id',
                    'formal_record_family_id',
                    'version_number',
                    'predecessor_version_id',
                    'revision',
                    'change_summary',
                    'created_by_user_id',
                    'last_changed_by_user_id',
                    'effective_from',
                    'effective_until',
                    'review_due_at',
                    'content_hash',
                    'frozen_at',
                ],
            ),
        );
    }

    public function test_invalid_version_revision_and_effective_ranges_are_rejected(): void
    {
        [$user, $business, $family] = $this->baseRecords();

        $base = [
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'predecessor_version_id' => null,
            'change_summary' => 'Schema probe',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('a', 64),
            'frozen_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->assertDatabaseRejects(
            fn () => DB::table('formal_record_versions')->insert(
                $base + [
                    'id' => (string) Str::uuid7(),
                    'version_number' => 0,
                    'revision' => 1,
                ],
            ),
        );

        $this->assertDatabaseRejects(
            fn () => DB::table('formal_record_versions')->insert(
                $base + [
                    'id' => (string) Str::uuid7(),
                    'version_number' => 1,
                    'revision' => 0,
                ],
            ),
        );

        $this->assertDatabaseRejects(
            fn () => DB::table('formal_record_versions')->insert(
                array_merge(
                    $base,
                    [
                        'id' => (string) Str::uuid7(),
                        'version_number' => 1,
                        'revision' => 1,
                        'effective_from' => now(),
                        'effective_until' => now()->subDay(),
                    ],
                ),
            ),
        );
    }

    public function test_predecessor_is_database_restricted_to_same_family(): void
    {
        [$user, $business, $familyA] = $this->baseRecords();

        $familyB = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'policy',
            'subject_type' => 'business',
            'subject_id' => 'B',
        ]);

        $predecessor = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $familyB->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Other family',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'content_hash' => str_repeat('b', 64),
        ]);

        $exception = $this->assertDatabaseRejects(
            fn () => FormalRecordVersion::query()->create([
                'business_id' => $business->getKey(),
                'formal_record_family_id' => $familyA->getKey(),
                'version_number' => 2,
                'predecessor_version_id' => $predecessor->getKey(),
                'revision' => 1,
                'change_summary' => 'Invalid lineage',
                'created_by_user_id' => $user->getKey(),
                'last_changed_by_user_id' => $user->getKey(),
                'content_hash' => str_repeat('c', 64),
            ]),
        );

        $this->assertStringContainsString(
            'same family',
            strtolower($exception->getMessage()),
        );
    }

    public function test_required_postgresql_integrity_triggers_exist(): void
    {
        $triggerNames = collect(
            DB::select(
                "SELECT tgname
                 FROM pg_trigger
                 WHERE NOT tgisinternal
                   AND tgrelid::regclass::text IN (
                       'formal_record_versions',
                       'record_version_state_transitions',
                       'record_family_effective_heads',
                       'record_version_supersessions'
                   )
                 ORDER BY tgname",
            ),
        )->pluck('tgname')->all();

        foreach ([
            'formal_record_versions_protect_frozen',
            'formal_record_versions_validate_lineage',
            'record_effective_heads_validate',
            'record_state_transitions_append_only',
            'record_state_transitions_validate',
            'record_supersessions_append_only',
            'record_supersessions_validate',
        ] as $expected) {
            $this->assertContains($expected, $triggerNames);
        }
    }

    /**
     * @return array{User, Business, FormalRecordFamily}
     */
    private function baseRecords(): array
    {
        $user = User::query()->create([
            'email' => 'schema-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => AccountStatus::Active->value,
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'name' => 'Schema Business '.Str::uuid7(),
            'origin_type' => BusinessOriginType::StartedThroughPbr->value,
            'business_stage' => BusinessStage::Idea->value,
            'setup_phase' => SetupPhase::Formation->value,
            'workspace_status' => WorkspaceStatus::Active->value,
            'base_currency' => 'USD',
        ]);

        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'policy',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        return [$user, $business, $family];
    }

    private function assertDatabaseRejects(callable $callback): QueryException
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::rollBack();
            $this->fail('Expected PostgreSQL to reject the invalid write.');
        } catch (QueryException $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->addToAssertionCount(1);

            return $exception;
        }
    }
}
