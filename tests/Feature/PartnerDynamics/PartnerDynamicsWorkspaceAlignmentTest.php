<?php

declare(strict_types=1);

namespace Tests\Feature\PartnerDynamics;

use App\Application\Businesses\CreateBusiness;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\PartnerDynamics\PartnerDynamicsAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PartnerDynamicsWorkspaceAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_workspace_alignment_reuses_linked_personal_results_without_exposing_raw_answers(): void
    {
        [$owner, $business] = $this->ownedBusiness(
            'pd-workspace-owner@example.test',
        );

        $partnerA = $this->linkedPartner(
            $business,
            'pd-workspace-a@example.test',
            'Partner A',
        );
        $partnerB = $this->linkedPartner(
            $business,
            'pd-workspace-b@example.test',
            'Partner B',
        );

        $scoresA = $this->dimensions(80);
        $scoresA['vision'] = 90;

        $scoresB = $this->dimensions(75);
        $scoresB['vision'] = 30;

        $this->completedAssessment(
            $partnerA['user'],
            'guardian',
            'analyst',
            $scoresA,
            [1 => 5, 2 => 1],
        );
        $this->completedAssessment(
            $partnerB['user'],
            'visionary',
            'builder',
            $scoresB,
            [1 => 1, 2 => 5],
        );

        $response = $this->actingAs($owner)
            ->withSession([
                'current_business_id' => (string) $business->getKey(),
            ])
            ->get('/partnership');

        $response->assertOk();

        $workspace = $response->viewData('page')['props']['partnership']['partner_dynamics_workspace'];

        self::assertSame(2, $workspace['progress']['completed']);
        self::assertSame(2, $workspace['progress']['total']);
        self::assertTrue($workspace['progress']['ready']);
        self::assertTrue($workspace['advisoryOnly']);
        self::assertCount(2, $workspace['participants']);
        self::assertNotNull($workspace['alignment']);
        self::assertCount(2, $workspace['alignment']['roleSuggestions']);
        self::assertNotEmpty(
            $workspace['alignment']['decisionRecommendations'],
        );
        self::assertNotEmpty(
            $workspace['alignment']['discussionPriorities'],
        );

        foreach ($workspace['participants'] as $participant) {
            self::assertArrayNotHasKey('answers', $participant);
            self::assertArrayNotHasKey('dimensionScores', $participant);
            self::assertArrayNotHasKey('userId', $participant);
        }

        foreach (
            $workspace['alignment']['importantDifferences'] as $difference
        ) {
            self::assertArrayNotHasKey('scores', $difference);
            self::assertArrayNotHasKey(
                'highest_participant',
                $difference,
            );
            self::assertArrayNotHasKey(
                'lowest_participant',
                $difference,
            );
        }

        self::assertStringNotContainsString(
            '"answers"',
            json_encode($workspace, JSON_THROW_ON_ERROR),
        );
    }

    public function test_pending_partner_progress_does_not_block_workspace_and_current_user_can_continue_personal_assessment(): void
    {
        [$owner, $business] = $this->ownedBusiness(
            'pd-workspace-pending-owner@example.test',
        );

        $this->linkExistingUserAsPartner(
            $business,
            $owner,
            'Owner Partner',
        );

        $other = $this->linkedPartner(
            $business,
            'pd-workspace-complete@example.test',
            'Completed Partner',
        );

        $this->completedAssessment(
            $other['user'],
            'operator',
            null,
            $this->dimensions(70),
            [1 => 3],
        );

        $response = $this->actingAs($owner)
            ->withSession([
                'current_business_id' => (string) $business->getKey(),
            ])
            ->get('/partnership');

        $response->assertOk();

        $workspace = $response->viewData('page')['props']['partnership']['partner_dynamics_workspace'];

        self::assertSame(1, $workspace['progress']['completed']);
        self::assertSame(2, $workspace['progress']['total']);
        self::assertFalse($workspace['progress']['ready']);
        self::assertTrue($workspace['currentUser']['linked']);
        self::assertFalse($workspace['currentUser']['completed']);
        self::assertSame(
            '/partner-dynamics',
            $workspace['currentUser']['assessmentRoute'],
        );
        self::assertNull($workspace['alignment']);

        self::assertSame(
            'pending',
            collect($workspace['participants'])
                ->firstWhere('isCurrentUser', true)['completionStatus'],
        );
    }

    public function test_workspace_alignment_is_scoped_to_current_business_membership_links(): void
    {
        [$owner, $businessA] = $this->ownedBusiness(
            'pd-workspace-isolation-owner@example.test',
        );

        $businessB = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $owner,
                'Partner Dynamics Other Business',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        $foreign = $this->linkedPartner(
            $businessB,
            'pd-workspace-foreign@example.test',
            'Foreign Partner',
        );

        $this->completedAssessment(
            $foreign['user'],
            'connector',
            null,
            $this->dimensions(82),
            [1 => 5],
        );

        $response = $this->actingAs($owner)
            ->withSession([
                'current_business_id' => (string) $businessA->getKey(),
            ])
            ->get('/partnership');

        $response->assertOk();

        $workspace = $response->viewData('page')['props']['partnership']['partner_dynamics_workspace'];

        self::assertSame(0, $workspace['progress']['completed']);
        self::assertSame(0, $workspace['progress']['total']);
        self::assertSame([], $workspace['participants']);
        self::assertNull($workspace['alignment']);
    }

    /**
     * @return array{User,Business}
     */
    private function ownedBusiness(string $email): array
    {
        $owner = $this->activeUser($email);

        $business = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $owner,
                'Partner Dynamics Workspace Business',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        return [$owner, $business];
    }

    /**
     * @return array{user:User,partnerId:string,membershipId:string}
     */
    private function linkedPartner(
        Business $business,
        string $email,
        string $displayName,
    ): array {
        $user = $this->activeUser($email);

        return [
            'user' => $user,
            ...$this->linkExistingUserAsPartner(
                $business,
                $user,
                $displayName,
            ),
        ];
    }

    /**
     * @return array{partnerId:string,membershipId:string}
     */
    private function linkExistingUserAsPartner(
        Business $business,
        User $user,
        string $displayName,
    ): array {
        $membership = Membership::query()->firstOrCreate(
            [
                'user_id' => $user->getKey(),
                'business_id' => $business->getKey(),
            ],
            [
                'access_status' => MembershipAccessStatus::Active,
            ],
        );

        $partnerId = (string) Str::uuid7();

        DB::table('partners')->insert([
            'id' => $partnerId,
            'business_id' => $business->getKey(),
            'display_name' => $displayName,
            'legal_name' => null,
            'email' => $user->email,
            'status' => 'active',
            'notes' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('partner_membership_links')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'partner_id' => $partnerId,
            'membership_id' => $membership->getKey(),
            'linked_by_membership_id' => $membership->getKey(),
            'linked_at' => now(),
        ]);

        return [
            'partnerId' => $partnerId,
            'membershipId' => (string) $membership->getKey(),
        ];
    }

    /**
     * @param  array<string,float>  $scores
     * @param  array<int,int|string>  $answers
     */
    private function completedAssessment(
        User $user,
        string $primaryProfile,
        ?string $secondaryProfile,
        array $scores,
        array $answers,
    ): PartnerDynamicsAssessment {
        return PartnerDynamicsAssessment::query()->create([
            'user_id' => $user->getKey(),
            'assessment_version' => 'v1',
            'status' => 'completed',
            'answers' => $answers,
            'dimension_scores' => $scores,
            'behaviour_profile_scores' => [],
            'scenario_scores' => [],
            'scenario_counts' => [],
            'profile_scores' => [],
            'primary_profile' => $primaryProfile,
            'primary_score' => 80,
            'secondary_profile' => $secondaryProfile,
            'secondary_score' => $secondaryProfile === null ? null : 74,
            'is_blended' => $secondaryProfile !== null,
            'result_confidence' => 'strong',
            'consistency_data' => [],
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    /**
     * @return array<string,float>
     */
    private function dimensions(float $score): array
    {
        return [
            'vision' => $score,
            'execution' => $score,
            'people' => $score,
            'analysis' => $score,
            'structure' => $score,
            'risk' => $score,
            'decision' => $score,
            'adaptability' => $score,
        ];
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
}
