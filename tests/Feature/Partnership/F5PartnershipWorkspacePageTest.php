<?php

declare(strict_types=1);

namespace Tests\Feature\Partnership;

use App\Application\Businesses\CreateBusiness;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Application\Partnership\PartnerDirectory;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F5PartnershipWorkspacePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_partnership_workspace_is_business_scoped_and_visible_to_authorized_member(): void
    {
        [$user, $business] = $this->fixture(
            'f5-workspace-page@example.test',
        );

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $business->getKey(),
            ])
            ->get('/partnership');

        $response
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Partnership/Index')
                    ->where(
                        'partnership.business.id',
                        (string) $business->getKey(),
                    )
                    ->where(
                        'partnership.business.name',
                        (string) $business->name,
                    ),
            );
    }

    public function test_partnership_workspace_does_not_expose_other_business_partner_rows(): void
    {
        [$user, $businessA] = $this->fixture(
            'f5-workspace-isolation@example.test',
        );

        $businessB = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $user,
                'F5 Other Business',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        DB::table('partners')->insert([
            'id' => (string) Str::uuid(),
            'business_id' => $businessB->getKey(),
            'display_name' => 'Restricted Other Business Partner',
            'legal_name' => null,
            'email' => null,
            'status' => 'prospective',
            'notes' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $businessA->getKey(),
            ])
            ->get('/partnership');

        $response
            ->assertOk()
            ->assertDontSee(
                'Restricted Other Business Partner',
            );
    }

    public function test_partner_dynamics_http_submit_is_idempotent_and_hydrates_current_reference(): void
    {
        [$user, $business] = $this->fixture(
            'f5-pd-http@example.test',
        );

        $partner = $this->app
            ->make(PartnerDirectory::class)
            ->create(
                $user,
                $business,
                'HTTP PartnerDynamics Partner',
                null,
                null,
                null,
            );

        self::assertNotNull($partner);

        $payload = [
            'source_assessment_id' => 'pd-http-001',
            'source_url' => 'https://example.test/assessment/pd-http-001',
            'assessment_version' => 'current',
            'primary_profile' => 'visionary',
            'secondary_profile' => 'builder',
            'completed_at' => '2026-09-29',
        ];

        $request = fn (array $data) => $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $business->getKey(),
            ])
            ->from('/partnership')
            ->post(
                '/partnership/partners/'.$partner['id'].'/partner-dynamics',
                $data,
            );

        $request($payload)
            ->assertRedirect('/partnership')
            ->assertSessionHasNoErrors();

        $request($payload)
            ->assertRedirect('/partnership')
            ->assertSessionHasNoErrors();

        self::assertSame(
            1,
            DB::table('partner_dynamics_assessment_references')
                ->where('business_id', $business->getKey())
                ->where('source_assessment_id', 'pd-http-001')
                ->count(),
        );

        self::assertSame(
            1,
            DB::table('business_events')
                ->where('business_id', $business->getKey())
                ->where(
                    'event_type',
                    'partnership.partner_dynamics.referenced',
                )
                ->count(),
        );

        $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $business->getKey(),
            ])
            ->get('/partnership')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where(
                        'partnership.partner_dynamics.0.partner_id',
                        $partner['id'],
                    )
                    ->where(
                        'partnership.partner_dynamics.0.primary_profile',
                        'visionary',
                    )
                    ->where(
                        'partnership.partner_dynamics.0.assessment_version',
                        'current',
                    ),
            );

        $request([
            ...$payload,
            'primary_profile' => 'analyst',
        ])->assertSessionHasErrors('partner_dynamics');

        self::assertSame(
            1,
            DB::table('partner_dynamics_assessment_references')
                ->where('business_id', $business->getKey())
                ->where('source_assessment_id', 'pd-http-001')
                ->count(),
        );
    }

    public function test_partner_dynamics_http_submit_preserves_auth_and_business_isolation(): void
    {
        [$user, $businessA] = $this->fixture(
            'f5-pd-isolation@example.test',
        );

        $businessB = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $user,
                'F5 PD Business B',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        $partnerB = $this->app
            ->make(PartnerDirectory::class)
            ->create(
                $user,
                $businessB,
                'Foreign Business Partner',
                null,
                null,
                null,
            );

        self::assertNotNull($partnerB);

        $payload = [
            'source_assessment_id' => 'pd-foreign-001',
            'source_url' => null,
            'assessment_version' => 'current',
            'primary_profile' => 'visionary',
            'secondary_profile' => null,
            'completed_at' => '2026-09-29',
        ];

        $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $businessA->getKey(),
            ])
            ->post(
                '/partnership/partners/'.$partnerB['id'].'/partner-dynamics',
                $payload,
            )
            ->assertNotFound();

        auth()->logout();

        $this->post(
            '/partnership/partners/'.$partnerB['id'].'/partner-dynamics',
            $payload,
        )->assertRedirect('/login');

        $this->assertDatabaseMissing(
            'partner_dynamics_assessment_references',
            [
                'source_assessment_id' => 'pd-foreign-001',
            ],
        );
    }

    /**
     * @return array{
     *     User,
     *     Business
     * }
     */
    private function fixture(string $email): array
    {
        $password = 'F5-Test-Password-2026';

        $this->app
            ->make(ProvisionAccount::class)
            ->handle(
                email: $email,
                displayName: 'F5 Workspace Tester',
                password: $password,
                languageMode: LanguageMode::English,
                timezone: 'UTC',
                actorLabel: 'F5 Workspace Test',
                reason: 'F5 deterministic fixture',
                source: 'test',
            );

        $this->app
            ->make(ChangeAccountStatus::class)
            ->handle(
                email: $email,
                targetStatus: AccountStatus::Active,
                actorLabel: 'F5 Workspace Test',
                reason: 'Activate F5 fixture',
                source: 'test',
            );

        $user = User::query()
            ->where('email', $email)
            ->sole();

        $business = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $user,
                'F5 Workspace Business',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        return [$user, $business];
    }
}
