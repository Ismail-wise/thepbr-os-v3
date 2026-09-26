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
use Tests\TestCase;

final class F5PartnershipActionSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_http_surface_creates_and_reviews_cash_contribution(): void
    {
        [$user, $business] = $this->fixture(
            'f5-action-cash@example.test',
        );

        $partnerId = $this->createPartner(
            $user,
            $business,
            'Cash Partner',
        );

        $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $business->getKey(),
            ])
            ->post('/partnership/contributions', [
                'partner_id' => $partnerId,
                'contribution_type' => 'cash',
                'currency' => 'USD',
                'description' => 'Initial cash contribution',
                'proposed_value' => '5000.00',
                'amount_committed' => '5000.00',
                'amount_received' => '0.00',
            ])
            ->assertRedirect();

        $row = DB::table('contributions')
            ->where('business_id', $business->getKey())
            ->where('partner_id', $partnerId)
            ->sole();

        self::assertSame('proposed', $row->status);
        self::assertSame('5000.00', (string) $row->proposed_value);

        $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $business->getKey(),
            ])
            ->put(
                '/partnership/contributions/'.$row->id.'/review',
                [
                    'expected_revision' => 1,
                    'reviewed_value' => '4800.00',
                    'valuation_method' => 'Bank transfer evidence review',
                    'note' => 'Reviewed through F5 action surface.',
                ],
            )
            ->assertRedirect();

        $reviewed = DB::table('contributions')
            ->where('id', $row->id)
            ->sole();

        self::assertSame('reviewed', $reviewed->status);
        self::assertSame('4800.00', (string) $reviewed->reviewed_value);
        self::assertSame(2, (int) $reviewed->revision);
    }

    public function test_http_surface_refuses_ownership_without_accepted_contribution(): void
    {
        [$user, $business] = $this->fixture(
            'f5-action-ownership@example.test',
        );

        $partnerId = $this->createPartner(
            $user,
            $business,
            'Scenario Partner',
        );

        $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $business->getKey(),
            ])
            ->post('/partnership/contributions', [
                'partner_id' => $partnerId,
                'contribution_type' => 'cash',
                'currency' => 'USD',
                'description' => 'Not yet accepted',
                'proposed_value' => '1000.00',
                'amount_committed' => '1000.00',
            ])
            ->assertRedirect();

        $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $business->getKey(),
            ])
            ->from('/partnership')
            ->post('/partnership/ownership/scenarios', [
                'name' => 'Blocked Scenario',
                'currency' => 'USD',
                'share_value_minor_units' => 100,
                'authorized_shares' => '10000',
                'reserved_unissued_shares' => '0',
            ])
            ->assertRedirect('/partnership')
            ->assertSessionHasErrors('contributions');

        self::assertSame(
            0,
            DB::table('ownership_scenarios')
                ->where('business_id', $business->getKey())
                ->count(),
        );
    }

    public function test_cross_business_partner_id_is_not_usable_on_contribution_route(): void
    {
        [$user, $businessA] = $this->fixture(
            'f5-action-isolation@example.test',
        );

        $businessB = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $user,
                'F5 Other Action Business',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        $partnerB = $this->createPartner(
            $user,
            $businessB,
            'Other Business Partner',
        );

        $this
            ->actingAs($user)
            ->withSession([
                'current_business_id' => $businessA->getKey(),
            ])
            ->post('/partnership/contributions', [
                'partner_id' => $partnerB,
                'contribution_type' => 'cash',
                'currency' => 'USD',
                'description' => 'Cross-business attempt',
                'proposed_value' => '1000.00',
                'amount_committed' => '1000.00',
            ])
            ->assertNotFound();

        self::assertSame(
            0,
            DB::table('contributions')
                ->where('business_id', $businessA->getKey())
                ->count(),
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

    /**
     * @return array{User, Business}
     */
    private function fixture(string $email): array
    {
        $this->app
            ->make(ProvisionAccount::class)
            ->handle(
                email: $email,
                displayName: 'F5 Action Tester',
                password: 'F5-Test-Password-2026',
                languageMode: LanguageMode::English,
                timezone: 'UTC',
                actorLabel: 'F5 Action Test',
                reason: 'F5 action fixture',
                source: 'test',
            );

        $this->app
            ->make(ChangeAccountStatus::class)
            ->handle(
                email: $email,
                targetStatus: AccountStatus::Active,
                actorLabel: 'F5 Action Test',
                reason: 'Activate F5 action fixture',
                source: 'test',
            );

        $user = User::query()
            ->where('email', $email)
            ->sole();

        $business = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $user,
                'F5 Action Business',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        return [$user, $business];
    }
}
