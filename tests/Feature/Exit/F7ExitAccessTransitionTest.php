<?php

declare(strict_types=1);

namespace Tests\Feature\Exit;

use App\Application\Access\ResolveMembershipCapabilities;
use App\Application\Access\TransitionMembershipAccess;
use App\Application\Businesses\CreateBusiness;
use App\Application\Exit\ExitCaseWorkflow;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Application\Partnership\PartnerDirectory;
use App\Domain\Access\Enums\StandardAccessProfile;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Exit\Enums\ExitTrigger;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class F7ExitAccessTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_suspension_is_explicit_and_does_not_change_partner_or_ownership_truth(): void
    {
        [$owner, $business] = $this->fixture(
            'f7-exit-access-owner@example.test',
            'F7 Exit Access Business',
        );

        [$memberUser, $member] = $this->secondaryMembership(
            $business,
            'f7-exit-access-partner@example.test',
        );

        $partnerId = $this->seedActivePartner(
            $business,
            'Access Transition Partner',
        );

        self::assertNotNull(
            $this->app->make(PartnerDirectory::class)->linkMembership(
                $owner,
                $business,
                $partnerId,
                (string) $member->getKey(),
            ),
        );

        $case = $this->app->make(ExitCaseWorkflow::class)->createCase(
            $owner,
            $business,
            $partnerId,
            ExitTrigger::Misconduct,
            'partner_exit_approval',
            'Security review is separate from Exit effectivity.',
        );

        self::assertNotNull($case);
        self::assertSame(
            MembershipAccessStatus::Active,
            $member->fresh()->access_status,
        );
        self::assertSame(
            'active',
            DB::table('partners')->where('id', $partnerId)->value('status'),
        );

        $ownershipRowsBefore = DB::table('ownership_register_positions')
            ->where('business_id', $business->getKey())
            ->count();

        self::assertTrue(
            $this->app->make(TransitionMembershipAccess::class)->execute(
                $owner,
                $business,
                (string) $member->getKey(),
                MembershipAccessStatus::Active,
                MembershipAccessStatus::Suspended,
                'security_suspension_pending_exit_review',
                'exit_case',
                (string) $case->getKey(),
            ),
        );

        self::assertSame(
            MembershipAccessStatus::Suspended,
            $member->fresh()->access_status,
        );
        self::assertSame(
            'active',
            DB::table('partners')->where('id', $partnerId)->value('status'),
            'A security suspension must not itself change Partner lifecycle truth.',
        );
        self::assertSame(
            $ownershipRowsBefore,
            DB::table('ownership_register_positions')
                ->where('business_id', $business->getKey())
                ->count(),
            'System access transition must not mutate Ownership truth.',
        );

        self::assertDatabaseHas('membership_access_transitions', [
            'business_id' => $business->getKey(),
            'membership_id' => $member->getKey(),
            'from_status' => 'active',
            'to_status' => 'suspended',
            'source_type' => 'exit_case',
            'source_id' => $case->getKey(),
        ]);

        self::assertNull(
            $this->app
                ->make(ResolveMembershipCapabilities::class)
                ->activeMembership($memberUser, $business),
        );
    }

    public function test_last_active_workspace_owner_cannot_be_suspended_or_revoked(): void
    {
        [$owner, $business] = $this->fixture(
            'f7-exit-last-owner@example.test',
            'F7 Exit Last Owner Business',
        );

        $ownerMembership = Membership::query()
            ->where('business_id', $business->getKey())
            ->where('user_id', $owner->getKey())
            ->sole();

        $workspaceOwnerProfile = DB::table('permission_profiles')
            ->where('business_id', $business->getKey())
            ->where('name', StandardAccessProfile::WorkspaceOwner->value)
            ->value('id');

        self::assertNotNull($workspaceOwnerProfile);
        self::assertTrue(
            DB::table('membership_permission_profiles')
                ->where('business_id', $business->getKey())
                ->where('membership_id', $ownerMembership->getKey())
                ->where('permission_profile_id', $workspaceOwnerProfile)
                ->exists(),
        );

        $partnerId = $this->seedActivePartner(
            $business,
            'Workspace Owner Partner',
        );

        self::assertNotNull(
            $this->app->make(PartnerDirectory::class)->linkMembership(
                $owner,
                $business,
                $partnerId,
                (string) $ownerMembership->getKey(),
            ),
        );

        $case = $this->app->make(ExitCaseWorkflow::class)->createCase(
            $owner,
            $business,
            $partnerId,
            ExitTrigger::Retirement,
            'partner_exit_approval',
        );

        self::assertNotNull($case);

        foreach ([
            MembershipAccessStatus::Suspended,
            MembershipAccessStatus::Revoked,
        ] as $target) {
            try {
                $this->app->make(TransitionMembershipAccess::class)->execute(
                    $owner,
                    $business,
                    (string) $ownerMembership->getKey(),
                    MembershipAccessStatus::Active,
                    $target,
                    'workspace_owner_exit_safety',
                    'exit_case',
                    (string) $case->getKey(),
                );
                self::fail(
                    'Last active Workspace Owner must retain a recovery path.',
                );
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString(
                    'last active Workspace Owner',
                    $exception->getMessage(),
                );
            }
        }

        self::assertSame(
            MembershipAccessStatus::Active,
            $ownerMembership->fresh()->access_status,
        );
    }

    public function test_cross_business_membership_identifier_fails_closed(): void
    {
        [$ownerA, $businessA] = $this->fixture(
            'f7-exit-tenant-a@example.test',
            'F7 Exit Tenant A',
        );
        [, $businessB] = $this->fixture(
            'f7-exit-tenant-b@example.test',
            'F7 Exit Tenant B',
        );

        [, $memberB] = $this->secondaryMembership(
            $businessB,
            'f7-exit-tenant-b-member@example.test',
        );

        $result = $this->app->make(TransitionMembershipAccess::class)->execute(
            $ownerA,
            $businessA,
            (string) $memberB->getKey(),
            MembershipAccessStatus::Active,
            MembershipAccessStatus::Suspended,
            'cross_business_attempt',
        );

        self::assertFalse($result);
        self::assertSame(
            MembershipAccessStatus::Active,
            $memberB->fresh()->access_status,
        );
        self::assertDatabaseMissing('membership_access_transitions', [
            'membership_id' => $memberB->getKey(),
            'business_id' => $businessA->getKey(),
        ]);
    }

    private function seedActivePartner(Business $business, string $name): string
    {
        $id = (string) Str::uuid7();

        DB::table('partners')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'display_name' => $name,
            'legal_name' => null,
            'email' => null,
            'status' => 'active',
            'notes' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array{User,Membership} */
    private function secondaryMembership(
        Business $business,
        string $email,
    ): array {
        $user = User::query()->create([
            'email' => $email,
            'password' => Hash::make('test-password'),
            'status' => 'active',
            'password_changed_at' => now(),
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        return [$user, $membership];
    }

    /** @return array{User,Business} */
    private function fixture(string $email, string $businessName): array
    {
        $this->app->make(ProvisionAccount::class)->handle(
            email: $email,
            displayName: 'F7 Exit Access Tester',
            password: 'F7-Exit-Access-2026',
            languageMode: LanguageMode::English,
            timezone: 'UTC',
            actorLabel: 'F7 Exit Access Test',
            reason: 'F7 Exit access fixture',
            source: 'test',
        );

        $this->app->make(ChangeAccountStatus::class)->handle(
            email: $email,
            targetStatus: AccountStatus::Active,
            actorLabel: 'F7 Exit Access Test',
            reason: 'Activate F7 Exit access fixture',
            source: 'test',
        );

        $user = User::query()->where('email', $email)->sole();

        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            $businessName,
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        return [$user, $business];
    }
}
