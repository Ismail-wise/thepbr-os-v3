<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Application\Businesses\CreateBusiness;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class GovernanceWorkspacePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_owner_can_open_zero_state_governance_command_center_without_receiving_governance_authority(): void
    {
        $this->withoutVite();

        $user = $this->activeUser('f3-command-center@example.test');

        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Governance Command Center',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        $response = $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/governance');

        $response
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('Governance/Index')
                    ->where(
                        'governance.business.id',
                        (string) $business->getKey(),
                    )
                    ->where(
                        'governance.summary.needsAttention',
                        0,
                    )
                    ->where(
                        'governance.summary.openDecisions',
                        0,
                    )
                    ->where(
                        'governance.summary.pendingSignatures',
                        0,
                    )
                    ->where(
                        'governance.summary.openActions',
                        0,
                    )
                    ->where(
                        'governance.authority.authority_mode',
                        'none',
                    )
                    ->has('governance.proposalVersions', 0)
                    ->has('governance.decisions', 0)
                    ->has('governance.signatureRequests', 0)
                    ->has('governance.actions', 0)
                    ->has('governance.reviews', 0)
                    ->has('governance.amendments', 0),
            );

        $this->assertDatabaseCount('formation_authority_establishments', 0);
        $this->assertDatabaseCount('authority_snapshots', 0);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_governance_command_center_fails_closed_without_system_capability(): void
    {
        $this->withoutVite();

        $user = $this->activeUser('f3-no-governance@example.test');

        $business = Business::query()->create([
            'name' => 'No Governance Access',
            'origin_type' => BusinessOriginType::StartedThroughPbr,
            'business_stage' => BusinessStage::Planning,
            'setup_phase' => SetupPhase::Formation,
            'workspace_status' => WorkspaceStatus::Active,
            'base_currency' => 'USD',
        ]);

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
            ->get('/governance')
            ->assertNotFound();
    }

    public function test_governance_route_is_current_business_scoped(): void
    {
        $route = app('router')
            ->getRoutes()
            ->getByName('governance.index');

        $this->assertNotNull($route);
        $this->assertSame('governance', $route->uri());

        $this->assertContains(
            EnsureCurrentBusinessContext::class,
            $route->gatherMiddleware(),
        );
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
