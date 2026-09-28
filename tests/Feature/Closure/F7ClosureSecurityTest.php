<?php

declare(strict_types=1);

namespace Tests\Feature\Closure;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Activity\ActivityTargetRegistry;
use App\Application\Closure\ClosureWorkflow;
use App\Application\Closure\GetClosureWorkspace;
use App\Application\Evidence\EvidenceTargetRegistry;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\Enums\PermissionEffect;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\RecordAccessRule;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Closure\ClosureCase;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class F7ClosureSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_business_closure_identifiers_fail_closed_everywhere(): void
    {
        [$userA, $businessA] = $this->workspace('closure-a');
        [$userB, $businessB] = $this->workspace('closure-b');

        $case = $this->app->make(ClosureWorkflow::class)->createCase(
            $userA,
            $businessA,
            'planned_dissolution',
            'Applicable jurisdiction A.',
            'business_closure_approval',
        );

        self::assertNotNull($case);

        try {
            $this->app->make(ClosureWorkflow::class)->recordRequirement(
                $userB,
                $businessB,
                (string) $case->getKey(),
                1,
                'asset',
                'assets_protected',
                'met',
            );
            self::fail('Cross-Business Closure identifier must fail closed.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'current Business',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            0,
            DB::table('closure_requirements')
                ->where('business_id', $businessA->getKey())
                ->where('closure_case_id', $case->getKey())
                ->count(),
        );

        $workspaceB = $this->app->make(GetClosureWorkspace::class)->execute(
            $userB,
            $businessB,
        );

        self::assertNotNull($workspaceB);
        self::assertSame([], $workspaceB['cases']);

        self::assertNull(
            $this->app->make(EvidenceTargetRegistry::class)->resolve(
                'closure_case',
                (string) $case->getKey(),
                (string) $businessB->getKey(),
            ),
        );

        self::assertNull(
            $this->app->make(ActivityTargetRegistry::class)->find(
                $businessB,
                'closure_case',
                (string) $case->getKey(),
            ),
        );
    }

    public function test_missing_closure_capability_hides_workspace_existence(): void
    {
        [$owner, $business] = $this->workspace('closure-visible');

        $case = $this->app->make(ClosureWorkflow::class)->createCase(
            $owner,
            $business,
            'planned_dissolution',
            'Applicable local jurisdiction.',
            'business_closure_approval',
        );

        self::assertNotNull($case);

        [$viewer] = $this->bareMembership($business, 'closure-no-capability');

        self::assertNull(
            $this->app->make(GetClosureWorkspace::class)->execute(
                $viewer,
                $business,
            ),
            'Missing system capability must not reveal Closure workspace or case existence.',
        );
    }

    public function test_explicit_record_deny_hides_closure_case_from_authorized_register(): void
    {
        [$owner, $business, $membership] = $this->workspace(
            'closure-record-deny',
            true,
        );

        $case = $this->app->make(ClosureWorkflow::class)->createCase(
            $owner,
            $business,
            'planned_dissolution',
            'Applicable local jurisdiction.',
            'business_closure_approval',
        );

        self::assertNotNull($case);

        $before = $this->app->make(GetClosureWorkspace::class)->execute(
            $owner,
            $business,
        );

        self::assertNotNull($before);
        self::assertCount(1, $before['cases']);

        $permission = Permission::query()
            ->where('key', CapabilityCatalog::CLOSURE_VIEW)
            ->sole();

        RecordAccessRule::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_profile_id' => null,
            'permission_id' => $permission->getKey(),
            'resource_type' => ClosureCase::class,
            'resource_id' => $case->getKey(),
            'effect' => PermissionEffect::Deny,
        ]);

        $after = $this->app->make(GetClosureWorkspace::class)->execute(
            $owner,
            $business,
        );

        self::assertNotNull($after);
        self::assertSame(
            [],
            $after['cases'],
            'Explicit scoped deny must hide even the existence of the Closure Case.',
        );
    }

    public function test_evidence_and_activity_registries_expose_only_tenant_scoped_closure_targets(): void
    {
        [$owner, $business] = $this->workspace('closure-registries');

        $case = $this->app->make(ClosureWorkflow::class)->createCase(
            $owner,
            $business,
            'planned_dissolution',
            'Applicable local jurisdiction.',
            'business_closure_approval',
        );

        self::assertNotNull($case);

        $evidenceRegistry = $this->app->make(EvidenceTargetRegistry::class);
        self::assertContains('closure_case', $evidenceRegistry->supportedTypes());
        self::assertSame(
            CapabilityCatalog::CLOSURE_MANAGE,
            $evidenceRegistry->requiredManageCapability('closure_case'),
        );
        self::assertInstanceOf(
            ClosureCase::class,
            $evidenceRegistry->resolve(
                'closure_case',
                (string) $case->getKey(),
                (string) $business->getKey(),
            ),
        );

        $activityRegistry = $this->app->make(ActivityTargetRegistry::class);
        self::assertSame(
            ClosureCase::class,
            $activityRegistry->resourceClass('closure_case'),
        );
        self::assertInstanceOf(
            ClosureCase::class,
            $activityRegistry->find(
                $business,
                'closure_case',
                (string) $case->getKey(),
            ),
        );
    }

    /**
     * @return array{0:User,1:Business,2?:Membership}
     */
    private function workspace(
        string $prefix,
        bool $includeMembership = false,
    ): array {
        $business = Business::query()->create([
            'name' => 'F7 Closure '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$user, $membership] = $this->bareMembership($business, $prefix);

        $this->app
            ->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $membership);

        return $includeMembership
            ? [$user, $business, $membership]
            : [$user, $business];
    }

    /** @return array{User,Membership} */
    private function bareMembership(
        Business $business,
        string $prefix,
    ): array {
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

        return [$user, $membership];
    }
}
