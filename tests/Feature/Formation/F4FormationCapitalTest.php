<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\BusinessModelPlanning;
use App\Application\Formation\CapitalPlanning;
use App\Application\Formation\ExistingBusinessBaseline;
use App\Application\Formation\GetFormationWorkspace;
use App\Application\Formation\NewBusinessPlanning;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Capital\ValueObjects\CapitalRequirement;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class F4FormationCapitalTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_business_planning_is_editable_and_direction_does_not_mutate_business_stage(): void
    {
        $user = $this->activeUser('f4-new@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'F4 New Business',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Idea,
            'USD',
        );

        $planning = $this->app->make(NewBusinessPlanning::class);
        $businessModel = $this->app->make(BusinessModelPlanning::class);

        $idea = $planning->saveIdea(
            $user,
            $business,
            0,
            [
                'summary' => 'Evidence-led service business',
                'problem' => 'Manual coordination',
                'target_customer' => 'SME teams',
                'proposed_solution' => 'Structured operating workflow',
            ],
        );

        self::assertSame(1, $idea['revision']);

        $bmc = $businessModel->saveBmc(
            $user,
            $business,
            0,
            $this->bmc(),
        );

        self::assertSame(1, $bmc['revision']);

        try {
            $businessModel->saveBmc(
                $user,
                $business,
                0,
                $this->bmc(),
            );

            self::fail('Expected stale BMC revision to be rejected.');
        } catch (StaleRevision) {
            self::assertTrue(true);
        }

        $planning->recordDirection(
            $user,
            $business,
            'go',
            'Owners choose to continue after reviewing the planning evidence.',
        );

        $business->refresh();

        self::assertSame(
            BusinessStage::Idea,
            $business->business_stage,
        );

        $this->assertDatabaseHas('formation_direction_decisions', [
            'business_id' => $business->getKey(),
            'direction' => 'go',
        ]);
    }

    public function test_existing_business_baseline_keeps_valuation_as_review_state_not_governance_approval(): void
    {
        $user = $this->activeUser('f4-existing@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'F4 Existing Business',
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            BusinessStage::Operating,
            'THB',
        );

        $baseline = $this->app->make(ExistingBusinessBaseline::class);
        $businessModel = $this->app->make(BusinessModelPlanning::class);

        $businessModel->saveBmc(
            $user,
            $business,
            0,
            $this->bmc(),
        );

        $baseline->saveProfile(
            $user,
            $business,
            0,
            [
                'operating_since' => '2021-01-01',
                'summary' => 'Operating retail business',
                'notes' => null,
            ],
        );

        $baseline->addOwnerPosition(
            $user,
            $business,
            [
                'owner_name' => 'Existing Owner',
                'baseline_percent' => '100.0000',
                'notes' => 'Baseline only, not the Effective Ownership Register.',
            ],
        );

        $baseline->addValuation(
            $user,
            $business,
            [
                'as_of_date' => '2026-09-26',
                'amount' => '2500000.00',
                'method' => 'Owner-supported baseline valuation',
                'review_state' => 'reviewed',
                'notes' => 'Reviewed baseline; governance approval remains separate.',
            ],
        );

        $workspace = $this->app->make(GetFormationWorkspace::class)
            ->execute($user, $business);

        self::assertNotNull($workspace);
        self::assertSame('existing', $workspace['journey']);
        self::assertSame(
            'SME operators',
            $workspace['bmc']['customer_segments'],
        );
        self::assertCount(
            1,
            $workspace['existing_business']['owner_positions'],
        );
        self::assertSame(
            'reviewed',
            $workspace['existing_business']['valuations'][0]['review_state'],
        );
    }

    public function test_capital_scenario_never_becomes_official_until_existing_governance_flow_makes_the_exact_record_effective(): void
    {
        $user = $this->activeUser('f4-capital@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'F4 Capital Business',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        $capital = $this->app->make(CapitalPlanning::class);

        $scenario = $capital->saveScenario(
            $user,
            $business,
            'base',
            0,
            'Base',
            new CapitalRequirement(
                '1000.00',
                '2000.00',
                '3000.00',
                '500.00',
                '2500.00',
            ),
            'Planning scenario only.',
        );

        self::assertSame('6500.00', $scenario['total_requirement']);
        self::assertSame('4000.00', $scenario['funding_gap']);
        $this->assertDatabaseCount('record_family_effective_heads', 0);

        $promotion = $capital->promoteScenario(
            $user,
            $business,
            'base',
        );

        self::assertNotNull($promotion);
        $this->assertDatabaseCount('capital_plan_promotions', 1);
        $this->assertDatabaseCount('proposal_versions', 1);
        $this->assertDatabaseCount('record_family_effective_heads', 0);

        self::assertSame(
            'ready_for_review',
            DB::table('record_version_state_transitions')
                ->where(
                    'formal_record_version_id',
                    $promotion['formal_record_version_id'],
                )
                ->orderByDesc('sequence')
                ->value('to_state'),
        );

        self::assertTrue(
            $capital->advanceContentReview(
                $user,
                $business,
                $promotion['id'],
                FormalRecordState::UnderReview,
            ),
        );

        self::assertTrue(
            $capital->advanceContentReview(
                $user,
                $business,
                $promotion['id'],
                FormalRecordState::Approved,
            ),
        );

        self::assertSame(
            'approved',
            DB::table('record_version_state_transitions')
                ->where(
                    'formal_record_version_id',
                    $promotion['formal_record_version_id'],
                )
                ->orderByDesc('sequence')
                ->value('to_state'),
        );

        $this->assertDatabaseCount('record_family_effective_heads', 0);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_database_rejects_a_capital_total_that_breaks_the_four_part_invariant(): void
    {
        $user = $this->activeUser('f4-db-invariant@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'F4 DB Invariant',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        $this->expectException(QueryException::class);

        DB::table('capital_scenarios')->insert([
            'id' => (string) str()->uuid7(),
            'business_id' => $business->getKey(),
            'scenario_kind' => 'lean',
            'name' => 'Invalid',
            'pre_opening_costs' => '100.00',
            'initial_assets_inventory' => '100.00',
            'working_capital' => '100.00',
            'contingency_reserve' => '100.00',
            'available_funding' => '0.00',
            'total_requirement' => '999.00',
            'funding_gap' => '999.00',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function activeUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function bmc(): array
    {
        return [
            'customer_segments' => 'SME operators',
            'value_propositions' => 'Clear business operating system',
            'channels' => 'Direct and partner channels',
            'customer_relationships' => 'Guided support',
            'revenue_streams' => 'Subscription and service revenue',
            'key_resources' => 'Team, data and systems',
            'key_activities' => 'Delivery and validation',
            'key_partnerships' => 'Strategic partners',
            'cost_structure' => 'People, tools and operations',
        ];
    }
}
