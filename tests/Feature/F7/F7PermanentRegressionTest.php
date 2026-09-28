<?php

declare(strict_types=1);

namespace Tests\Feature\F7;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\AI\PbrAiAssistant;
use App\Application\Closure\GetClosureWorkspace;
use App\Application\Exit\GetExitWorkspace;
use App\Application\Health\GetBusinessHealth;
use App\Application\Import\GetImportWorkspace;
use App\Application\PartnerChanges\GetPartnerChangesWorkspace;
use App\Application\Portability\GetPortabilityWorkspace;
use App\Application\Reporting\GetReportsWorkspace;
use App\Application\Search\GlobalSearch;
use App\Domain\Records\Enums\FormalRecordState;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7PermanentRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_f7_read_surfaces_fail_closed_across_businesses(): void
    {
        [$userA, $businessA] = $this->workspace('f7-regression-a');
        [, $businessB] = $this->workspace('f7-regression-b');

        self::assertNotNull(
            $this->app->make(GetPartnerChangesWorkspace::class)
                ->execute($userA, $businessA),
        );

        foreach ([
            GetPartnerChangesWorkspace::class,
            GetExitWorkspace::class,
            GetClosureWorkspace::class,
            GetBusinessHealth::class,
            GetReportsWorkspace::class,
            GetImportWorkspace::class,
            GetPortabilityWorkspace::class,
        ] as $class) {
            $service = $this->app->make($class);

            self::assertNull(
                $service->execute($userA, $businessB),
                $class.' must fail closed across Business boundaries.',
            );
        }

        self::assertNull(
            $this->app->make(GlobalSearch::class)->execute(
                $userA,
                $businessB,
                'anything',
            ),
        );

        self::assertNull(
            $this->app->make(PbrAiAssistant::class)->workspace(
                $userA,
                $businessB,
            ),
        );
    }

    public function test_effective_record_remains_immutable_at_database_layer(): void
    {
        [$user, $business] = $this->workspace(
            'f7-regression-effective',
        );

        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'f7_regression_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'revision' => 1,
            'change_summary' => 'Immutable F7 regression baseline',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'content_hash' => str_repeat('a', 64),
            'frozen_at' => now()->subMinute(),
        ]);

        $states = [
            FormalRecordState::Draft,
            FormalRecordState::ReadyForReview,
            FormalRecordState::UnderReview,
            FormalRecordState::Approved,
            FormalRecordState::ReadyForEffect,
            FormalRecordState::Effective,
        ];

        $from = null;

        foreach ($states as $index => $state) {
            RecordVersionStateTransition::query()->create([
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => $index + 1,
                'from_state' => $from?->value,
                'to_state' => $state->value,
                'transitioned_by_user_id' => $user->getKey(),
                'occurred_at' => now(),
            ]);

            $from = $state;
        }

        DB::table('record_family_effective_heads')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::beginTransaction();

        try {
            DB::table('formal_record_versions')
                ->where('id', $version->getKey())
                ->update([
                    'change_summary' => 'forbidden rewrite',
                ]);

            DB::rollBack();
            self::fail(
                'Effective/frozen Formal Record mutation must be rejected.',
            );
        } catch (QueryException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            self::addToAssertionCount(1);
        }

        self::assertSame(
            'Immutable F7 regression baseline',
            $version->fresh()->change_summary,
        );
    }

    public function test_standard_system_access_does_not_create_other_rights_or_authority(): void
    {
        [, $business, $membership] = $this->workspace(
            'f7-regression-separation',
        );

        self::assertGreaterThan(
            0,
            DB::table('membership_permission_profiles')
                ->where('business_id', $business->getKey())
                ->where('membership_id', $membership->getKey())
                ->count(),
        );

        self::assertSame(
            0,
            DB::table('authority_snapshots')
                ->where('business_id', $business->getKey())
                ->count(),
            'System access must not manufacture Governance authority.',
        );
        self::assertSame(
            0,
            DB::table('ownership_register_versions')
                ->where('business_id', $business->getKey())
                ->count(),
            'System access must not manufacture Ownership rights.',
        );
        self::assertSame(
            0,
            DB::table('document_access_grants')
                ->where('business_id', $business->getKey())
                ->count(),
            'System access must not manufacture Document rights.',
        );
        self::assertSame(
            0,
            DB::table('governance_charter_rule_actors')
                ->where('business_id', $business->getKey())
                ->count(),
            'System profile must not manufacture Governance actors.',
        );
    }

    public function test_append_only_and_history_guards_remain_installed(): void
    {
        $expected = [
            'partner_lifecycle_transitions_append_only',
            'partner_change_eligibility_append_only',
            'partner_change_rofr_response_append_only',
            'ownership_transfer_sources_append_only',
            'membership_access_transitions_append_only',
            'exit_case_transitions_append_only',
            'closure_case_transitions_append_only',
            'business_archive_transitions_append_only',
            'business_portability_transitions_append_only',
            'business_portability_exports_history_guard',
        ];

        $found = DB::table('pg_trigger')
            ->whereIn('tgname', $expected)
            ->where('tgisinternal', false)
            ->pluck('tgname')
            ->all();

        sort($expected);
        sort($found);

        self::assertSame($expected, $found);
    }

    /**
     * @return array{User,Business,Membership}
     */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Permanent Regression '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        $user = User::query()->create([
            'email' => $prefix.'-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => 'active',
            'password_changed_at' => now(),
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        $this->app
            ->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $membership);

        return [$user, $business, $membership];
    }
}
