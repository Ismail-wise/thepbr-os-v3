<?php

declare(strict_types=1);

namespace Tests\Feature\PartnerChanges;

use App\Application\Businesses\CreateBusiness;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Application\Partnership\PartnerDirectory;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\Enums\StandardAccessProfile;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionProfile;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7PartnerChangeSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_partner_profile_can_view_but_cannot_manage_partner_changes(): void
    {
        [$owner, $business] = $this->fixture(
            'f7-security-owner@example.test',
            'F7 Security Business',
        );

        $incoming = $this->createPartner(
            $owner,
            $business,
            'Security Incoming Partner',
        );

        $viewer = User::query()->create([
            'email' => 'f7-security-viewer@example.test',
            'password' => Hash::make('test-password'),
            'status' => 'active',
            'password_changed_at' => now(),
        ]);

        $viewerMembership = Membership::query()->create([
            'user_id' => $viewer->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        $partnerProfile = PermissionProfile::query()
            ->where('business_id', $business->getKey())
            ->where('name', StandardAccessProfile::Partner->value)
            ->sole();

        DB::table('membership_permission_profiles')->insert([
            'business_id' => $business->getKey(),
            'membership_id' => $viewerMembership->getKey(),
            'permission_profile_id' => $partnerProfile->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this
            ->actingAs($viewer)
            ->withSession(['current_business_id' => $business->getKey()])
            ->get('/changes/partner-changes')
            ->assertOk();

        $this
            ->actingAs($viewer)
            ->withSession(['current_business_id' => $business->getKey()])
            ->post('/changes/partner-changes', [
                'transaction_type' => 'admission',
                'buyer_partner_id' => $incoming,
                'governance_decision_type' => 'partner_change_approval',
                'rofr_required' => false,
            ])
            ->assertNotFound();

        self::assertSame(
            0,
            DB::table('partner_change_cases')
                ->where('business_id', $business->getKey())
                ->count(),
        );
    }

    public function test_explicit_manage_deny_overrides_workspace_owner_profile_allow(): void
    {
        [$owner, $business] = $this->fixture(
            'f7-security-deny@example.test',
            'F7 Security Deny Business',
        );

        $incoming = $this->createPartner(
            $owner,
            $business,
            'Deny Incoming Partner',
        );

        $ownerMembership = Membership::query()
            ->where('business_id', $business->getKey())
            ->where('user_id', $owner->getKey())
            ->sole();

        $permission = Permission::query()
            ->where('key', CapabilityCatalog::PARTNER_CHANGES_MANAGE)
            ->sole();

        PermissionGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $ownerMembership->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => PermissionEffect::Deny,
        ]);

        $this
            ->actingAs($owner)
            ->withSession(['current_business_id' => $business->getKey()])
            ->get('/changes/partner-changes')
            ->assertOk();

        $this
            ->actingAs($owner)
            ->withSession(['current_business_id' => $business->getKey()])
            ->post('/changes/partner-changes', [
                'transaction_type' => 'admission',
                'buyer_partner_id' => $incoming,
                'governance_decision_type' => 'partner_change_approval',
                'rofr_required' => false,
            ])
            ->assertNotFound();
    }

    public function test_cross_business_partner_id_fails_closed_without_case_creation(): void
    {
        [$owner, $businessA] = $this->fixture(
            'f7-security-cross@example.test',
            'F7 Security Business A',
        );

        $businessB = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $owner,
                'F7 Security Business B',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        $foreignPartner = $this->createPartner(
            $owner,
            $businessB,
            'Foreign Business Partner',
        );

        $payload = [
            'transaction_type' => 'admission',
            'governance_decision_type' => 'partner_change_approval',
            'rofr_required' => false,
        ];

        $this
            ->actingAs($owner)
            ->withSession(['current_business_id' => $businessA->getKey()])
            ->post('/changes/partner-changes', [
                ...$payload,
                'buyer_partner_id' => $foreignPartner,
            ])
            ->assertNotFound();

        $this
            ->actingAs($owner)
            ->withSession(['current_business_id' => $businessA->getKey()])
            ->post('/changes/partner-changes', [
                ...$payload,
                'buyer_partner_id' => (string) Str::uuid(),
            ])
            ->assertNotFound();

        self::assertSame(
            0,
            DB::table('partner_change_cases')
                ->where('business_id', $businessA->getKey())
                ->count(),
        );
    }

    public function test_cross_business_case_id_and_nonexistent_case_id_fail_with_same_validation_surface(): void
    {
        [$owner, $businessA] = $this->fixture(
            'f7-security-case-cross@example.test',
            'F7 Case Security A',
        );

        $businessB = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $owner,
                'F7 Case Security B',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        $partnerB = $this->createPartner(
            $owner,
            $businessB,
            'Case B Incoming',
        );

        $this
            ->actingAs($owner)
            ->withSession(['current_business_id' => $businessB->getKey()])
            ->post('/changes/partner-changes', [
                'transaction_type' => 'admission',
                'buyer_partner_id' => $partnerB,
                'governance_decision_type' => 'partner_change_approval',
                'rofr_required' => false,
            ])
            ->assertRedirect();

        $caseB = DB::table('partner_change_cases')
            ->where('business_id', $businessB->getKey())
            ->sole();

        $cross = $this
            ->actingAs($owner)
            ->withSession(['current_business_id' => $businessA->getKey()])
            ->from('/changes/partner-changes')
            ->post(
                '/changes/partner-changes/'.$caseB->id.'/transition',
                [
                    'expected_revision' => 1,
                    'target' => 'eligibility_review',
                ],
            );

        $missing = $this
            ->actingAs($owner)
            ->withSession(['current_business_id' => $businessA->getKey()])
            ->from('/changes/partner-changes')
            ->post(
                '/changes/partner-changes/'.Str::uuid().'/transition',
                [
                    'expected_revision' => 1,
                    'target' => 'eligibility_review',
                ],
            );

        $cross->assertRedirect('/changes/partner-changes')
            ->assertSessionHasErrors('partner_change');

        $missing->assertRedirect('/changes/partner-changes')
            ->assertSessionHasErrors('partner_change');

        self::assertSame(
            'draft',
            DB::table('partner_change_cases')
                ->where('id', $caseB->id)
                ->value('status'),
        );
    }

    private function createPartner(
        User $user,
        Business $business,
        string $name,
    ): string {
        $partner = $this->app
            ->make(PartnerDirectory::class)
            ->create(
                $user,
                $business,
                $name,
                null,
                null,
                null,
            );

        self::assertNotNull($partner);

        return (string) $partner['id'];
    }

    /** @return array{User,Business} */
    private function fixture(string $email, string $businessName): array
    {
        $this->app
            ->make(ProvisionAccount::class)
            ->handle(
                email: $email,
                displayName: 'F7 Security Tester',
                password: 'F7-Security-Password-2026',
                languageMode: LanguageMode::English,
                timezone: 'UTC',
                actorLabel: 'F7 Security Test',
                reason: 'F7 security fixture',
                source: 'test',
            );

        $this->app
            ->make(ChangeAccountStatus::class)
            ->handle(
                email: $email,
                targetStatus: AccountStatus::Active,
                actorLabel: 'F7 Security Test',
                reason: 'Activate F7 security fixture',
                source: 'test',
            );

        $user = User::query()
            ->where('email', $email)
            ->sole();

        $business = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $user,
                $businessName,
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        return [$user, $business];
    }
}
