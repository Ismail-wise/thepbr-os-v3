<?php

declare(strict_types=1);

namespace Tests\Feature\Partnership;

use App\Application\Businesses\CreateBusiness;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
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
