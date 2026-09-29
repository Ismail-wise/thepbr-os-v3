<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Application\Businesses\CreateBusiness;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Domain\Records\Enums\FormalRecordState;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FormationAuthorityBootstrapHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_fresh_business_can_prepare_freeze_and_establish_explicit_temporary_formation_authority(): void
    {
        [$user, $business, $membership] = $this->ownerBusiness(
            'formation-bootstrap@example.test',
            'Formation Bootstrap',
        );

        $session = [
            EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
        ];

        $this
            ->actingAs($user)
            ->withSession($session)
            ->from('/governance/rules')
            ->post(
                '/governance/rules/formation-authority',
                $this->policyPayload(
                    (string) $membership->getKey(),
                ),
            )
            ->assertRedirect('/governance/rules')
            ->assertSessionHasNoErrors();

        $family = FormalRecordFamily::query()
            ->where('business_id', $business->getKey())
            ->where('record_type', 'formation_authority_policy')
            ->sole();

        $version = FormalRecordVersion::query()
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_family_id',
                $family->getKey(),
            )
            ->sole();

        self::assertNull($version->frozen_at);
        self::assertSame(1, (int) $version->revision);
        self::assertSame(
            'draft',
            $this->latestState((string) $version->getKey()),
        );

        $this->assertDatabaseHas(
            'formation_authority_policy_rules',
            [
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'decision_type' => 'general_management',
                'decision_method' => 'approval',
                'required_approvals' => 1,
                'required_votes' => 0,
                'quorum_count' => 1,
                'reserved_matter' => true,
            ],
        );

        $ruleId = (string) DB::table(
            'formation_authority_policy_rules',
        )
            ->where(
                'formal_record_version_id',
                $version->getKey(),
            )
            ->value('id');

        $this->assertDatabaseHas(
            'formation_authority_policy_actors',
            [
                'business_id' => $business->getKey(),
                'formation_authority_policy_rule_id' => $ruleId,
                'membership_id' => $membership->getKey(),
                'capacity' => 'Formation Approver',
                'can_approve' => true,
            ],
        );

        $this
            ->actingAs($user)
            ->withSession($session)
            ->from('/governance/rules')
            ->post(
                '/governance/rules/formation-authority/'
                    .$version->getKey().'/freeze',
                ['expected_revision' => 1],
            )
            ->assertRedirect('/governance/rules')
            ->assertSessionHasNoErrors();

        $version->refresh();

        self::assertNotNull($version->frozen_at);
        self::assertSame(
            'ready_for_review',
            $this->latestState((string) $version->getKey()),
        );

        $this
            ->actingAs($user)
            ->withSession($session)
            ->from('/governance/rules')
            ->post(
                '/governance/rules/formation-authority/'
                    .$version->getKey().'/establish',
            )
            ->assertRedirect('/governance/rules')
            ->assertSessionHasNoErrors();

        $establishment = DB::table(
            'formation_authority_establishments',
        )
            ->where('business_id', $business->getKey())
            ->sole();

        self::assertSame(
            (string) $version->getKey(),
            (string) $establishment->formal_record_version_id,
        );
        self::assertSame(
            (string) $version->content_hash,
            (string) $establishment->establishment_hash,
        );

        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'governance.formation_authority.established',
            'target_type' => 'formation_authority_establishment',
            'target_id' => $establishment->id,
        ]);

        $this->assertDatabaseHas('business_events', [
            'business_id' => $business->getKey(),
            'event_type' => 'governance.formation_authority.established',
            'aggregate_type' => 'formation_authority_establishment',
            'aggregate_id' => $establishment->id,
        ]);

        $this
            ->actingAs($user)
            ->withSession($session)
            ->get('/governance')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where(
                        'governance.authority.authority_mode',
                        'bootstrap',
                    ),
            );
    }

    public function test_establishment_requires_exact_frozen_ready_for_review_policy(): void
    {
        [$user, $business, $membership] = $this->ownerBusiness(
            'formation-state@example.test',
            'Formation State',
        );

        $version = $this->createPolicyThroughHttp(
            $user,
            $business,
            $membership,
        );

        $session = [
            EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
        ];

        $this
            ->actingAs($user)
            ->withSession($session)
            ->from('/governance/rules')
            ->post(
                '/governance/rules/formation-authority/'
                    .$version->getKey().'/establish',
            )
            ->assertRedirect('/governance/rules')
            ->assertSessionHasErrors('formation_authority');

        $this->assertDatabaseCount(
            'formation_authority_establishments',
            0,
        );

        $this
            ->actingAs($user)
            ->withSession($session)
            ->post(
                '/governance/rules/formation-authority/'
                    .$version->getKey().'/freeze',
                ['expected_revision' => 1],
            )
            ->assertRedirect();

        $transitioned = $this->app
            ->make(TransitionFormalRecordVersion::class)
            ->execute(
                $user,
                $business,
                new Capability(CapabilityCatalog::RECORDS_MANAGE),
                (string) $version->getKey(),
                FormalRecordState::UnderReview,
            );

        self::assertNotNull($transitioned);
        self::assertSame(
            'under_review',
            $this->latestState((string) $version->getKey()),
        );

        $this
            ->actingAs($user)
            ->withSession($session)
            ->from('/governance/rules')
            ->post(
                '/governance/rules/formation-authority/'
                    .$version->getKey().'/establish',
            )
            ->assertRedirect('/governance/rules')
            ->assertSessionHasErrors('formation_authority');

        $this->assertDatabaseCount(
            'formation_authority_establishments',
            0,
        );
    }

    public function test_foreign_business_actor_and_policy_are_denied_without_cross_tenant_leakage(): void
    {
        $user = $this->activeUser(
            'formation-isolation@example.test',
        );

        [$businessA, $membershipA] = $this->businessForOwner(
            $user,
            'Formation Business A',
        );
        [$businessB, $membershipB] = $this->businessForOwner(
            $user,
            'Formation Business B',
        );

        $sessionA = [
            EnsureCurrentBusinessContext::SESSION_KEY => (string) $businessA->getKey(),
        ];

        $this
            ->actingAs($user)
            ->withSession($sessionA)
            ->from('/governance/rules')
            ->post(
                '/governance/rules/formation-authority',
                $this->policyPayload(
                    (string) $membershipB->getKey(),
                ),
            )
            ->assertRedirect('/governance/rules')
            ->assertSessionHasErrors('formation_authority');

        $this->assertDatabaseMissing(
            'formal_record_families',
            [
                'business_id' => $businessA->getKey(),
                'record_type' => 'formation_authority_policy',
            ],
        );

        $versionB = $this->createPolicyThroughHttp(
            $user,
            $businessB,
            $membershipB,
        );

        $sessionB = [
            EnsureCurrentBusinessContext::SESSION_KEY => (string) $businessB->getKey(),
        ];

        $this
            ->actingAs($user)
            ->withSession($sessionB)
            ->post(
                '/governance/rules/formation-authority/'
                    .$versionB->getKey().'/freeze',
                ['expected_revision' => 1],
            )
            ->assertRedirect();

        $this
            ->actingAs($user)
            ->withSession($sessionA)
            ->post(
                '/governance/rules/formation-authority/'
                    .$versionB->getKey().'/establish',
            )
            ->assertNotFound();

        $this->assertDatabaseMissing(
            'formation_authority_establishments',
            [
                'business_id' => $businessA->getKey(),
            ],
        );

        self::assertNotSame(
            (string) $membershipA->getKey(),
            (string) $membershipB->getKey(),
        );
    }

    public function test_active_membership_without_bootstrap_capability_cannot_use_bootstrap_route(): void
    {
        [$owner, $business] = $this->ownerBusiness(
            'formation-owner@example.test',
            'Formation Unauthorized',
        );

        $user = $this->activeUser(
            'formation-no-capability@example.test',
        );

        $membership = Membership::query()->create([
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
                '/governance/rules/formation-authority',
                $this->policyPayload(
                    (string) $membership->getKey(),
                ),
            )
            ->assertNotFound();

        $this->assertDatabaseMissing(
            'formal_record_families',
            [
                'business_id' => $business->getKey(),
                'record_type' => 'formation_authority_policy',
            ],
        );

        self::assertNotSame(
            (string) $owner->getKey(),
            (string) $user->getKey(),
        );
    }

    public function test_effective_governance_closes_bootstrap_policy_preparation_path(): void
    {
        [$user, $business, $membership] = $this->ownerBusiness(
            'formation-effective@example.test',
            'Formation Effective Governance',
        );

        $this->seedEffectiveGovernanceCharter(
            $business,
            $user,
            $membership,
        );

        $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->from('/governance/rules')
            ->post(
                '/governance/rules/formation-authority',
                $this->policyPayload(
                    (string) $membership->getKey(),
                ),
            )
            ->assertRedirect('/governance/rules')
            ->assertSessionHasErrors('formation_authority');

        $this->assertDatabaseMissing(
            'formal_record_families',
            [
                'business_id' => $business->getKey(),
                'record_type' => 'formation_authority_policy',
            ],
        );

        $this->assertDatabaseCount(
            'formation_authority_establishments',
            0,
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

    private function createPolicyThroughHttp(
        User $user,
        Business $business,
        Membership $membership,
    ): FormalRecordVersion {
        $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->post(
                '/governance/rules/formation-authority',
                $this->policyPayload(
                    (string) $membership->getKey(),
                ),
            )
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $family = FormalRecordFamily::query()
            ->where('business_id', $business->getKey())
            ->where('record_type', 'formation_authority_policy')
            ->sole();

        return FormalRecordVersion::query()
            ->where(
                'formal_record_family_id',
                $family->getKey(),
            )
            ->sole();
    }

    /**
     * @return array<string,mixed>
     */
    private function policyPayload(
        string $membershipId,
    ): array {
        return [
            'effective_from' => now()->toDateString(),
            'rules' => [[
                'decision_type' => 'general_management',
                'decision_method' => 'approval',
                'required_approvals' => 1,
                'required_votes' => 0,
                'quorum_count' => 1,
                'signature_required' => false,
                'reserved_matter' => true,
                'amount_min' => null,
                'amount_max' => null,
                'actors' => [[
                    'membership_id' => $membershipId,
                    'capacity' => 'Formation Approver',
                    'can_approve' => true,
                    'can_vote' => false,
                    'can_sign' => false,
                ]],
            ]],
        ];
    }

    private function latestState(
        string $formalRecordVersionId,
    ): ?string {
        $state = DB::table(
            'record_version_state_transitions',
        )
            ->where(
                'formal_record_version_id',
                $formalRecordVersionId,
            )
            ->orderByDesc('sequence')
            ->value('to_state');

        return $state === null ? null : (string) $state;
    }

    private function seedEffectiveGovernanceCharter(
        Business $business,
        User $user,
        Membership $membership,
    ): void {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'governance_charter',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Effective Governance fixture.',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('a', 64),
            'frozen_at' => null,
        ]);
        DB::table(
            'record_version_state_transitions',
        )->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'from_state' => null,
            'to_state' => 'draft',
            'transitioned_by_user_id' => $user->getKey(),
            'occurred_at' => now(),
        ]);

        DB::table('governance_charter_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'governance_owner_membership_id' => $membership->getKey(),
            'voting_basis' => 'One eligible participant, one vote',
            'default_approval_rule' => 'Exact authority rule',
            'meeting_frequency' => 'Monthly',
            'default_quorum_count' => 1,
            'minutes_owner_membership_id' => $membership->getKey(),
            'conflict_of_interest_rule' => 'Disclose and recuse.',
            'deadlock_rule' => 'Escalate under approved process.',
            'remote_voting_allowed' => true,
            'written_resolution_allowed' => true,
            'created_at' => now(),
        ]);

        $version->frozen_at = now();
        $version->save();

        $states = [
            ['draft', 'ready_for_review'],
            ['ready_for_review', 'under_review'],
            ['under_review', 'approved'],
            ['approved', 'ready_for_effect'],
            ['ready_for_effect', 'effective'],
        ];

        foreach ($states as $index => [$from, $to]) {
            DB::table(
                'record_version_state_transitions',
            )->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => $index + 2,
                'from_state' => $from,
                'to_state' => $to,
                'transitioned_by_user_id' => $user->getKey(),
                'occurred_at' => now(),
            ]);
        }

        RecordFamilyEffectiveHead::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'activated_at' => now(),
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
}
