<?php

declare(strict_types=1);

namespace Tests\Feature\PartnerChanges;

use App\Application\Businesses\CreateBusiness;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Application\PartnerChanges\PartnerChangeWorkflow;
use App\Application\Partnership\DueDiligenceWorkflow;
use App\Application\Partnership\PartnerDirectory;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\PartnerChanges\Enums\PartnerChangeEligibilityStatus;
use App\Domain\PartnerChanges\Enums\PartnerChangeStatus;
use App\Domain\PartnerChanges\Enums\PartnerChangeTransactionType;
use App\Domain\Partnership\Enums\DueDiligenceStatus;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class F7PartnerChangeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_partner_admission_requires_dd_and_contribution_terms_before_governance(): void
    {
        [$user, $business] = $this->fixture(
            'f7-admission@example.test',
            'F7 Admission Business',
        );

        $partnerId = $this->createPartner(
            $user,
            $business,
            'Incoming Partner',
        );

        $workflow = $this->app->make(PartnerChangeWorkflow::class);

        $case = $workflow->createCase(
            $user,
            $business,
            PartnerChangeTransactionType::Admission,
            $partnerId,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'partner_change_approval',
            false,
            CarbonImmutable::now()->subMinute(),
        );

        self::assertNotNull($case);
        self::assertSame(PartnerChangeStatus::Draft, $case->status);
        self::assertNull($case->source_ownership_register_version_id);
        self::assertSame(1, (int) $case->revision);

        $case = $workflow->transition(
            $user,
            $business,
            (string) $case->getKey(),
            1,
            PartnerChangeStatus::EligibilityReview,
        );

        self::assertNotNull($case);
        self::assertSame(2, (int) $case->revision);

        self::assertTrue(
            $workflow->recordEligibility(
                $user,
                $business,
                (string) $case->getKey(),
                2,
                'buyer_eligible',
                PartnerChangeEligibilityStatus::Met,
                'Buyer eligibility reviewed.',
            ),
        );

        $case = $workflow->transition(
            $user,
            $business,
            (string) $case->getKey(),
            2,
            PartnerChangeStatus::Eligible,
        );

        self::assertNotNull($case);
        self::assertSame(3, (int) $case->revision);

        try {
            $workflow->transition(
                $user,
                $business,
                (string) $case->getKey(),
                3,
                PartnerChangeStatus::TermsReady,
            );
            self::fail('Incomplete Due Diligence must block Terms Ready.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'Due Diligence',
                $exception->getMessage(),
            );
        }

        $this->completeDueDiligence(
            $user,
            $business,
            $partnerId,
        );

        self::assertTrue(
            $workflow->recordRequirement(
                $user,
                $business,
                (string) $case->getKey(),
                3,
                'contribution',
                'contribution_terms_resolved',
                PartnerChangeEligibilityStatus::NotApplicable,
                'No pre-admission contribution is required.',
            ),
        );

        $case = $workflow->transition(
            $user,
            $business,
            (string) $case->getKey(),
            3,
            PartnerChangeStatus::TermsReady,
        );

        self::assertNotNull($case);
        self::assertSame(4, (int) $case->revision);

        $submission = $workflow->submitGovernance(
            $user,
            $business,
            (string) $case->getKey(),
            4,
        );

        self::assertNotNull($submission);
        $case->refresh();

        self::assertSame(
            PartnerChangeStatus::UnderGovernance,
            $case->status,
        );
        self::assertSame(5, (int) $case->revision);

        self::assertDatabaseHas('partners', [
            'id' => $partnerId,
            'business_id' => $business->getKey(),
            'status' => 'admission_pending',
        ]);

        self::assertDatabaseHas('partner_lifecycle_transitions', [
            'business_id' => $business->getKey(),
            'partner_id' => $partnerId,
            'from_status' => 'prospective',
            'to_status' => 'admission_pending',
            'source_type' => 'partner_change_case',
            'source_id' => $case->getKey(),
        ]);

        $governanceSubmission = DB::table(
            'partner_change_governance_submissions',
        )
            ->where('business_id', $business->getKey())
            ->where('partner_change_case_id', $case->getKey())
            ->sole();

        self::assertSame(
            $submission['formal_record_version_id'],
            (string) $governanceSubmission->formal_record_version_id,
        );
        self::assertSame(
            $submission['proposal_version_id'],
            (string) $governanceSubmission->proposal_version_id,
        );

        self::assertDatabaseHas('proposal_version_records', [
            'business_id' => $business->getKey(),
            'proposal_version_id' => $submission['proposal_version_id'],
            'formal_record_version_id' => $submission['formal_record_version_id'],
        ]);

        $record = DB::table('partner_change_record_versions')
            ->where(
                'formal_record_version_id',
                $submission['formal_record_version_id'],
            )
            ->sole();

        self::assertSame(
            (string) $record->package_hash,
            (string) $governanceSubmission->package_hash,
        );

        self::assertSame(
            0,
            DB::table('decisions')
                ->where(
                    'proposal_version_id',
                    $submission['proposal_version_id'],
                )
                ->count(),
            'System capability must not manufacture Governance authority or a Decision.',
        );
    }

    public function test_stale_case_revision_is_rejected_without_silent_overwrite(): void
    {
        [$user, $business] = $this->fixture(
            'f7-stale@example.test',
            'F7 Stale Business',
        );

        $partnerId = $this->createPartner(
            $user,
            $business,
            'Stale Partner',
        );

        $workflow = $this->app->make(PartnerChangeWorkflow::class);

        $case = $workflow->createCase(
            $user,
            $business,
            PartnerChangeTransactionType::Admission,
            $partnerId,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'partner_change_approval',
            false,
            null,
        );

        self::assertNotNull($case);

        $workflow->transition(
            $user,
            $business,
            (string) $case->getKey(),
            1,
            PartnerChangeStatus::EligibilityReview,
        );

        $this->expectException(StaleRevision::class);

        $workflow->recordEligibility(
            $user,
            $business,
            (string) $case->getKey(),
            1,
            'buyer_eligible',
            PartnerChangeEligibilityStatus::Met,
        );
    }

    public function test_partner_lifecycle_history_is_append_only_in_database(): void
    {
        $triggerCount = (int) DB::selectOne(
            <<<'SQL'
SELECT COUNT(*)::int AS count
FROM pg_trigger
WHERE tgname = 'partner_lifecycle_transitions_append_only'
  AND NOT tgisinternal
SQL
        )->count;

        self::assertSame(1, $triggerCount);
    }

    private function completeDueDiligence(
        User $user,
        Business $business,
        string $partnerId,
    ): void {
        $workflow = $this->app->make(DueDiligenceWorkflow::class);
        $fields = [
            'identity_legal_info' => 'Verified identity.',
            'background_summary' => 'Reviewed.',
            'business_experience' => 'Reviewed.',
            'financial_capacity' => 'Reviewed.',
            'reputation' => 'Reviewed.',
            'existing_business_interests' => 'None declared.',
            'conflict_of_interest' => 'None identified.',
            'time_commitment' => 'Confirmed.',
            'legal_regulatory_check' => 'Clear.',
            'notes' => 'F7 admission prerequisite fixture.',
        ];

        $draft = $workflow->save(
            $user,
            $business,
            $partnerId,
            null,
            0,
            DueDiligenceStatus::Draft,
            null,
            $fields,
        );

        self::assertNotNull($draft);

        $review = $workflow->save(
            $user,
            $business,
            $partnerId,
            $draft['id'],
            1,
            DueDiligenceStatus::InReview,
            null,
            $fields,
        );

        self::assertNotNull($review);

        $complete = $workflow->save(
            $user,
            $business,
            $partnerId,
            $draft['id'],
            2,
            DueDiligenceStatus::Completed,
            'low',
            $fields,
        );

        self::assertNotNull($complete);
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

    /** @return array{User,Business} */
    private function fixture(string $email, string $businessName): array
    {
        $this->app
            ->make(ProvisionAccount::class)
            ->handle(
                email: $email,
                displayName: 'F7 Partner Change Tester',
                password: 'F7-Test-Password-2026',
                languageMode: LanguageMode::English,
                timezone: 'UTC',
                actorLabel: 'F7 Partner Change Test',
                reason: 'F7 Partner Change fixture',
                source: 'test',
            );

        $this->app
            ->make(ChangeAccountStatus::class)
            ->handle(
                email: $email,
                targetStatus: AccountStatus::Active,
                actorLabel: 'F7 Partner Change Test',
                reason: 'Activate F7 fixture',
                source: 'test',
            );

        $user = User::query()
            ->where('email', $email)
            ->sole();

        $business = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $user,
                $businessName,
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        return [$user, $business];
    }
}
