<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Application\Access\CreateBusinessAccessInvitation;
use App\Application\Access\RedeemBusinessAccessInvitation;
use App\Application\Access\RevokeBusinessAccessInvitation;
use App\Application\Businesses\CreateBusiness;
use App\Application\Identity\HasBusinessCreationEntitlement;
use App\Application\Partnership\PartnerDirectory;
use App\Domain\Access\Enums\StandardAccessProfile;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionProfile;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class BusinessAccessInvitationAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_admission_has_no_self_registration_route_and_uses_invitation_routes(): void
    {
        self::assertFalse(Route::has('register'));
        self::assertTrue(Route::has('access.invitations.redeem.show'));
        self::assertTrue(Route::has('access.invitations.redeem.store'));
        self::assertTrue(Route::has('workspace.access.invitations.store'));
        self::assertTrue(Route::has('workspace.access.invitations.revoke'));
    }

    public function test_authorized_invitation_redeems_once_into_membership_and_system_profile_only(): void
    {
        $owner = $this->activeUser('invite-owner@example.test');
        $business = $this->business($owner, 'Invite-only Business');
        $profile = $this->profile(
            $business->getKey(),
            StandardAccessProfile::AuditorViewer,
        );

        $invitation = $this->app
            ->make(CreateBusinessAccessInvitation::class)
            ->execute(
                $owner,
                $business,
                'approved-user@example.test',
                (string) $profile->getKey(),
                24,
            );

        self::assertNotNull($invitation);
        self::assertSame(48, strlen($invitation['token']));
        self::assertFalse(
            Schema::hasColumn('business_access_invitations', 'token'),
        );

        $storedInvitation = DB::table('business_access_invitations')
            ->where('id', $invitation['id'])
            ->first();

        self::assertNotNull($storedInvitation);
        self::assertSame(
            hash('sha256', $invitation['token']),
            $storedInvitation->token_fingerprint,
        );
        self::assertSame('pending', $storedInvitation->status);

        $result = $this->app
            ->make(RedeemBusinessAccessInvitation::class)
            ->execute(
                $invitation['token'],
                'approved-user@example.test',
                null,
                'Approved User',
                'strong-password-123',
                LanguageMode::English,
                'Asia/Yangon',
            );

        self::assertSame(
            AccountStatus::Active,
            $result['user']->status,
        );
        self::assertFalse(
            $this->app
                ->make(HasBusinessCreationEntitlement::class)
                ->handle($result['user']),
        );
        self::assertSame(
            (string) $business->getKey(),
            (string) $result['membership']->business_id,
        );

        $this->assertDatabaseHas('membership_permission_profiles', [
            'business_id' => $business->getKey(),
            'membership_id' => $result['membership']->getKey(),
            'permission_profile_id' => $profile->getKey(),
        ]);

        $this->assertDatabaseHas('business_access_invitations', [
            'id' => $invitation['id'],
            'status' => 'redeemed',
            'redeemed_by_user_id' => $result['user']->getKey(),
            'redeemed_membership_id' => $result['membership']->getKey(),
        ]);

        $this->assertDatabaseCount('partners', 0);
        $this->assertDatabaseCount('partner_membership_links', 0);
        $this->assertDatabaseCount('ownership_scenarios', 0);
        $this->assertDatabaseCount('ownership_registers', 0);
        $this->assertDatabaseCount('decisions', 0);
        $this->assertDatabaseCount('authority_snapshots', 0);
        $this->assertDatabaseCount('document_access_grants', 0);

        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'access.invitation.created',
            'target_id' => $invitation['id'],
        ]);
        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'access.invitation.redeemed',
            'target_id' => $invitation['id'],
        ]);
        $this->assertDatabaseHas('business_events', [
            'business_id' => $business->getKey(),
            'event_type' => 'access.invitation.redeemed',
            'aggregate_id' => $invitation['id'],
        ]);

        $usersAfterRedemption = DB::table('users')->count();
        $membershipsAfterRedemption = DB::table('memberships')->count();

        try {
            $this->app
                ->make(RedeemBusinessAccessInvitation::class)
                ->execute(
                    $invitation['token'],
                    'approved-user@example.test',
                    $result['user'],
                    '',
                    '',
                    LanguageMode::English,
                    'Asia/Yangon',
                );

            self::fail('A redeemed invitation must not be reusable.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Invitation is invalid or unavailable.',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            $usersAfterRedemption,
            DB::table('users')->count(),
        );
        self::assertSame(
            $membershipsAfterRedemption,
            DB::table('memberships')->count(),
        );
    }

    public function test_revoked_or_invalid_invitation_cannot_admit_a_user(): void
    {
        $owner = $this->activeUser('revoke-owner@example.test');
        $business = $this->business($owner, 'Revocation Business');
        $profile = $this->profile(
            $business->getKey(),
            StandardAccessProfile::AdvisorConsultant,
        );

        $invitation = $this->app
            ->make(CreateBusinessAccessInvitation::class)
            ->execute(
                $owner,
                $business,
                'revoked-user@example.test',
                (string) $profile->getKey(),
                24,
            );

        self::assertNotNull($invitation);

        $revoked = $this->app
            ->make(RevokeBusinessAccessInvitation::class)
            ->execute(
                $owner,
                $business,
                $invitation['id'],
            );

        self::assertTrue($revoked);

        $this->assertDatabaseHas('business_access_invitations', [
            'id' => $invitation['id'],
            'status' => 'revoked',
        ]);

        $usersBefore = DB::table('users')->count();
        $membershipsBefore = DB::table('memberships')->count();

        foreach (
            [
                $invitation['token'],
                str_repeat('A', 48),
            ] as $invalidToken
        ) {
            try {
                $this->app
                    ->make(RedeemBusinessAccessInvitation::class)
                    ->execute(
                        $invalidToken,
                        'revoked-user@example.test',
                        null,
                        'Blocked User',
                        'strong-password-123',
                        LanguageMode::English,
                        'UTC',
                    );

                self::fail('Invalid access must not admit a user.');
            } catch (InvalidArgumentException) {
                // Expected: no admission side effect.
            }
        }

        self::assertSame($usersBefore, DB::table('users')->count());
        self::assertSame(
            $membershipsBefore,
            DB::table('memberships')->count(),
        );
        self::assertFalse(
            $this->app
                ->make(RevokeBusinessAccessInvitation::class)
                ->execute(
                    $owner,
                    $business,
                    $invitation['id'],
                ),
        );
    }

    public function test_access_admin_cannot_create_or_revoke_across_business_tenant_boundary(): void
    {
        $ownerA = $this->activeUser('owner-a@example.test');
        $ownerB = $this->activeUser('owner-b@example.test');
        $businessA = $this->business($ownerA, 'Business A');
        $businessB = $this->business($ownerB, 'Business B');

        $profileA = $this->profile(
            $businessA->getKey(),
            StandardAccessProfile::AuditorViewer,
        );
        $profileB = $this->profile(
            $businessB->getKey(),
            StandardAccessProfile::AuditorViewer,
        );

        $create = $this->app->make(
            CreateBusinessAccessInvitation::class,
        );

        self::assertNull(
            $create->execute(
                $ownerA,
                $businessB,
                'cross-tenant@example.test',
                (string) $profileB->getKey(),
            ),
        );

        self::assertNull(
            $create->execute(
                $ownerA,
                $businessA,
                'wrong-profile@example.test',
                (string) $profileB->getKey(),
            ),
        );

        $invitationB = $create->execute(
            $ownerB,
            $businessB,
            'business-b-user@example.test',
            (string) $profileB->getKey(),
        );

        self::assertNotNull($invitationB);

        self::assertNull(
            $this->app
                ->make(RevokeBusinessAccessInvitation::class)
                ->execute(
                    $ownerA,
                    $businessB,
                    $invitationB['id'],
                ),
        );

        $this->assertDatabaseHas('business_access_invitations', [
            'id' => $invitationB['id'],
            'business_id' => $businessB->getKey(),
            'status' => 'pending',
        ]);

        self::assertSame(
            1,
            DB::table('business_access_invitations')
                ->where('business_id', $businessB->getKey())
                ->count(),
        );

        self::assertSame(
            0,
            DB::table('business_access_invitations')
                ->where('business_id', $businessA->getKey())
                ->count(),
        );

        self::assertNotSame(
            (string) $profileA->getKey(),
            (string) $profileB->getKey(),
        );
    }

    public function test_partner_invitation_links_identity_without_promoting_partner_or_creating_rights(): void
    {
        $owner = $this->activeUser('partner-invite-owner@example.test');
        $business = $this->business($owner, 'Partner Invite Business');

        $directory = $this->app->make(PartnerDirectory::class);

        $partner = $directory->create(
            $owner,
            $business,
            'Prospective Partner',
            null,
            'prospective@example.test',
            null,
        );

        self::assertNotNull($partner);

        $invitation = $directory->invite(
            $owner,
            $business,
            $partner['id'],
            'prospective@example.test',
        );

        self::assertNotNull($invitation);

        $result = $this->app
            ->make(RedeemBusinessAccessInvitation::class)
            ->execute(
                $invitation['token'],
                'prospective@example.test',
                null,
                'Prospective User',
                'strong-password-123',
                LanguageMode::Myanmar,
                'Asia/Yangon',
            );

        $this->assertDatabaseHas('partners', [
            'id' => $partner['id'],
            'business_id' => $business->getKey(),
            'status' => 'prospective',
        ]);

        $this->assertDatabaseHas('partner_membership_links', [
            'business_id' => $business->getKey(),
            'partner_id' => $partner['id'],
            'membership_id' => $result['membership']->getKey(),
        ]);

        $partnerProfile = $this->profile(
            $business->getKey(),
            StandardAccessProfile::Partner,
        );

        $this->assertDatabaseHas('membership_permission_profiles', [
            'business_id' => $business->getKey(),
            'membership_id' => $result['membership']->getKey(),
            'permission_profile_id' => $partnerProfile->getKey(),
        ]);

        $this->assertDatabaseCount('ownership_scenarios', 0);
        $this->assertDatabaseCount('ownership_registers', 0);
        $this->assertDatabaseCount('votes', 0);
        $this->assertDatabaseCount('approvals', 0);
        $this->assertDatabaseCount('document_access_grants', 0);
    }

    private function activeUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => 'not-used-by-direct-test',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);
    }

    private function business(
        User $owner,
        string $name,
    ): Business {
        return $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $owner,
                $name,
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );
    }

    private function profile(
        string $businessId,
        StandardAccessProfile $profile,
    ): PermissionProfile {
        return PermissionProfile::query()
            ->where('business_id', $businessId)
            ->where('name', $profile->value)
            ->firstOrFail();
    }
}
