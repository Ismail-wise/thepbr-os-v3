<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ActivityPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_route_requires_authenticated_active_current_business_context(): void
    {
        $this->get('/records/activity')->assertRedirect('/login');
    }

    public function test_activity_page_is_default_deny_without_capability(): void
    {
        [$user, $business] = $this->context(false);

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/records/activity')
            ->assertForbidden();
    }

    public function test_authorized_activity_page_renders_privacy_filtered_props(): void
    {
        $this->withoutVite();

        [$user, $business] = $this->context(true);

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/records/activity')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('Records/Activity')
                    ->has('activity.items', 0)
                    ->where('activity.nextCursor', null)
                    ->missing('activity.total'),
            );
    }

    /**
     * @return array{User, Business}
     */
    private function context(bool $grant): array
    {
        $user = User::query()->create([
            'email' => 'activity-page-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => AccountStatus::Active->value,
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'name' => 'Activity Page Business '.Str::uuid7(),
            'origin_type' => BusinessOriginType::StartedThroughPbr->value,
            'business_stage' => BusinessStage::Idea->value,
            'setup_phase' => SetupPhase::Formation->value,
            'workspace_status' => WorkspaceStatus::Active->value,
            'base_currency' => 'USD',
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => MembershipAccessStatus::Active->value,
        ]);

        if ($grant) {
            $permission = Permission::query()->create([
                'key' => 'records.activity.view',
            ]);

            PermissionGrant::query()->create([
                'business_id' => $business->getKey(),
                'membership_id' => $membership->getKey(),
                'permission_id' => $permission->getKey(),
                'effect' => PermissionEffect::Allow->value,
            ]);
        }

        return [$user, $business];
    }
}
