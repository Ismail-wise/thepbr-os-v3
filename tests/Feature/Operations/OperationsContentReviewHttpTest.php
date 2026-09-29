<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Application\Businesses\CreateBusiness;
use App\Application\Operations\OperationsRegisterWorkflow;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OperationsContentReviewHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_ready_for_review_moves_to_under_review_once_and_repeat_is_actionable(): void
    {
        [$user, $business, $membership] = $this->ownerBusiness(
            'operations-review@example.test',
            'Operations Review',
        );

        $versionId = $this->readyForReview(
            $user,
            $business,
            $membership,
        );

        $session = [
            EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
        ];

        self::assertSame(
            'ready_for_review',
            $this->latestState($versionId),
        );

        $this
            ->actingAs($user)
            ->withSession($session)
            ->from('/operations')
            ->post(
                '/operations/register/'.$versionId.'/content-review',
                ['target' => 'under_review'],
            )
            ->assertRedirect('/operations')
            ->assertSessionHasNoErrors();

        self::assertSame(
            'under_review',
            $this->latestState($versionId),
        );
        self::assertSame(
            1,
            $this->transitionCount(
                $versionId,
                'under_review',
            ),
        );

        $this
            ->actingAs($user)
            ->withSession($session)
            ->get('/operations')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where(
                        'operations.versions.0.state',
                        'under_review',
                    ),
            );

        $this
            ->actingAs($user)
            ->withSession($session)
            ->from('/operations')
            ->post(
                '/operations/register/'.$versionId.'/content-review',
                ['target' => 'under_review'],
            )
            ->assertRedirect('/operations')
            ->assertSessionHasErrors('operations');

        self::assertSame(
            'under_review',
            $this->latestState($versionId),
        );
        self::assertSame(
            1,
            $this->transitionCount(
                $versionId,
                'under_review',
            ),
        );

        $this
            ->actingAs($user)
            ->withSession($session)
            ->from('/operations')
            ->post(
                '/operations/register/'.$versionId.'/content-review',
                ['target' => 'approved'],
            )
            ->assertRedirect('/operations')
            ->assertSessionHasNoErrors();

        self::assertSame(
            'approved',
            $this->latestState($versionId),
        );
        self::assertSame(
            1,
            $this->transitionCount(
                $versionId,
                'approved',
            ),
        );
    }

    public function test_foreign_business_version_is_not_visible_to_content_review_route(): void
    {
        $user = $this->activeUser(
            'operations-review-isolation@example.test',
        );

        [$businessA] = $this->businessForOwner(
            $user,
            'Operations Review A',
        );
        [$businessB, $membershipB] = $this->businessForOwner(
            $user,
            'Operations Review B',
        );

        $versionB = $this->readyForReview(
            $user,
            $businessB,
            $membershipB,
        );

        $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $businessA->getKey(),
            ])
            ->post(
                '/operations/register/'.$versionB.'/content-review',
                ['target' => 'under_review'],
            )
            ->assertNotFound();

        self::assertSame(
            'ready_for_review',
            $this->latestState($versionB),
        );
    }

    public function test_active_membership_without_operations_capability_remains_denied(): void
    {
        [$owner, $business, $ownerMembership] =
            $this->ownerBusiness(
                'operations-owner@example.test',
                'Operations Unauthorized',
            );

        $versionId = $this->readyForReview(
            $owner,
            $business,
            $ownerMembership,
        );

        $user = $this->activeUser(
            'operations-no-capability@example.test',
        );

        Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => MembershipAccessStatus::Active,
        ]);

        $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->post(
                '/operations/register/'.$versionId.'/content-review',
                ['target' => 'under_review'],
            )
            ->assertNotFound();

        self::assertSame(
            'ready_for_review',
            $this->latestState($versionId),
        );
    }

    /**
     * @return array{User,Business,Membership}
     */
    private function ownerBusiness(
        string $email,
        string $name,
    ): array {
        $user = $this->activeUser($email);
        [$business, $membership] = $this->businessForOwner(
            $user,
            $name,
        );

        return [$user, $business, $membership];
    }

    /**
     * @return array{Business,Membership}
     */
    private function businessForOwner(
        User $user,
        string $name,
    ): array {
        $business = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $user,
                $name,
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        $membership = Membership::query()
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->sole();

        return [$business, $membership];
    }

    private function readyForReview(
        User $user,
        Business $business,
        Membership $membership,
    ): string {
        $workflow = $this->app->make(
            OperationsRegisterWorkflow::class,
        );

        $created = $workflow->createDraft(
            $user,
            $business,
            $this->operationsPayload(
                (string) $membership->getKey(),
            ),
            now()->subMinute(),
        );

        self::assertNotNull($created);

        $submitted = $workflow->submitForGovernance(
            $user,
            $business,
            $created['formal_record_version_id'],
            1,
        );

        self::assertNotNull($submitted);

        return $created['formal_record_version_id'];
    }

    /**
     * @return array<string,mixed>
     */
    private function operationsPayload(
        string $membershipId,
    ): array {
        return [
            'organization_name' => 'Operations HTTP Fixture',
            'notes' => 'Content-review HTTP regression.',
            'roles' => [[
                'role_key' => 'operations_lead',
                'name' => 'Operations Lead',
                'function_name' => 'Operations',
                'purpose' => 'Own operating delivery.',
                'responsibilities' => 'Plan, coordinate, report and close work.',
                'operational_authority' => 'Coordinate approved operating work only.',
                'reports_to_role_key' => null,
                'report_type' => 'Operating update',
                'reporting_frequency' => 'Weekly',
                'meeting_frequency' => 'Weekly',
                'review_frequency' => 'Quarterly',
                'assignments' => [[
                    'membership_id' => $membershipId,
                    'assignment_type' => 'primary',
                ]],
            ]],
            'raci' => [],
            'kpis' => [],
        ];
    }

    private function latestState(string $versionId): ?string
    {
        $state = DB::table(
            'record_version_state_transitions',
        )
            ->where(
                'formal_record_version_id',
                $versionId,
            )
            ->orderByDesc('sequence')
            ->value('to_state');

        return $state === null ? null : (string) $state;
    }

    private function transitionCount(
        string $versionId,
        string $toState,
    ): int {
        return DB::table(
            'record_version_state_transitions',
        )
            ->where(
                'formal_record_version_id',
                $versionId,
            )
            ->where('to_state', $toState)
            ->count();
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
