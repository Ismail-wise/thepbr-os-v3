<?php

declare(strict_types=1);

namespace Tests\Feature\Records;

use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Records\Enums\FormalRecordState;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EffectiveRecordImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_frozen_version_cannot_be_updated_or_deleted_at_database_layer(): void
    {
        [$user, $business, $version] = $this->frozenVersion();

        $this->assertDatabaseRejects(
            fn () => DB::table('formal_record_versions')
                ->where('id', $version->getKey())
                ->update(['change_summary' => 'tampered']),
        );

        $this->assertDatabaseRejects(
            fn () => DB::table('formal_record_versions')
                ->where('id', $version->getKey())
                ->delete(),
        );

        $this->assertSame(
            'Frozen baseline',
            $version->fresh()->change_summary,
        );
    }

    public function test_effective_version_remains_database_immutable(): void
    {
        [$user, $business, $version] = $this->frozenVersion(
            now()->subMinute(),
        );

        $states = [
            FormalRecordState::ReadyForReview,
            FormalRecordState::UnderReview,
            FormalRecordState::Approved,
            FormalRecordState::ReadyForEffect,
            FormalRecordState::Effective,
        ];

        $from = FormalRecordState::Draft;
        $sequence = 2;

        foreach ($states as $state) {
            RecordVersionStateTransition::query()->create([
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => $sequence++,
                'from_state' => $from->value,
                'to_state' => $state->value,
                'transitioned_by_user_id' => $user->getKey(),
                'occurred_at' => now(),
            ]);

            $from = $state;
        }

        $this->assertDatabaseRejects(
            fn () => DB::table('formal_record_versions')
                ->where('id', $version->getKey())
                ->update(['content_hash' => str_repeat('f', 64)]),
        );

        $this->assertSame(
            str_repeat('a', 64),
            $version->fresh()->content_hash,
        );
    }

    /**
     * @return array{User, Business, FormalRecordVersion}
     */
    private function frozenVersion(
        mixed $effectiveFrom = null,
    ): array {
        $user = User::query()->create([
            'email' => 'immutable-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => AccountStatus::Active->value,
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'name' => 'Immutable Business '.Str::uuid7(),
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

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'revision' => 1,
            'change_summary' => 'Frozen baseline',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => $effectiveFrom,
            'content_hash' => str_repeat('a', 64),
            'frozen_at' => now(),
        ]);

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'from_state' => null,
            'to_state' => FormalRecordState::Draft->value,
            'transitioned_by_user_id' => $user->getKey(),
            'occurred_at' => now(),
        ]);

        return [$user, $business, $version];
    }

    private function assertDatabaseRejects(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::rollBack();
            $this->fail('Expected immutable database write to be rejected.');
        } catch (QueryException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->addToAssertionCount(1);
        }
    }
}
