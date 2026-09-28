<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\AI\BuildAuthorizedAiContext;
use App\Application\AI\PbrAiAssistant;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\Enums\PermissionEffect;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7AiAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_membership_and_pbr_ai_capability_are_required(): void
    {
        [$owner, $business, $membership] = $this->workspace(
            'ai-owner',
            true,
        );

        $context = $this->app->make(BuildAuthorizedAiContext::class);

        self::assertTrue($context->canAccess($owner, $business));

        $permission = Permission::query()
            ->where('key', CapabilityCatalog::PBR_AI_VIEW)
            ->sole();

        PermissionGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => PermissionEffect::Deny,
        ]);

        self::assertFalse(
            $context->canAccess($owner, $business),
            'Explicit scoped deny must win over the standard profile allow.',
        );

        $membership->access_status = 'revoked';
        $membership->save();

        self::assertFalse($context->canAccess($owner, $business));
    }

    public function test_missing_ai_capability_defaults_to_deny_even_with_active_membership(): void
    {
        [$user, $business] = $this->workspace(
            'ai-no-profile',
            false,
        );

        self::assertFalse(
            $this->app
                ->make(BuildAuthorizedAiContext::class)
                ->canAccess($user, $business),
        );
    }

    public function test_cross_business_context_fails_closed(): void
    {
        [$userA, $businessA] = $this->workspace('ai-a', true);
        [$userB, $businessB] = $this->workspace('ai-b', true);

        $context = $this->app->make(BuildAuthorizedAiContext::class);

        self::assertTrue($context->canAccess($userA, $businessA));
        self::assertTrue($context->canAccess($userB, $businessB));
        self::assertFalse($context->canAccess($userA, $businessB));
        self::assertFalse($context->canAccess($userB, $businessA));

        self::assertNull(
            $this->app->make(PbrAiAssistant::class)->workspace(
                $userA,
                $businessB,
            ),
        );
    }

    public function test_ai_routes_use_authenticated_current_business_context(): void
    {
        $this->withoutVite();

        [$user, $business] = $this->workspace('ai-route', true);

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $business->getKey(),
            ])
            ->get('/ai')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('AI/Index')
                    ->where(
                        'aiWorkspace.business.name',
                        $business->name,
                    )
                    ->where(
                        'aiWorkspace.advisory_only',
                        true,
                    ),
            );
    }

    /**
     * @return array{User,Business,Membership}
     */
    private function workspace(
        string $prefix,
        bool $provision,
    ): array {
        $business = Business::query()->create([
            'name' => 'F7 AI '.Str::uuid7(),
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

        if ($provision) {
            $this->app
                ->make(ProvisionStandardAccessProfiles::class)
                ->execute($business, $membership);
        }

        return [$user, $business, $membership];
    }
}
