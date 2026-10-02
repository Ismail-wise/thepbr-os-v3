<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Application\Businesses\CreateBusiness;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class BusinessControlCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_overview_requires_authenticated_current_business_context(): void
    {
        $this->get('/overview')->assertRedirect('/login');

        $user = $this->activeUser('overview-no-context@example.test');

        $this->actingAs($user)
            ->get('/overview')
            ->assertForbidden();
    }

    public function test_overview_composes_authorized_sources_without_exposing_technical_identifiers(): void
    {
        $user = $this->activeUser('overview-owner@example.test');
        $business = $this->createOwnedBusiness(
            $user,
            'Meridian Control Centre',
        );

        $before = [
            'actions' => DB::table('actions')->count(),
            'business_events' => DB::table('business_events')->count(),
            'formal_record_versions' => DB::table('formal_record_versions')->count(),
        ];

        $response = $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/overview');

        $response
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('Business/ControlCenter')
                    ->where('controlCenter.business.name', 'Meridian Control Centre')
                    ->where('controlCenter.business.workspaceStatus', 'active')
                    ->where('controlCenter.business.baseCurrency', 'USD')
                    ->has('controlCenter.attention')
                    ->has('controlCenter.health.summary')
                    ->has('controlCenter.health.requirements')
                    ->has('controlCenter.governance.summary')
                    ->has('controlCenter.nextActions')
                    ->has('controlCenter.upcoming')
                    ->has('controlCenter.recentActivity.items'),
            );

        $payload = $response->viewData('page')['props']['controlCenter'];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        foreach ([
            'sourceId',
            'source_id',
            'sourceVersion',
            'reasonCode',
            'lastVerifiedAt',
            'eventType',
            'actorType',
            'versionNumber',
            'content_hash',
            'snapshot_hash',
            'proposalId',
            'proposal_id',
            'authoritySnapshot',
            'authority_snapshot',
            'formalRecordVersionId',
            'formal_record_version_id',
            'current_effective_source',
            'records.formal_record',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }

        self::assertSame($before, [
            'actions' => DB::table('actions')->count(),
            'business_events' => DB::table('business_events')->count(),
            'formal_record_versions' => DB::table('formal_record_versions')->count(),
        ]);
    }

    public function test_overview_is_tenant_scoped_to_selected_business(): void
    {
        $user = $this->activeUser('overview-tenant@example.test');

        $alpha = $this->createOwnedBusiness($user, 'Alpha Business');
        $beta = $this->createOwnedBusiness($user, 'Beta Business');

        $response = $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $alpha->getKey(),
            ])
            ->get('/overview');

        $response
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->where('controlCenter.business.name', 'Alpha Business'),
            );

        $controlCenter = $response->viewData('page')['props']['controlCenter'];
        self::assertStringNotContainsString(
            'Beta Business',
            json_encode($controlCenter, JSON_THROW_ON_ERROR),
        );

        self::assertNotSame(
            (string) $alpha->getKey(),
            (string) $beta->getKey(),
        );
    }

    public function test_overview_fails_closed_per_section_when_capabilities_are_not_granted(): void
    {
        $user = $this->activeUser('overview-private@example.test');

        $business = Business::query()->create([
            'name' => 'Private Overview',
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => null,
            'workspace_status' => 'active',
            'base_currency' => 'THB',
        ]);

        Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/overview')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->where('controlCenter.business.name', 'Private Overview')
                    ->where('controlCenter.health', null)
                    ->where('controlCenter.governance', null)
                    ->where('controlCenter.recentActivity', null)
                    ->has('controlCenter.attention', 0)
                    ->has('controlCenter.nextActions', 0)
                    ->has('controlCenter.upcoming', 0),
            );
    }

    public function test_control_center_source_preserves_frozen_visual_hierarchy_and_hides_technical_fields(): void
    {
        $source = file_get_contents(
            base_path('resources/js/pages/Business/ControlCenter.vue'),
        );

        self::assertIsString($source);

        $markers = [
            '<BusinessHero',
            '<AttentionQueue',
            '<HealthReadinessStrip',
            '<BusinessSnapshotGrid',
            '<NextBestActionCard',
            '<OperatingAreaCard',
            '<UpcomingPanel',
            '<RecentActivityPanel',
        ];

        $previous = -1;

        foreach ($markers as $marker) {
            $position = strpos($source, $marker);

            self::assertNotFalse(
                $position,
                sprintf('Missing frozen UX-2 section: %s', $marker),
            );
            self::assertGreaterThan(
                $previous,
                $position,
                sprintf('UX-2 section is out of frozen order: %s', $marker),
            );

            $previous = $position;
        }

        foreach ([
            'sourceId',
            'content_hash',
            'snapshot_hash',
            'proposalId',
            'authoritySnapshot',
            'formalRecordVersionId',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_overview_route_remains_current_business_scoped_and_home_is_unchanged(): void
    {
        $route = app('router')->getRoutes()->getByName(
            'business.control-center',
        );

        self::assertNotNull($route);
        self::assertSame('overview', $route->uri());
        self::assertContains(
            EnsureCurrentBusinessContext::class,
            $route->gatherMiddleware(),
        );

        $user = $this->activeUser('overview-home@example.test');

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('AccountHome')
                    ->missing('controlCenter'),
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

    private function createOwnedBusiness(
        User $user,
        string $name,
    ): Business {
        return $this->app->make(CreateBusiness::class)->handle(
            $user,
            $name,
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );
    }
}
