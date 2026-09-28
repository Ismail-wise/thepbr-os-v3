<?php

declare(strict_types=1);

namespace Tests\Feature\Exit;

use App\Application\Businesses\CreateBusiness;
use App\Application\Exit\ExitCaseWorkflow;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Application\Partnership\PartnerDirectory;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Exit\Enums\ExitCaseStatus;
use App\Domain\Exit\Enums\ExitTrigger;
use App\Domain\Exit\Enums\LeaverClassification;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class F7ExitWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_exit_preserves_access_and_ownership_and_captures_current_truth(): void
    {
        [$user, $business] = $this->fixture(
            'f7-exit-open@example.test',
            'F7 Exit Open Business',
        );

        $partnerId = $this->seedActivePartner($business, 'Leaving Partner');
        $membership = Membership::query()
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->sole();

        self::assertNotNull(
            $this->app->make(PartnerDirectory::class)->linkMembership(
                $user,
                $business,
                $partnerId,
                (string) $membership->getKey(),
            ),
        );

        $baseline = DB::table('ownership_register_versions')
            ->where('business_id', $business->getKey())
            ->where('status', 'effective')
            ->where('effective_from', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>', now());
            })
            ->orderByDesc('effective_from')
            ->first();

        $beforeOwnershipRows = DB::table('ownership_register_positions')
            ->where('business_id', $business->getKey())
            ->count();

        $workflow = $this->app->make(ExitCaseWorkflow::class);

        $case = $workflow->createCase(
            $user,
            $business,
            $partnerId,
            ExitTrigger::Voluntary,
            'partner_exit_approval',
            'Planned voluntary exit.',
            CarbonImmutable::now()->addDays(30),
        );

        self::assertNotNull($case);
        self::assertSame(ExitCaseStatus::Draft, $case->status);
        self::assertSame(
            $baseline?->id,
            $case->source_ownership_register_version_id,
        );
        self::assertSame(
            'active',
            DB::table('partners')->where('id', $partnerId)->value('status'),
        );
        self::assertSame('active', $membership->fresh()->access_status->value);
        self::assertSame(
            $beforeOwnershipRows,
            DB::table('ownership_register_positions')
                ->where('business_id', $business->getKey())
                ->count(),
        );
        self::assertSame(
            0,
            DB::table('membership_access_transitions')
                ->where('business_id', $business->getKey())
                ->count(),
        );

        $case = $workflow->recordNotice(
            $user,
            $business,
            (string) $case->getKey(),
            1,
            CarbonImmutable::now()->startOfDay(),
            CarbonImmutable::now()->addDays(30)->startOfDay(),
            30,
            'Thirty-day voluntary Exit Notice.',
        );

        self::assertNotNull($case);
        self::assertSame(ExitCaseStatus::NoticeRecorded, $case->status);
        self::assertSame('exiting', DB::table('partners')->where('id', $partnerId)->value('status'));
        self::assertSame('active', $membership->fresh()->access_status->value);
        self::assertSame(2, (int) $case->revision);

        $case = $workflow->recordShareTreatment(
            $user,
            $business,
            (string) $case->getKey(),
            2,
            'no_shares',
            LeaverClassification::NotApplicable,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );

        self::assertNotNull($case);
        self::assertSame(ExitCaseStatus::TreatmentReady, $case->status);
        self::assertSame(3, (int) $case->revision);

        $case = $workflow->recordPaymentTerms(
            $user,
            $business,
            (string) $case->getKey(),
            3,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'not_applicable',
            null,
        );

        self::assertNotNull($case);
        self::assertSame(4, (int) $case->revision);

        foreach ([
            ['legal', 'legal_terms_ready'],
            ['handover', 'handover_plan_ready'],
            ['post_exit', 'post_exit_obligations_recorded'],
        ] as [$type, $key]) {
            self::assertTrue($workflow->recordRequirement(
                $user,
                $business,
                (string) $case->getKey(),
                4,
                $type,
                $key,
                'met',
                'Verified for the frozen Exit terms.',
            ));
        }

        $case = $workflow->transition(
            $user,
            $business,
            (string) $case->getKey(),
            4,
            ExitCaseStatus::TermsReady,
        );

        self::assertNotNull($case);
        self::assertSame(5, (int) $case->revision);

        $submission = $workflow->submitGovernance(
            $user,
            $business,
            (string) $case->getKey(),
            5,
        );

        self::assertNotNull($submission);

        $binding = DB::table('exit_governance_submissions')
            ->where('business_id', $business->getKey())
            ->where('exit_case_id', $case->getKey())
            ->sole();

        self::assertSame(
            $submission['proposal_version_id'],
            (string) $binding->proposal_version_id,
        );
        self::assertSame(
            $submission['formal_record_version_id'],
            (string) $binding->formal_record_version_id,
        );

        self::assertDatabaseHas('proposal_version_records', [
            'business_id' => $business->getKey(),
            'proposal_version_id' => $submission['proposal_version_id'],
            'formal_record_version_id' => $submission['formal_record_version_id'],
        ]);

        self::assertSame(
            0,
            DB::table('decisions')
                ->where('proposal_version_id', $submission['proposal_version_id'])
                ->count(),
            'System capability must not manufacture Governance authority or Decisions.',
        );
        self::assertSame('active', $membership->fresh()->access_status->value);
        self::assertSame(
            $beforeOwnershipRows,
            DB::table('ownership_register_positions')
                ->where('business_id', $business->getKey())
                ->count(),
        );
    }

    public function test_leaver_classification_has_no_hard_coded_discount_and_requires_rule_reference(): void
    {
        [$user, $business] = $this->fixture(
            'f7-exit-leaver@example.test',
            'F7 Exit Leaver Business',
        );

        $partnerId = $this->seedActivePartner($business, 'Leaver Partner');
        $workflow = $this->app->make(ExitCaseWorkflow::class);

        $case = $workflow->createCase(
            $user,
            $business,
            $partnerId,
            ExitTrigger::AgreementBreach,
            'partner_exit_approval',
            'Agreement-breach Exit review.',
        );

        self::assertNotNull($case);

        $case = $workflow->recordNotice(
            $user,
            $business,
            (string) $case->getKey(),
            1,
            CarbonImmutable::now()->startOfDay(),
            null,
            null,
            'Notice recorded for governed Exit treatment.',
        );

        self::assertNotNull($case);

        try {
            $workflow->recordShareTreatment(
                $user,
                $business,
                (string) $case->getKey(),
                2,
                'no_shares',
                LeaverClassification::Bad,
                null,
                null,
                null,
                null,
                null,
                -12_345,
                null,
                null,
                null,
            );
            self::fail('A classified leaver must identify the applicable rule/evidence.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'rule/evidence reference',
                $exception->getMessage(),
            );
        }

        $case = $workflow->recordShareTreatment(
            $user,
            $business,
            (string) $case->getKey(),
            2,
            'no_shares',
            LeaverClassification::Bad,
            null,
            null,
            null,
            null,
            null,
            -12_345,
            null,
            null,
            'Partnership Agreement clause 12.4; evidence reviewed.',
        );

        self::assertNotNull($case);
        self::assertSame(ExitCaseStatus::TreatmentReady, $case->status);
        self::assertSame(-12_345, $case->leaver_adjustment_minor_units);
        self::assertSame(
            'Partnership Agreement clause 12.4; evidence reviewed.',
            $case->leaver_rule_reference,
        );
        self::assertSame(LeaverClassification::Bad, $case->leaver_classification);
    }

    public function test_stale_exit_revision_is_rejected_without_overwrite(): void
    {
        [$user, $business] = $this->fixture(
            'f7-exit-stale@example.test',
            'F7 Exit Stale Business',
        );

        $partnerId = $this->seedActivePartner($business, 'Stale Exit Partner');
        $workflow = $this->app->make(ExitCaseWorkflow::class);

        $case = $workflow->createCase(
            $user,
            $business,
            $partnerId,
            ExitTrigger::Retirement,
            'partner_exit_approval',
        );

        self::assertNotNull($case);

        $updated = $workflow->recordNotice(
            $user,
            $business,
            (string) $case->getKey(),
            1,
            CarbonImmutable::now()->startOfDay(),
            null,
            null,
            'First accepted Exit Notice.',
        );

        self::assertNotNull($updated);
        self::assertSame(2, (int) $updated->revision);

        $this->expectException(StaleRevision::class);

        $workflow->recordNotice(
            $user,
            $business,
            (string) $case->getKey(),
            1,
            CarbonImmutable::now()->startOfDay(),
            null,
            null,
            'Stale overwrite attempt.',
        );
    }

    private function seedActivePartner(Business $business, string $name): string
    {
        $id = (string) Str::uuid7();

        DB::table('partners')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'display_name' => $name,
            'legal_name' => null,
            'email' => null,
            'status' => 'active',
            'notes' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array{User,Business} */
    private function fixture(string $email, string $businessName): array
    {
        $this->app->make(ProvisionAccount::class)->handle(
            email: $email,
            displayName: 'F7 Exit Tester',
            password: 'F7-Exit-Password-2026',
            languageMode: LanguageMode::English,
            timezone: 'UTC',
            actorLabel: 'F7 Exit Test',
            reason: 'F7 Exit fixture',
            source: 'test',
        );

        $this->app->make(ChangeAccountStatus::class)->handle(
            email: $email,
            targetStatus: AccountStatus::Active,
            actorLabel: 'F7 Exit Test',
            reason: 'Activate F7 Exit fixture',
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
