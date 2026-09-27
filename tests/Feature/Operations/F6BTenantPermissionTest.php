<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F6BTenantPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_operations_and_governance_reads_are_tenant_scoped(): void
    {
        $user = $this->user('f6-tenant-reader');
        $businessA = $this->business('F6 Tenant A');
        $businessB = $this->business('F6 Tenant B');

        $membershipA = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $businessA->getKey(),
            'access_status' => 'active',
        ]);

        Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $businessB->getKey(),
            'access_status' => 'active',
        ]);

        $this->grant(
            $businessA,
            $membershipA,
            CapabilityCatalog::OPERATIONS_VIEW,
        );
        $this->grant(
            $businessA,
            $membershipA,
            CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
        );

        $operationsOther = $this->otherBusinessVersion(
            $businessB,
            $user,
            'operations_register',
            str_repeat('e', 64),
        );
        $governanceOther = $this->otherBusinessVersion(
            $businessB,
            $user,
            'governance_charter',
            str_repeat('f', 64),
        );

        $operations = $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $businessA->getKey(),
            ])
            ->get('/operations');

        $operations
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Operations/Index')
                    ->where(
                        'operations.business.id',
                        (string) $businessA->getKey(),
                    )
                    ->has('operations.versions', 0),
            )
            ->assertDontSee(
                (string) $operationsOther->getKey(),
            );

        $governance = $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $businessA->getKey(),
            ])
            ->get('/governance/rules');

        $governance
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Governance/Rules')
                    ->where(
                        'governanceRules.business.id',
                        (string) $businessA->getKey(),
                    )
                    ->has('governanceRules.versions', 0),
            )
            ->assertDontSee(
                (string) $governanceOther->getKey(),
            );
    }

    public function test_active_membership_without_operations_capability_is_default_denied(): void
    {
        $user = $this->user('f6-default-deny');
        $business = $this->business('F6 Default Deny');

        Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $business->getKey(),
            ])
            ->get('/operations')
            ->assertNotFound();
    }

    public function test_operations_permission_does_not_create_governance_visibility(): void
    {
        $user = $this->user('f6-rights-separation');
        $business = $this->business('F6 Rights Separation');

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        $this->grant(
            $business,
            $membership,
            CapabilityCatalog::OPERATIONS_VIEW,
        );

        $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $business->getKey(),
            ])
            ->get('/operations')
            ->assertOk();

        $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $business->getKey(),
            ])
            ->get('/governance/rules')
            ->assertNotFound();
    }

    private function otherBusinessVersion(
        Business $business,
        User $user,
        string $recordType,
        string $hash,
    ): FormalRecordVersion {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => $recordType,
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        return FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Restricted other-Business record',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => $hash,
            'frozen_at' => null,
        ]);
    }

    private function user(string $prefix): User
    {
        return User::query()->create([
            'email' => $prefix.'-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => 'active',
            'password_changed_at' => now(),
        ]);
    }

    private function business(string $name): Business
    {
        return Business::query()->create([
            'name' => $name,
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);
    }

    private function grant(
        Business $business,
        Membership $membership,
        string $capability,
    ): void {
        $permission = Permission::query()->firstOrCreate([
            'key' => $capability,
        ]);

        PermissionGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => 'allow',
        ]);
    }
}
