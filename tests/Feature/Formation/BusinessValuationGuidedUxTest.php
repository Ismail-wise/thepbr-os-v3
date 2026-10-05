<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Evidence\ListAuthorizedEvidenceTargets;
use App\Application\Formation\ExistingBusinessBaseline;
use App\Application\Journey\GetMasterBusinessJourney;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class BusinessValuationGuidedUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'array');
    }

    public function test_existing_business_guided_endpoint_reuses_canonical_sources_and_read_model(): void
    {
        $this->withoutVite();

        $user = $this->activeUser('valuation-guided@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Guided Valuation Business',
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            BusinessStage::Operating,
            'THB',
        );

        $baseline = $this->app->make(ExistingBusinessBaseline::class);

        $baseline->addFinancialSnapshot(
            $user,
            $business,
            [
                'as_of_date' => '2026-09-30',
                'revenue' => '800000.00',
                'expenses' => '620000.00',
                'cash' => '20000.00',
                'receivables' => '50000.00',
                'payables' => '40000.00',
                'notes' => 'Canonical source snapshot.',
            ],
        );

        $baseline->addAsset(
            $user,
            $business,
            [
                'name' => 'Operating assets',
                'estimated_value' => '300000.00',
                'notes' => null,
            ],
        );

        $baseline->addLiability(
            $user,
            $business,
            [
                'name' => 'Recorded liabilities',
                'outstanding_amount' => '100000.00',
                'notes' => null,
            ],
        );

        $response = $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->post('/formation/existing/business-valuation', [
                'as_of_date' => '2026-10-05',
                'historical' => [
                    'ebitda' => '100000.00',
                    'debt' => '10000.00',
                ],
                'assumptions' => [
                    'ebitda_multiple' => '4',
                ],
                'review_state' => 'reviewed',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('business_valuation_runs', [
            'business_id' => $business->getKey(),
            'as_of_date' => '2026-10-05',
            'base_value' => '305000.00',
            'review_state' => 'reviewed',
        ]);

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/formation')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('Formation/Index')
                    ->where('formation.journey', 'existing')
                    ->where(
                        'formation.existing_business.business_valuation.historical.cash',
                        '20000.00',
                    )
                    ->where(
                        'formation.existing_business.business_valuation.historical.ebitda',
                        '100000.00',
                    )
                    ->where(
                        'formation.existing_business.business_valuation.assumptions.ebitda_multiple',
                        '4.0000',
                    )
                    ->where(
                        'formation.existing_business.business_valuation.range.base',
                        '305000.00',
                    )
                    ->where(
                        'formation.existing_business.business_valuation.provenance.financialSnapshot.values.revenue',
                        '800000.00',
                    )
                    ->where(
                        'formation.existing_business.business_valuation.semantics.indicativeEstimate',
                        true,
                    )
                    ->where(
                        'formation.existing_business.business_valuation.semantics.ownershipTruth',
                        false,
                    )
                    ->where(
                        'formation.existing_business.business_valuation.semantics.contributionValuation',
                        false,
                    ),
            );
    }

    public function test_hidden_guided_fields_are_not_required_when_asset_based_method_is_eligible(): void
    {
        $user = $this->activeUser('valuation-asset-only@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Asset Only Valuation Business',
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            BusinessStage::Operating,
            'USD',
        );

        $this->app->make(ExistingBusinessBaseline::class)->addAsset(
            $user,
            $business,
            [
                'name' => 'Recorded asset',
                'estimated_value' => '125000.00',
                'notes' => null,
            ],
        );

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->post('/formation/existing/business-valuation', [
                'as_of_date' => '2026-10-05',
                'historical' => [],
                'assumptions' => [],
                'review_state' => 'draft',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('business_valuation_runs', [
            'business_id' => $business->getKey(),
            'base_value' => '125000.00',
            'confidence_level' => 'low',
        ]);
    }

    public function test_new_business_journey_does_not_include_or_require_business_valuation(): void
    {
        $user = $this->activeUser('valuation-new-journey@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'New Journey Business',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        $journey = $this->app
            ->make(GetMasterBusinessJourney::class)
            ->execute($user, $business, null);

        self::assertSame('new', $journey['variant']);
        self::assertNotContains(
            'business_valuation',
            array_column($journey['steps'], 'key'),
        );

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->post('/formation/existing/business-valuation', [
                'as_of_date' => '2026-10-05',
                'historical' => [],
                'assumptions' => [],
                'review_state' => 'draft',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('formation');

        $this->assertDatabaseCount('business_valuation_runs', 0);
    }

    public function test_guided_valuation_manage_and_evidence_targets_fail_closed_without_capability(): void
    {
        $owner = $this->activeUser('valuation-owner@example.test');
        $viewer = $this->activeUser('valuation-viewer@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $owner,
            'Valuation Permission Business',
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            BusinessStage::Operating,
            'USD',
        );

        $this->app->make(ExistingBusinessBaseline::class)->addAsset(
            $owner,
            $business,
            [
                'name' => 'Recorded asset',
                'estimated_value' => '100000.00',
                'notes' => null,
            ],
        );

        Membership::query()->create([
            'user_id' => $viewer->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => MembershipAccessStatus::Active,
        ]);

        self::assertSame(
            [],
            $this->app
                ->make(ListAuthorizedEvidenceTargets::class)
                ->execute($viewer, $business)['business_valuation_run'],
        );

        $this->actingAs($viewer)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->post('/formation/existing/business-valuation', [
                'as_of_date' => '2026-10-05',
                'historical' => [],
                'assumptions' => [],
                'review_state' => 'draft',
            ])
            ->assertNotFound();
    }

    public function test_guided_source_contract_uses_progressive_reveal_session_draft_and_backend_result(): void
    {
        $component = $this->source(
            'resources/js/components/business-valuation/BusinessValuationGuidedJourney.vue',
        );
        $formation = $this->source('resources/js/pages/Formation/Index.vue');
        $vault = $this->source('resources/js/pages/Records/Documents/Show.vue');

        foreach ([
            '<GuidedJourneyStepper',
            '<ProgressiveReveal',
            'window.sessionStorage',
            "router.post('/formation/existing/business-valuation'",
            'historical,',
            'assumptions,',
            'props.latest?.methods',
            'latest.evidenceQuality',
            'latest.provenance',
            'Indicative',
            'guaranteed',
            'Ownership Truth',
            'Contribution Valuation',
            'href="/records/documents"',
        ] as $contract) {
            self::assertStringContainsString($contract, $component);
        }

        self::assertStringContainsString(
            '<BusinessValuationGuidedJourney',
            $formation,
        );
        self::assertStringNotContainsString(
            '@submit.prevent="post(\'/formation/existing/valuations\', valuation)"',
            $formation,
        );
        self::assertStringContainsString(
            'evidenceTargetOptions[linkForm.target_type] ?? []',
            $vault,
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

    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));

        self::assertIsString($source);

        return $source;
    }
}
