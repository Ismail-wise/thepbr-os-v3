<?php

declare(strict_types=1);

namespace Tests\Feature\Partnership;

use App\Application\Businesses\CreateBusiness;
use App\Application\Evidence\EvidenceTargetRegistry;
use App\Application\Partnership\ContributionWorkflow;
use App\Application\Partnership\PartnerDirectory;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Partnership\Enums\ContributionType;
use App\Domain\Partnership\ValueObjects\ContributionValue;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class F5ContributionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_proposed_and_reviewed_contribution_never_becomes_accepted_truth(): void
    {
        [$user, $business, $partner] =
            $this->fixture('f5-contribution@example.test');

        $workflow = $this->app->make(
            ContributionWorkflow::class,
        );

        $contribution = $workflow->create(
            $user,
            $business,
            $partner['id'],
            ContributionType::Cash,
            'THB',
            'Initial cash contribution',
            new ContributionValue('100000.00'),
            'Subject to evidence and governance approval.',
            '2026-09-26',
            '2026-10-10',
            [
                'amount_committed' => '100000.00',
                'amount_received' => '0.00',
                'payment_date' => null,
            ],
        );

        self::assertNotNull($contribution);

        self::assertSame(
            [],
            $workflow->acceptedValues(
                $user,
                $business,
            ),
        );

        $reviewed = $workflow->review(
            $user,
            $business,
            $contribution['id'],
            1,
            new ContributionValue('95000.00'),
            'Verified cash commitment basis',
        );

        self::assertTrue($reviewed);

        $this->assertDatabaseHas('contributions', [
            'id' => $contribution['id'],
            'status' => 'reviewed',
            'proposed_value' => '100000.00',
            'reviewed_value' => '95000.00',
            'accepted_value' => null,
        ]);

        self::assertSame(
            [],
            $workflow->acceptedValues(
                $user,
                $business,
            ),
        );
    }

    public function test_contribution_cannot_submit_for_governance_without_evidence(): void
    {
        [$user, $business, $partner] =
            $this->fixture(
                'f5-contribution-evidence@example.test',
            );

        $workflow = $this->app->make(
            ContributionWorkflow::class,
        );

        $contribution = $workflow->create(
            $user,
            $business,
            $partner['id'],
            ContributionType::Cash,
            'USD',
            'Evidence-gated contribution',
            new ContributionValue('1000.00'),
            null,
            null,
            null,
            [
                'amount_committed' => '1000.00',
            ],
        );

        $workflow->review(
            $user,
            $business,
            $contribution['id'],
            1,
            new ContributionValue('1000.00'),
            'Cash commitment review',
        );

        $this->expectException(
            InvalidArgumentException::class,
        );

        $workflow->submitGovernance(
            $user,
            $business,
            $contribution['id'],
            'approval',
        );
    }

    public function test_delivery_is_blocked_before_governance_approval(): void
    {
        [$user, $business, $partner] =
            $this->fixture(
                'f5-delivery-before-approval@example.test',
            );

        $workflow = $this->app->make(
            ContributionWorkflow::class,
        );

        $contribution = $workflow->create(
            $user,
            $business,
            $partner['id'],
            ContributionType::Cash,
            'USD',
            'Approval-gated delivery',
            new ContributionValue('500.00'),
            null,
            null,
            null,
            [
                'amount_committed' => '500.00',
            ],
        );

        $workflow->review(
            $user,
            $business,
            $contribution['id'],
            1,
            new ContributionValue('500.00'),
            'Cash review',
        );

        $this->expectException(
            InvalidArgumentException::class,
        );

        $workflow->recordDelivery(
            $user,
            $business,
            $contribution['id'],
            2,
            new ContributionValue('500.00'),
            new DateTimeImmutable,
            null,
            true,
        );
    }

    public function test_database_rejects_fake_accepted_value_without_accepted_state_and_governance_decision(): void
    {
        [$user, $business, $partner] =
            $this->fixture(
                'f5-db-accepted-guard@example.test',
            );

        $workflow = $this->app->make(
            ContributionWorkflow::class,
        );

        $contribution = $workflow->create(
            $user,
            $business,
            $partner['id'],
            ContributionType::Cash,
            'USD',
            'Protected accepted value',
            new ContributionValue('700.00'),
            null,
            null,
            null,
            [
                'amount_committed' => '700.00',
            ],
        );

        $this->expectException(QueryException::class);

        DB::statement(
            'UPDATE contributions
             SET accepted_value = ?,
                 revision = revision + 1,
                 updated_at = now()
             WHERE id = ?',
            [
                '700.00',
                $contribution['id'],
            ],
        );
    }

    public function test_cross_business_partner_cannot_receive_contribution(): void
    {
        $user = $this->activeUser(
            'f5-cross-business@example.test',
        );

        $create = $this->app->make(
            CreateBusiness::class,
        );

        $businessA = $create->handle(
            $user,
            'F5 Business A',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        $businessB = $create->handle(
            $user,
            'F5 Business B',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        $partner = $this->app
            ->make(PartnerDirectory::class)
            ->create(
                $user,
                $businessA,
                'Business A Partner',
                null,
                null,
                null,
            );

        $result = $this->app
            ->make(ContributionWorkflow::class)
            ->create(
                $user,
                $businessB,
                $partner['id'],
                ContributionType::Cash,
                'USD',
                'Cross-business attempt',
                new ContributionValue('100.00'),
                null,
                null,
                null,
                [
                    'amount_committed' => '100.00',
                ],
            );

        self::assertNull($result);

        $this->assertDatabaseMissing('contributions', [
            'business_id' => $businessB->getKey(),
            'partner_id' => $partner['id'],
        ]);
    }

    public function test_contribution_detail_change_advances_parent_revision(): void
    {
        [$user, $business, $partner] =
            $this->fixture(
                'f5-detail-revision@example.test',
            );

        $workflow = $this->app->make(
            ContributionWorkflow::class,
        );

        $contribution = $workflow->create(
            $user,
            $business,
            $partner['id'],
            ContributionType::Cash,
            'USD',
            'Revision tracked cash contribution',
            new ContributionValue('100.00'),
            null,
            null,
            null,
            [
                'amount_committed' => '100.00',
                'amount_received' => '0.00',
                'payment_date' => null,
            ],
        );

        self::assertNotNull($contribution);
        self::assertSame(1, $contribution['revision']);

        DB::table('cash_contribution_details')
            ->where('contribution_id', $contribution['id'])
            ->update([
                'amount_received' => '25.00',
            ]);

        $this->assertDatabaseHas('contributions', [
            'id' => $contribution['id'],
            'revision' => 2,
        ]);
    }

    public function test_contribution_evidence_target_requires_contribution_manage_capability(): void
    {
        $registry = $this->app->make(
            EvidenceTargetRegistry::class,
        );

        self::assertSame(
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
            $registry->requiredManageCapability('contribution'),
        );

        self::assertSame(
            CapabilityCatalog::RECORDS_MANAGE,
            $registry->requiredManageCapability('proposal_version'),
        );
    }

    /**
     * @return array{User, mixed, array{id:string,revision:int}}
     */
    private function fixture(string $email): array
    {
        $user = $this->activeUser($email);

        $business = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $user,
                'F5 Contribution Business',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        $partner = $this->app
            ->make(PartnerDirectory::class)
            ->create(
                $user,
                $business,
                'Contribution Partner',
                null,
                null,
                null,
            );

        return [$user, $business, $partner];
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
