<?php

declare(strict_types=1);

namespace Tests\Feature\Closure;

use App\Application\Businesses\CreateBusiness;
use App\Application\Closure\ClosureWorkflow;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Closure\Enums\ClosureCaseStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class F7ClosureWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_closure_case_is_governed_and_does_not_close_or_delete_live_truth_when_opened(): void
    {
        [$user, $business] = $this->fixture(
            'f7-closure-open@example.test',
            'F7 Closure Open Business',
        );

        $beforeFamilies = DB::table('formal_record_families')
            ->where('business_id', $business->getKey())
            ->count();
        $beforeDocuments = DB::table('documents')
            ->where('business_id', $business->getKey())
            ->count();

        $workflow = $this->app->make(ClosureWorkflow::class);

        $case = $workflow->createCase(
            $user,
            $business,
            'partners_approved_orderly_wind_down',
            'Thailand company law and qualified local legal advice.',
            'business_closure_approval',
            'Orderly wind-down requested for governed review.',
            'Local company registration reference.',
        );

        self::assertNotNull($case);
        self::assertSame(ClosureCaseStatus::Draft, $case->status);
        self::assertSame(1, (int) $case->revision);
        self::assertSame(
            WorkspaceStatus::Active,
            $business->fresh()->workspace_status,
        );
        self::assertSame(
            $beforeFamilies,
            DB::table('formal_record_families')
                ->where('business_id', $business->getKey())
                ->count(),
            'Opening Closure must not create Effective/formal truth by itself.',
        );
        self::assertSame(
            $beforeDocuments,
            DB::table('documents')
                ->where('business_id', $business->getKey())
                ->count(),
            'Opening Closure must not delete or replace business files.',
        );

        foreach ([
            ['asset', 'assets_protected'],
            ['asset', 'asset_inventory_complete'],
            ['liability', 'liability_inventory_complete'],
        ] as [$type, $key]) {
            self::assertTrue($workflow->recordRequirement(
                $user,
                $business,
                (string) $case->getKey(),
                1,
                $type,
                $key,
                'met',
                'Verified before Governance submission.',
            ));
        }

        $claimId = $workflow->createClaim(
            $user,
            $business,
            (string) $case->getKey(),
            1,
            'CLAIM-001',
            'creditor',
            'Trade creditor reference',
            true,
            null,
            null,
            'Liability identified before residual distribution.',
            null,
            null,
            null,
            'Qualified local adviser to confirm applicable priority.',
        );

        self::assertNotNull($claimId);
        self::assertDatabaseHas('closure_claims', [
            'id' => $claimId,
            'business_id' => $business->getKey(),
            'closure_case_id' => $case->getKey(),
            'claim_reference' => 'CLAIM-001',
            'required' => true,
            'status' => 'identified',
            'legal_priority_reference' => null,
        ]);

        $submission = $workflow->submitGovernance(
            $user,
            $business,
            (string) $case->getKey(),
            1,
        );

        self::assertNotNull($submission);
        $case->refresh();

        self::assertSame(ClosureCaseStatus::UnderGovernance, $case->status);
        self::assertSame(2, (int) $case->revision);
        self::assertDatabaseHas('proposal_version_records', [
            'business_id' => $business->getKey(),
            'proposal_version_id' => $submission['proposal_version_id'],
            'formal_record_version_id' => $submission['formal_record_version_id'],
        ]);
        self::assertSame(
            0,
            DB::table('decisions')
                ->where(
                    'proposal_version_id',
                    $submission['proposal_version_id'],
                )
                ->count(),
            'System permission must not manufacture Governance approval.',
        );
        self::assertSame(
            WorkspaceStatus::Active,
            $business->fresh()->workspace_status,
            'Governance submission is not legal or workspace closure.',
        );
    }

    public function test_missing_required_wind_down_inventory_blocks_governance_submission(): void
    {
        [$user, $business] = $this->fixture(
            'f7-closure-preconditions@example.test',
            'F7 Closure Preconditions Business',
        );

        $workflow = $this->app->make(ClosureWorkflow::class);
        $case = $workflow->createCase(
            $user,
            $business,
            'planned_dissolution',
            'Applicable local jurisdiction rule reference.',
            'business_closure_approval',
        );

        self::assertNotNull($case);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('assets_protected');

        $workflow->submitGovernance(
            $user,
            $business,
            (string) $case->getKey(),
            1,
        );
    }

    public function test_stale_closure_revision_is_rejected_without_overwrite(): void
    {
        [$user, $business] = $this->fixture(
            'f7-closure-stale@example.test',
            'F7 Closure Stale Business',
        );

        $workflow = $this->app->make(ClosureWorkflow::class);
        $case = $workflow->createCase(
            $user,
            $business,
            'planned_dissolution',
            'Applicable local jurisdiction rule reference.',
            'business_closure_approval',
        );

        self::assertNotNull($case);

        $case = $workflow->transition(
            $user,
            $business,
            (string) $case->getKey(),
            1,
            ClosureCaseStatus::Withdrawn,
        );

        self::assertNotNull($case);
        self::assertSame(2, (int) $case->revision);

        $this->expectException(StaleRevision::class);

        $workflow->recordRequirement(
            $user,
            $business,
            (string) $case->getKey(),
            1,
            'asset',
            'assets_protected',
            'met',
        );
    }

    public function test_archived_and_closed_workspace_states_remain_distinct_from_closure_case_state(): void
    {
        [$user, $business] = $this->fixture(
            'f7-closure-state@example.test',
            'F7 Closure State Business',
        );

        self::assertNotSame(
            WorkspaceStatus::Archived,
            WorkspaceStatus::Closed,
        );

        $business->workspace_status = WorkspaceStatus::Archived;
        $business->save();

        self::assertSame(
            0,
            DB::table('closure_cases')
                ->where('business_id', $business->getKey())
                ->count(),
            'Archive must not create a Closure Case.',
        );

        $case = $this->app->make(ClosureWorkflow::class)->createCase(
            $user,
            $business,
            'archived_business_requires_legal_dissolution',
            'Applicable local jurisdiction rule reference.',
            'business_closure_approval',
        );

        self::assertNotNull($case);
        self::assertSame(
            WorkspaceStatus::Archived,
            $business->fresh()->workspace_status,
            'Opening Closure must not silently change archive/workspace state.',
        );
    }

    /** @return array{User,Business} */
    private function fixture(string $email, string $businessName): array
    {
        $this->app->make(ProvisionAccount::class)->handle(
            email: $email,
            displayName: 'F7 Closure Tester',
            password: 'F7-Closure-Password-2026',
            languageMode: LanguageMode::English,
            timezone: 'UTC',
            actorLabel: 'F7 Closure Test',
            reason: 'F7 Closure fixture',
            source: 'test',
        );

        $this->app->make(ChangeAccountStatus::class)->handle(
            email: $email,
            targetStatus: AccountStatus::Active,
            actorLabel: 'F7 Closure Test',
            reason: 'Activate F7 Closure fixture',
            source: 'test',
        );

        $user = User::query()->where('email', $email)->sole();

        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            $businessName,
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        return [$user, $business];
    }
}
