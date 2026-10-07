<?php

declare(strict_types=1);

namespace Tests\Feature\Partnership;

use App\Application\Evidence\LinkEvidence;
use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Application\Journey\GetMasterBusinessJourney;
use App\Application\Partnership\ContributionDecisionRecordWorkflow;
use App\Application\Partnership\ContributionSetupWorkflow;
use App\Application\Partnership\ContributionWorkflow;
use App\Application\Partnership\GetOwnershipActionPlanReadModel;
use App\Application\Partnership\GetOwnershipChapterWorkspace;
use App\Application\Partnership\GetOwnershipDecisionRecordReadModel;
use App\Application\Partnership\OwnershipActionPlanWorkflow;
use App\Application\Partnership\OwnershipDecisionRecordWorkflow;
use App\Application\Partnership\OwnershipGovernanceWorkflow;
use App\Application\Partnership\OwnershipWorkflow;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Domain\Evidence\Enums\EvidenceConfidentiality;
use App\Domain\Governance\Enums\ActionStatus;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Domain\Partnership\Enums\ContributionType;
use App\Domain\Partnership\ValueObjects\ContributionValue;
use App\Domain\Records\Enums\FormalRecordState;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Evidence\Evidence;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityEstablishment;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyActor;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyRule;
use App\Infrastructure\Persistence\Eloquent\Governance\ProposalReview;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class OwnershipChapterCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_share_planning_is_pinned_to_chapter_two_and_requires_explicit_readiness(): void
    {
        $context = $this->completedContributionChapter('planning');

        $workflow = $this->app->make(OwnershipWorkflow::class);

        $scenarioId = $workflow->createFromAcceptedContributions(
            $context['user'],
            $context['business'],
            'Chapter 3 Ownership',
            'THB',
            10_000,
            '100',
            '10',
        );

        self::assertNotNull($scenarioId);

        try {
            $this->app
                ->make(OwnershipDecisionRecordWorkflow::class)
                ->create(
                    $context['user'],
                    $context['business'],
                    [
                        'decisionOwnerMembershipId' => (string) $context['membership']->getKey(),
                        'reviewDate' => '2027-10-07',
                        'decisionSummary' => 'A Draft scenario is not official Ownership.',
                        'evidenceReferences' => [],
                    ],
                );

            self::fail('Decision Record must require an exact current Effective Ownership source.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'current Effective Ownership Register',
                $exception->getMessage(),
            );
        }

        $scenario = DB::table('ownership_scenarios')
            ->where('id', $scenarioId)
            ->sole();

        self::assertSame(
            $context['registerHash'],
            (string) $scenario->source_accepted_register_hash,
        );
        self::assertSame(
            $context['contributionDecisionId'],
            (string) $scenario->source_contribution_decision_record_id,
        );
        self::assertSame('USD', (string) $scenario->currency);

        $shareClass = DB::table('ownership_scenario_share_classes')
            ->where('ownership_scenario_id', $scenarioId)
            ->sole();

        self::assertSame('Ordinary', (string) $shareClass->name);
        self::assertSame('1.00000000', (string) $shareClass->voting_right_per_share);
        self::assertSame('1.00000000', (string) $shareClass->profit_right_per_share);

        $position = DB::table('ownership_scenario_positions')
            ->where('ownership_scenario_id', $scenarioId)
            ->sole();

        self::assertSame('85000', (string) $position->accepted_contribution_minor_units);
        self::assertSame('8.50000000', (string) $position->shares_issued);
        self::assertNull($position->vesting_applies);

        $workspace = $this->app
            ->make(GetOwnershipChapterWorkspace::class)
            ->execute($context['user'], $context['business']);

        self::assertNotNull($workspace);
        self::assertTrue($workspace['source']['ready']);
        self::assertSame('Accepted', $workspace['source']['rows'][0]['status']);
        self::assertSame('cash', $workspace['source']['rows'][0]['type']);
        self::assertSame('850.00', $workspace['source']['rows'][0]['acceptedValue']);
        self::assertNotEmpty($workspace['source']['rows'][0]['evidence']);
        self::assertNotSame('', $workspace['source']['rows'][0]['description']);
        self::assertSame('8.50000000', $workspace['scenario']['positions'][0]['sharesIssued']);
        self::assertSame('100.0000', $workspace['scenario']['positions'][0]['ownershipPercentage']);
        self::assertTrue($workspace['boundaries']['shareCountCanonical']);
        self::assertTrue($workspace['boundaries']['ownershipPercentDerived']);
        self::assertFalse($workspace['boundaries']['votingIsGovernanceAuthority']);
        self::assertFalse($workspace['boundaries']['ownershipIsSalary']);
        self::assertFalse($workspace['boundaries']['ownershipIsRole']);
        self::assertFalse($workspace['boundaries']['ownershipIsProfitDistributionPolicy']);

        try {
            $workflow->freeze(
                $context['user'],
                $context['business'],
                $scenarioId,
                1,
            );

            self::fail('Unreviewed Chapter 3 terms must not freeze.');
        } catch (ValidationException $exception) {
            self::assertTrue($exception->errors() !== []);
        }

        self::assertTrue(
            $workflow->setShareClassRights(
                $context['user'],
                $context['business'],
                $scenarioId,
                (string) $shareClass->id,
                '1',
                '0.75',
                true,
                'Transfers require the agreed process.',
                'No extra Governance authority.',
                'Founders Ordinary',
            ),
        );

        $reviewedClass = DB::table('ownership_scenario_share_classes')
            ->where('id', $shareClass->id)
            ->sole();

        self::assertSame('Founders Ordinary', (string) $reviewedClass->name);
        self::assertSame('1.00000000', (string) $reviewedClass->voting_right_per_share);
        self::assertSame('0.75000000', (string) $reviewedClass->profit_right_per_share);
        self::assertTrue((bool) $reviewedClass->transfer_allowed);
        self::assertSame(
            'Transfers require the agreed process.',
            (string) $reviewedClass->restrictions,
        );
        self::assertSame(
            'No extra Governance authority.',
            (string) $reviewedClass->special_rights,
        );

        self::assertTrue(
            $workflow->setVestingDecision(
                $context['user'],
                $context['business'],
                $scenarioId,
                (string) $position->id,
                false,
            ),
        );

        self::assertTrue(
            $workflow->setCapacity(
                $context['user'],
                $context['business'],
                $scenarioId,
                '100',
                '10',
            ),
        );

        self::assertTrue(
            $workflow->setIssuanceRule(
                $context['user'],
                $context['business'],
                $scenarioId,
                'Existing Partners through governed approval.',
                '75',
                true,
                'Independent agreed valuation at issuance.',
                true,
            ),
        );

        $position = DB::table('ownership_scenario_positions')
            ->where('id', $position->id)
            ->sole();

        self::assertFalse((bool) $position->vesting_applies);
        self::assertSame(
            (string) $position->shares_issued,
            (string) $position->shares_vested,
        );

        $rule = DB::table('ownership_scenario_issuance_rules')
            ->where('ownership_scenario_id', $scenarioId)
            ->sole();

        self::assertTrue((bool) $rule->preemption_right);
        self::assertSame('75.00', (string) $rule->approval_threshold_percent);
        self::assertTrue((bool) $rule->dilution_acknowledged);

        self::assertTrue(
            $workflow->freeze(
                $context['user'],
                $context['business'],
                $scenarioId,
                $this->scenarioRevision($scenarioId),
            ),
        );

        self::assertDatabaseHas('ownership_scenarios', [
            'id' => $scenarioId,
            'status' => 'frozen',
            'revision' => 6,
        ]);
    }

    public function test_explicit_share_vesting_preserves_schedule_conditions_and_derived_unvested_shares(): void
    {
        $context = $this->completedContributionChapter('vesting');
        $workflow = $this->app->make(OwnershipWorkflow::class);

        $scenarioId = $workflow->createFromAcceptedContributions(
            $context['user'],
            $context['business'],
            'Explicit Vesting Scenario',
            'USD',
            10_000,
            '100',
            '0',
        );

        self::assertNotNull($scenarioId);

        $position = DB::table('ownership_scenario_positions')
            ->where('ownership_scenario_id', $scenarioId)
            ->sole();

        self::assertTrue(
            $workflow->setVestingDecision(
                $context['user'],
                $context['business'],
                $scenarioId,
                (string) $position->id,
                true,
                '2.5',
                '2026-10-07',
                48,
                12,
                'Continued service and agreed delivery milestones.',
                'Unvested Shares return to the agreed pool on early exit.',
            ),
        );

        $workspace = $this->app
            ->make(GetOwnershipChapterWorkspace::class)
            ->execute($context['user'], $context['business']);

        self::assertNotNull($workspace);

        $vesting = $workspace['scenario']['positions'][0];

        self::assertTrue($vesting['vestingApplies']);
        self::assertSame('8.50000000', $vesting['sharesIssued']);
        self::assertSame('2.50000000', $vesting['sharesVested']);
        self::assertSame('6.00000000', $vesting['sharesUnvested']);
        self::assertSame('2026-10-07', $vesting['vestingStartDate']);
        self::assertSame(48, $vesting['vestingPeriodMonths']);
        self::assertSame(12, $vesting['vestingCliffMonths']);
        self::assertSame(
            'Continued service and agreed delivery milestones.',
            $vesting['vestingConditions'],
        );
        self::assertSame(
            'Unvested Shares return to the agreed pool on early exit.',
            $vesting['earlyExitTreatment'],
        );

        try {
            $workflow->setVestingDecision(
                $context['user'],
                $context['business'],
                $scenarioId,
                (string) $position->id,
                true,
                '2.5',
                '2026-10-07',
                12,
                13,
                'Condition',
                'Early exit treatment',
            );

            self::fail('Vesting Cliff greater than Vesting Period must fail.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('vesting', $exception->errors());
        }

        $positionAfter = DB::table('ownership_scenario_positions')
            ->where('id', $position->id)
            ->sole();

        self::assertSame('2.50000000', (string) $positionAfter->shares_vested);
        self::assertSame(48, (int) $positionAfter->vesting_period_months);
        self::assertSame(12, (int) $positionAfter->vesting_cliff_months);
    }

    public function test_stale_contribution_source_blocks_freeze_without_rewriting_captured_ownership(): void
    {
        $context = $this->completedContributionChapter('stale');
        $workflow = $this->app->make(OwnershipWorkflow::class);

        $scenarioId = $workflow->createFromAcceptedContributions(
            $context['user'],
            $context['business'],
            'Stale-source Scenario',
            'USD',
            10_000,
            '100',
            '0',
        );

        self::assertNotNull($scenarioId);
        $this->completeScenarioTerms(
            $workflow,
            $context,
            $scenarioId,
        );

        $scenarioBefore = DB::table('ownership_scenarios')
            ->where('id', $scenarioId)
            ->sole();

        $setup = DB::table('contribution_setups')
            ->where('business_id', $context['business']->getKey())
            ->sole();

        $updated = $this->app
            ->make(ContributionSetupWorkflow::class)
            ->save(
                $context['user'],
                $context['business'],
                (int) $setup->revision,
                [
                    'valuationDate' => '2026-09-27',
                    'currency' => 'USD',
                    'periodStart' => '2026-01-01',
                    'periodEnd' => '2026-12-31',
                    'valuationOwnerMembershipId' => (string) $context['membership']->getKey(),
                    'approverMembershipIds' => [
                        (string) $context['membership']->getKey(),
                    ],
                ],
            );

        self::assertNotNull($updated);
        self::assertFalse($updated['created']);

        $workspace = $this->app
            ->make(GetOwnershipChapterWorkspace::class)
            ->execute($context['user'], $context['business']);

        self::assertNotNull($workspace);
        self::assertTrue($workspace['scenario']['sourceStale']);

        try {
            $workflow->freeze(
                $context['user'],
                $context['business'],
                $scenarioId,
                $this->scenarioRevision($scenarioId),
            );

            self::fail('A stale Accepted Contribution source must not freeze.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('contributions', $exception->errors());
            self::assertStringContainsString(
                'Source changed',
                $exception->errors()['contributions'][0],
            );
        }

        $scenarioAfter = DB::table('ownership_scenarios')
            ->where('id', $scenarioId)
            ->sole();

        self::assertSame(
            (string) $scenarioBefore->source_accepted_register_hash,
            (string) $scenarioAfter->source_accepted_register_hash,
        );
        self::assertSame('draft', (string) $scenarioAfter->status);
        self::assertSame(5, (int) $scenarioAfter->revision);
    }

    public function test_effective_register_decision_record_optional_actions_and_master_journey_remain_separate_truth(): void
    {
        $context = $this->completedContributionChapter('completion');
        $workflow = $this->app->make(OwnershipWorkflow::class);

        $scenarioId = $workflow->createFromAcceptedContributions(
            $context['user'],
            $context['business'],
            'Governed Chapter 3 Ownership',
            'USD',
            10_000,
            '100',
            '10',
        );

        self::assertNotNull($scenarioId);
        $this->completeScenarioTerms(
            $workflow,
            $context,
            $scenarioId,
        );

        self::assertTrue(
            $workflow->freeze(
                $context['user'],
                $context['business'],
                $scenarioId,
                $this->scenarioRevision($scenarioId),
            ),
        );

        $governance = $this->app->make(
            OwnershipGovernanceWorkflow::class,
        );

        $submission = $governance->submitGovernance(
            $context['user'],
            $context['business'],
            $scenarioId,
            now()->subMinute(),
        );

        self::assertNotNull($submission);

        self::assertTrue(
            $governance->advanceContentReview(
                $context['user'],
                $context['business'],
                $submission['id'],
                FormalRecordState::UnderReview,
            ),
        );

        self::assertTrue(
            $governance->advanceContentReview(
                $context['user'],
                $context['business'],
                $submission['id'],
                FormalRecordState::Approved,
            ),
        );

        $this->approveProposalVersion(
            $context['business'],
            $context['membership'],
            $submission['proposal_version_id'],
        );

        $decision = $this->approveGovernanceDecision(
            $context['user'],
            $context['business'],
            $submission['proposal_version_id'],
            'ownership_approval',
        );

        self::assertTrue(
            $governance->effectApprovedGovernance(
                $context['user'],
                $context['business'],
                $submission['id'],
            ),
        );

        $register = DB::table('ownership_register_versions')
            ->where('business_id', $context['business']->getKey())
            ->where('status', 'effective')
            ->sole();

        self::assertSame(
            $context['registerHash'],
            (string) $register->source_accepted_register_hash,
        );
        self::assertSame(
            $context['contributionDecisionId'],
            (string) $register->source_contribution_decision_record_id,
        );
        self::assertSame(
            (string) $decision->getKey(),
            (string) $register->governance_decision_id,
        );

        $registerPosition = DB::table('ownership_register_positions')
            ->where('ownership_register_version_id', $register->id)
            ->sole();

        self::assertFalse((bool) $registerPosition->vesting_applies);
        self::assertSame('8.50000000', (string) $registerPosition->shares_issued);
        self::assertSame('6.37500000', (string) $registerPosition->profit_rights);

        $registerRule = DB::table('ownership_register_issuance_rules')
            ->where('ownership_register_version_id', $register->id)
            ->sole();

        self::assertSame('75.00', (string) $registerRule->approval_threshold_percent);
        self::assertTrue((bool) $registerRule->preemption_right);
        self::assertTrue((bool) $registerRule->dilution_acknowledged);

        $chapterBeforeDecision = $this->app
            ->make(GetOwnershipChapterWorkspace::class)
            ->execute($context['user'], $context['business']);

        self::assertNotNull($chapterBeforeDecision);
        self::assertFalse($chapterBeforeDecision['progress']['chapterComplete']);
        self::assertContains(
            $context['user']->email,
            $chapterBeforeDecision['scenario']['approval']['approvedBy'],
        );
        self::assertNotEmpty($chapterBeforeDecision['scenarioHistory']);
        self::assertNotEmpty($chapterBeforeDecision['approvalHistory']);
        self::assertNotEmpty($chapterBeforeDecision['registerHistory']);
        self::assertFalse($chapterBeforeDecision['actionPlan']['available']);
        self::assertSame([], $chapterBeforeDecision['actionPlan']['actions']);

        $crossBusiness = Business::query()->create([
            'name' => 'Other Business '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);
        [, $foreignMembership] = $this->userMembership(
            $crossBusiness,
            'foreign-owner',
        );

        try {
            $this->app
                ->make(OwnershipDecisionRecordWorkflow::class)
                ->create(
                    $context['user'],
                    $context['business'],
                    [
                        'decisionOwnerMembershipId' => (string) $foreignMembership->getKey(),
                        'reviewDate' => '2027-10-07',
                        'decisionSummary' => 'Must reject a foreign Business owner.',
                        'evidenceReferences' => [],
                    ],
                );

            self::fail('Cross-Business Decision Owner must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'active member of this Business',
                $exception->getMessage(),
            );
        }

        $registerSnapshotBeforeRecord = (array) DB::table(
            'ownership_register_versions',
        )
            ->where('id', $register->id)
            ->sole();

        $recordResult = $this->app
            ->make(OwnershipDecisionRecordWorkflow::class)
            ->create(
                $context['user'],
                $context['business'],
                [
                    'decisionOwnerMembershipId' => (string) $context['membership']->getKey(),
                    'reviewDate' => '2027-10-07',
                    'decisionSummary' => 'Current governed Ownership recorded for review.',
                    'evidenceReferences' => [
                        'Governed Ownership proposal and decision',
                    ],
                ],
            );

        self::assertNotNull($recordResult);
        self::assertTrue($recordResult['created']);
        self::assertSame((string) $register->id, $recordResult['registerVersionId']);

        $duplicate = $this->app
            ->make(OwnershipDecisionRecordWorkflow::class)
            ->create(
                $context['user'],
                $context['business'],
                [
                    'decisionOwnerMembershipId' => (string) $context['membership']->getKey(),
                    'reviewDate' => '2027-10-07',
                    'decisionSummary' => 'Idempotent duplicate attempt.',
                    'evidenceReferences' => [],
                ],
            );

        self::assertNotNull($duplicate);
        self::assertFalse($duplicate['created']);
        self::assertSame($recordResult['id'], $duplicate['id']);
        self::assertDatabaseCount('ownership_decision_records', 1);

        $registerSnapshotAfterRecord = (array) DB::table(
            'ownership_register_versions',
        )
            ->where('id', $register->id)
            ->sole();

        self::assertSame(
            $registerSnapshotBeforeRecord,
            $registerSnapshotAfterRecord,
            'Recording the Ownership Decision must not mutate the Share Register.',
        );

        $read = $this->app
            ->make(GetOwnershipDecisionRecordReadModel::class)
            ->execute($context['user'], $context['business']);

        self::assertNotNull($read);
        self::assertTrue($read['recorded']);
        self::assertSame((string) $register->id, $read['current']['registerVersionId']);
        self::assertSame('Current / Effective', $read['current']['status']);
        self::assertSame(
            ['Governed Ownership proposal and decision'],
            $read['current']['evidenceReferences'],
        );
        self::assertSame(
            substr((string) $register->effective_from, 0, 10),
            $read['current']['effectiveDate'],
        );
        self::assertNotNull($read['current']['approvalDate']);
        self::assertContains(
            $context['user']->email,
            $read['current']['approvedBy'],
        );
        self::assertTrue($read['semantics']['approvedByDerived']);
        self::assertTrue($read['semantics']['approvalDateDerived']);
        self::assertTrue($read['semantics']['effectiveDateDerived']);
        self::assertFalse($read['semantics']['recordChangesOwnership']);

        $futureScenarioId = $workflow->createFromAcceptedContributions(
            $context['user'],
            $context['business'],
            'Future Ownership Amendment',
            'USD',
            20_000,
            '100',
            '10',
        );

        self::assertNotNull($futureScenarioId);

        $stillCurrent = $workflow->currentEffectiveRegisterVersion(
            $context['user'],
            $context['business'],
        );

        self::assertNotNull($stillCurrent);
        self::assertSame((string) $register->id, (string) $stillCurrent->id);

        $readAfterDraft = $this->app
            ->make(GetOwnershipDecisionRecordReadModel::class)
            ->execute($context['user'], $context['business']);

        self::assertNotNull($readAfterDraft);
        self::assertTrue($readAfterDraft['recorded']);
        self::assertSame(
            $recordResult['id'],
            $readAfterDraft['current']['id'],
        );

        $actionsBefore = $this->app
            ->make(GetOwnershipActionPlanReadModel::class)
            ->execute($context['user'], $context['business']);

        self::assertNotNull($actionsBefore);
        self::assertTrue($actionsBefore['available']);
        self::assertSame([], $actionsBefore['actions']);
        self::assertTrue($actionsBefore['zeroActionsAllowed']);

        $chapterAfterDecision = $this->app
            ->make(GetOwnershipChapterWorkspace::class)
            ->execute($context['user'], $context['business']);

        self::assertNotNull($chapterAfterDecision);
        self::assertTrue($chapterAfterDecision['progress']['chapterComplete']);

        $journey = $this->app
            ->make(GetMasterBusinessJourney::class)
            ->execute($context['user'], $context['business'], null);

        $equity = collect($journey['steps'])->firstWhere('key', 'equity');
        self::assertNotNull($equity);
        self::assertSame('recorded', $equity['state']);

        $registerSnapshotBeforeAction = (array) DB::table(
            'ownership_register_versions',
        )
            ->where('id', $register->id)
            ->sole();

        $foreignAction = $this->app
            ->make(OwnershipActionPlanWorkflow::class)
            ->createCustom(
                $context['user'],
                $context['business'],
                (string) $foreignMembership->getKey(),
                'Foreign assignee must fail',
                null,
                null,
            );

        self::assertNull($foreignAction);
        self::assertDatabaseCount('ownership_action_links', 0);

        $action = $this->app
            ->make(OwnershipActionPlanWorkflow::class)
            ->createCustom(
                $context['user'],
                $context['business'],
                (string) $context['membership']->getKey(),
                'Review implementation notes',
                'Tracking only; this cannot change Ownership.',
                '2027-10-07',
            );

        self::assertInstanceOf(Action::class, $action);

        $updatedAction = $this->app
            ->make(OwnershipActionPlanWorkflow::class)
            ->updateStatus(
                $context['user'],
                $context['business'],
                (string) $action->getKey(),
                ActionStatus::Completed,
            );

        self::assertInstanceOf(Action::class, $updatedAction);
        self::assertSame(ActionStatus::Completed, $updatedAction->status);

        $registerSnapshotAfterAction = (array) DB::table(
            'ownership_register_versions',
        )
            ->where('id', $register->id)
            ->sole();

        self::assertSame(
            $registerSnapshotBeforeAction,
            $registerSnapshotAfterAction,
            'Completing an Ownership Action must not mutate the Share Register.',
        );

        $chapterAfterAction = $this->app
            ->make(GetOwnershipChapterWorkspace::class)
            ->execute($context['user'], $context['business']);

        self::assertNotNull($chapterAfterAction);
        self::assertTrue($chapterAfterAction['progress']['chapterComplete']);
    }

    public function test_read_only_member_can_inspect_ownership_without_mutation_authority(): void
    {
        $context = $this->completedContributionChapter('read-only');
        $workflow = $this->app->make(OwnershipWorkflow::class);

        $scenarioId = $workflow->createFromAcceptedContributions(
            $context['user'],
            $context['business'],
            'Read-only Inspection',
            'USD',
            10_000,
            '100',
            '10',
        );

        self::assertNotNull($scenarioId);

        [$reader, $readerMembership] = $this->userMembership(
            $context['business'],
            'ownership-reader',
        );

        foreach ([
            CapabilityCatalog::RECORDS_VIEW,
            CapabilityCatalog::OWNERSHIP_VIEW,
            CapabilityCatalog::CONTRIBUTIONS_VIEW,
        ] as $capability) {
            $this->grant(
                $context['business'],
                $readerMembership,
                $capability,
            );
        }

        $workspace = $this->app
            ->make(GetOwnershipChapterWorkspace::class)
            ->execute($reader, $context['business']);

        self::assertNotNull($workspace);
        self::assertFalse($workspace['canManage']);
        self::assertSame('850.00', $workspace['source']['rows'][0]['acceptedValue']);
        self::assertSame($scenarioId, $workspace['scenario']['id']);

        $before = (array) DB::table('ownership_scenarios')
            ->where('id', $scenarioId)
            ->sole();

        self::assertFalse(
            $workflow->setCapacity(
                $reader,
                $context['business'],
                $scenarioId,
                '200',
                '20',
            ),
        );

        $after = (array) DB::table('ownership_scenarios')
            ->where('id', $scenarioId)
            ->sole();

        self::assertSame($before, $after);
        self::assertNull(
            $this->app
                ->make(OwnershipDecisionRecordWorkflow::class)
                ->create(
                    $reader,
                    $context['business'],
                    [
                        'decisionOwnerMembershipId' => (string) $readerMembership->getKey(),
                        'reviewDate' => '2027-10-07',
                        'decisionSummary' => 'Read-only member must not create records.',
                        'evidenceReferences' => [],
                    ],
                ),
        );
    }

    /**
     * @return array{
     *   user:User,
     *   business:Business,
     *   membership:Membership,
     *   contributionId:string,
     *   registerHash:string,
     *   contributionDecisionId:string
     * }
     */
    private function completedContributionChapter(string $prefix): array
    {
        [$user, $business, $membership] = $this->governanceContext($prefix);

        foreach ([
            CapabilityCatalog::RECORDS_VIEW,
            CapabilityCatalog::OWNERSHIP_VIEW,
            CapabilityCatalog::OWNERSHIP_MANAGE,
        ] as $capability) {
            $this->grant($business, $membership, $capability);
        }

        $this->grant(
            $business,
            $membership,
            CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
            [
                Action::class,
                Decision::class,
                FormalRecordVersion::class,
            ],
        );

        $setup = $this->app
            ->make(ContributionSetupWorkflow::class)
            ->save(
                $user,
                $business,
                0,
                [
                    'valuationDate' => '2026-09-26',
                    'currency' => 'USD',
                    'periodStart' => '2026-01-01',
                    'periodEnd' => '2026-12-31',
                    'valuationOwnerMembershipId' => (string) $membership->getKey(),
                    'approverMembershipIds' => [
                        (string) $membership->getKey(),
                    ],
                ],
            );

        self::assertNotNull($setup);

        $this->establishFormationAuthority(
            $business,
            $user,
            $membership,
            [
                'contribution_approval',
                'contribution_acceptance',
                'ownership_approval',
            ],
        );

        $contributionId = $this->reviewedContributionWithEvidence(
            $user,
            $business,
            $membership,
            'Chapter 3 source '.$prefix,
        );

        $workflow = $this->app->make(ContributionWorkflow::class);

        $approvalSubmission = $workflow->submitGovernance(
            $user,
            $business,
            $contributionId,
            'approval',
        );

        self::assertNotNull($approvalSubmission);
        self::assertTrue($workflow->advanceContentReview(
            $user,
            $business,
            $approvalSubmission['id'],
            FormalRecordState::UnderReview,
        ));
        self::assertTrue($workflow->advanceContentReview(
            $user,
            $business,
            $approvalSubmission['id'],
            FormalRecordState::Approved,
        ));

        $this->approveProposalVersion(
            $business,
            $membership,
            $approvalSubmission['proposal_version_id'],
        );
        $this->approveGovernanceDecision(
            $user,
            $business,
            $approvalSubmission['proposal_version_id'],
            'contribution_approval',
        );

        self::assertTrue($workflow->syncGovernanceDecision(
            $user,
            $business,
            $approvalSubmission['id'],
        ));

        self::assertNotNull($workflow->recordDelivery(
            $user,
            $business,
            $contributionId,
            $this->contributionRevision($contributionId),
            new ContributionValue('900.00'),
            new DateTimeImmutable('2026-09-26T10:30:00+00:00'),
            'Approved value delivered.',
            true,
        ));

        $acceptanceSubmission = $workflow->submitGovernance(
            $user,
            $business,
            $contributionId,
            'acceptance',
            new ContributionValue('850.00'),
        );

        self::assertNotNull($acceptanceSubmission);
        self::assertTrue($workflow->advanceContentReview(
            $user,
            $business,
            $acceptanceSubmission['id'],
            FormalRecordState::UnderReview,
        ));
        self::assertTrue($workflow->advanceContentReview(
            $user,
            $business,
            $acceptanceSubmission['id'],
            FormalRecordState::Approved,
        ));

        $this->approveProposalVersion(
            $business,
            $membership,
            $acceptanceSubmission['proposal_version_id'],
        );
        $this->approveGovernanceDecision(
            $user,
            $business,
            $acceptanceSubmission['proposal_version_id'],
            'contribution_acceptance',
        );

        self::assertTrue($workflow->syncGovernanceDecision(
            $user,
            $business,
            $acceptanceSubmission['id'],
        ));

        $decision = $this->app
            ->make(ContributionDecisionRecordWorkflow::class)
            ->create(
                $user,
                $business,
                [
                    'decisionOwnerMembershipId' => (string) $membership->getKey(),
                    'effectiveDate' => '2026-09-27',
                    'reviewDate' => '2027-03-27',
                    'decisionSummary' => 'Governed Accepted Contribution source for Chapter 3.',
                    'evidenceReferences' => ['Governed acceptance evidence'],
                ],
            );

        self::assertNotNull($decision);
        self::assertTrue($decision['created']);

        return [
            'user' => $user,
            'business' => $business,
            'membership' => $membership,
            'contributionId' => $contributionId,
            'registerHash' => $decision['registerHash'],
            'contributionDecisionId' => $decision['id'],
        ];
    }

    /**
     * @param array{
     *   user:User,
     *   business:Business,
     *   membership:Membership,
     *   contributionId:string,
     *   registerHash:string,
     *   contributionDecisionId:string
     * } $context
     */
    private function completeScenarioTerms(
        OwnershipWorkflow $workflow,
        array $context,
        string $scenarioId,
    ): void {
        $shareClass = DB::table('ownership_scenario_share_classes')
            ->where('ownership_scenario_id', $scenarioId)
            ->sole();

        $position = DB::table('ownership_scenario_positions')
            ->where('ownership_scenario_id', $scenarioId)
            ->sole();

        self::assertTrue($workflow->setShareClassRights(
            $context['user'],
            $context['business'],
            $scenarioId,
            (string) $shareClass->id,
            '1',
            '0.75',
            true,
            'Transfers require the governed process.',
            'No Governance authority is created by this right.',
        ));

        self::assertTrue($workflow->setVestingDecision(
            $context['user'],
            $context['business'],
            $scenarioId,
            (string) $position->id,
            false,
        ));

        self::assertTrue($workflow->setCapacity(
            $context['user'],
            $context['business'],
            $scenarioId,
            '100',
            '10',
        ));

        self::assertTrue($workflow->setIssuanceRule(
            $context['user'],
            $context['business'],
            $scenarioId,
            'Existing Partners through governed approval.',
            '75',
            true,
            'Independent agreed valuation at issuance.',
            true,
        ));
    }

    /** @return array{User,Business,Membership} */
    private function governanceContext(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'Ownership Chapter '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$user, $membership] = $this->userMembership(
            $business,
            $prefix,
        );

        $this->grant(
            $business,
            $membership,
            CapabilityCatalog::RECORDS_MANAGE,
            [
                FormalRecordFamily::class,
                FormalRecordVersion::class,
                Proposal::class,
                ProposalVersion::class,
            ],
        );

        $this->grant(
            $business,
            $membership,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            [
                ProposalVersion::class,
                Decision::class,
                FormalRecordVersion::class,
            ],
        );

        $this->grant(
            $business,
            $membership,
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
        );
        $this->grant(
            $business,
            $membership,
            CapabilityCatalog::CONTRIBUTIONS_VIEW,
        );

        return [$user, $business, $membership];
    }

    /** @return array{User,Membership} */
    private function userMembership(
        Business $business,
        string $prefix,
    ): array {
        $user = User::query()->create([
            'email' => $prefix.'-'.Str::uuid7().'@example.test',
            'password' => 'not-a-real-hash',
            'status' => 'active',
            'password_changed_at' => now(),
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        return [$user, $membership];
    }

    private function partner(Business $business): string
    {
        $id = (string) Str::uuid7();

        DB::table('partners')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'display_name' => 'Ownership Partner',
            'legal_name' => null,
            'email' => null,
            'status' => 'prospective',
            'notes' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function reviewedContributionWithEvidence(
        User $user,
        Business $business,
        Membership $membership,
        string $description,
    ): string {
        $workflow = $this->app->make(ContributionWorkflow::class);

        $contribution = $workflow->create(
            $user,
            $business,
            $this->partner($business),
            ContributionType::Cash,
            'USD',
            $description,
            new ContributionValue('1000.00'),
            'Evidence and governance required.',
            '2026-09-26',
            '2026-10-10',
            [
                'amount_committed' => '1000.00',
                'amount_received' => '0.00',
                'payment_date' => null,
            ],
        );

        self::assertNotNull($contribution);

        [$document, $evidence] = $this->evidenceFixture(
            $business,
            $membership,
            Str::slug($description),
        );

        $this->allowDocumentManage(
            $business,
            $membership,
            $document,
        );

        $link = $this->app
            ->make(LinkEvidence::class)
            ->execute(
                $user,
                $business,
                (string) $evidence->getKey(),
                'contribution',
                $contribution['id'],
            );

        self::assertNotNull($link);

        self::assertTrue($workflow->review(
            $user,
            $business,
            $contribution['id'],
            $this->contributionRevision($contribution['id']),
            new ContributionValue('900.00'),
            'Verified Contribution evidence and valuation.',
        ));

        return $contribution['id'];
    }

    /** @return array{Document,Evidence} */
    private function evidenceFixture(
        Business $business,
        Membership $membership,
        string $label,
    ): array {
        $document = Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => 'Ownership Evidence '.$label,
            'category' => DocumentCategory::CorporateLegal->value,
            'created_by_membership_id' => $membership->getKey(),
        ]);

        $version = DocumentVersion::query()->create([
            'business_id' => $business->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'original_filename' => $label.'.pdf',
            'storage_key' => 'documents/ownership/'.Str::uuid7().'/source-v1.pdf',
            'size_bytes' => 2048,
            'mime_type' => 'application/pdf',
            'content_sha256' => hash('sha256', $label.'-'.Str::uuid7()),
            'uploaded_by_membership_id' => $membership->getKey(),
            'effective_from' => null,
            'supersedes_document_version_id' => null,
        ]);

        $evidence = Evidence::query()->create([
            'business_id' => $business->getKey(),
            'document_version_id' => $version->getKey(),
            'confidentiality' => EvidenceConfidentiality::Standard->value,
            'source_date' => '2026-09-26',
            'submitted_by_membership_id' => $membership->getKey(),
            'verified_at' => now(),
            'verified_by_membership_id' => $membership->getKey(),
            'verification_method' => 'manual_review',
            'verification_note' => 'Verified Ownership Chapter integration evidence.',
        ]);

        return [$document, $evidence];
    }

    private function allowDocumentManage(
        Business $business,
        Membership $membership,
        Document $document,
    ): void {
        DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => DocumentAccessRight::Manage->value,
            'effect' => 'allow',
        ]);
    }

    /** @param list<class-string> $resourceTypes */
    private function grant(
        Business $business,
        Membership $membership,
        string $capability,
        array $resourceTypes = [],
    ): void {
        $permission = Permission::query()->firstOrCreate([
            'key' => $capability,
        ]);

        PermissionGrant::query()->firstOrCreate([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
        ], [
            'effect' => 'allow',
        ]);

        foreach ($resourceTypes as $resourceType) {
            AccessPolicy::query()->firstOrCreate([
                'business_id' => $business->getKey(),
                'membership_id' => $membership->getKey(),
                'permission_profile_id' => null,
                'permission_id' => $permission->getKey(),
                'resource_type' => $resourceType,
            ], [
                'effect' => 'allow',
            ]);
        }
    }

    /** @param list<string> $decisionTypes */
    private function establishFormationAuthority(
        Business $business,
        User $user,
        Membership $membership,
        array $decisionTypes,
    ): void {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'formation_authority_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $contentHash = hash(
            'sha256',
            'ownership-formation-authority-'.$business->getKey(),
        );

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Ownership Chapter temporary Formation Authority.',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => $contentHash,
            'frozen_at' => null,
        ]);

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'from_state' => null,
            'to_state' => 'draft',
            'transitioned_by_user_id' => $user->getKey(),
            'occurred_at' => now()->subSeconds(10),
        ]);

        foreach (array_values($decisionTypes) as $index => $decisionType) {
            $rule = FormationAuthorityPolicyRule::query()->create([
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => $index + 1,
                'decision_type' => $decisionType,
                'decision_method' => DecisionMethod::Approval->value,
                'required_approvals' => 1,
                'required_votes' => 0,
                'quorum_count' => 1,
                'signature_required' => false,
                'reserved_matter' => false,
                'amount_min' => null,
                'amount_max' => null,
            ]);

            FormationAuthorityPolicyActor::query()->create([
                'business_id' => $business->getKey(),
                'formation_authority_policy_rule_id' => $rule->getKey(),
                'membership_id' => $membership->getKey(),
                'capacity' => 'Formation Approver',
                'can_approve' => true,
                'can_vote' => false,
                'can_sign' => false,
            ]);
        }

        $version->frozen_at = now()->subSeconds(5);
        $version->save();

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 2,
            'from_state' => 'draft',
            'to_state' => 'ready_for_review',
            'transitioned_by_user_id' => $user->getKey(),
            'occurred_at' => now(),
        ]);

        FormationAuthorityEstablishment::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'established_by_membership_id' => $membership->getKey(),
            'establishment_hash' => $contentHash,
            'established_at' => now(),
        ]);
    }

    private function approveProposalVersion(
        Business $business,
        Membership $membership,
        string $proposalVersionId,
    ): void {
        $review = ProposalReview::query()->create([
            'business_id' => $business->getKey(),
            'proposal_version_id' => $proposalVersionId,
            'reviewer_membership_id' => $membership->getKey(),
            'created_by_membership_id' => $membership->getKey(),
            'status' => 'open',
            'outcome' => null,
            'notes' => null,
            'due_at' => null,
            'resolved_at' => null,
        ]);

        $review->fill([
            'status' => 'completed',
            'outcome' => 'approved',
            'notes' => 'Approved exact frozen Ownership Proposal Version.',
            'resolved_at' => now(),
        ])->save();
    }

    private function approveGovernanceDecision(
        User $user,
        Business $business,
        string $proposalVersionId,
        string $decisionType,
    ): Decision {
        $decision = $this->app
            ->make(OpenGovernanceDecision::class)
            ->execute(
                $user,
                $business,
                $proposalVersionId,
                new DecisionType($decisionType),
            );

        self::assertInstanceOf(Decision::class, $decision);

        $approval = $this->app
            ->make(RecordGovernanceApproval::class)
            ->execute(
                $user,
                $business,
                (string) $decision->getKey(),
                ApprovalOutcome::Approved,
                'Exact frozen Ownership proposal approved.',
            );

        self::assertNotNull($approval);

        $resolved = $this->app
            ->make(ResolveGovernanceDecision::class)
            ->approve(
                $user,
                $business,
                (string) $decision->getKey(),
            );

        self::assertNotNull($resolved);

        return $decision;
    }

    private function scenarioRevision(string $scenarioId): int
    {
        return (int) DB::table('ownership_scenarios')
            ->where('id', $scenarioId)
            ->value('revision');
    }

    private function contributionRevision(string $contributionId): int
    {
        return (int) DB::table('contributions')
            ->where('id', $contributionId)
            ->value('revision');
    }
}
