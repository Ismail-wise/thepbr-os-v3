<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\CapitalActionPlanWorkflow;
use App\Application\Formation\CapitalApprovalWorkflow;
use App\Application\Formation\CapitalDecisionRecordWorkflow;
use App\Application\Formation\GetCapitalActionPlanReadModel;
use App\Application\Formation\RefreshCapitalComparisonDraftFromCanonical;
use App\Application\Formation\SaveCapitalComparisonDraft;
use App\Application\Formation\SaveCapitalPlanningDraft;
use App\Application\Formation\SaveCapitalRuleDraft;
use App\Application\Governance\EstablishInitialFormationAuthority;
use App\Application\Governance\FormationAuthorityPolicyWorkflow;
use App\Application\Journey\GetMasterBusinessJourney;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Capital\CapitalActionPlanContract;
use App\Domain\Capital\CapitalApprovalContract;
use App\Domain\Governance\Enums\ActionStatus;
use App\Domain\Governance\Enums\ProposalReviewOutcome;
use App\Domain\Identity\Enums\AccountStatus;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class CapitalActionPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_plan_requires_an_exact_recorded_capital_decision(): void
    {
        [$user, $business, $membership] = $this->business(
            'capital-act-prereq@example.test',
            'Capital Act Prerequisites',
        );

        $workflow = $this->workflow();

        try {
            $workflow->createCustom(
                $user,
                $business,
                (string) $membership->getKey(),
                'Prepare opening bank account',
                null,
                null,
            );
            self::fail('Capital Action must require a recorded Capital Decision.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Record the approved Capital Decision',
                $exception->getMessage(),
            );
        }

        $this->approveCapital(
            $user,
            $business,
            $membership,
        );

        try {
            $workflow->createCustom(
                $user,
                $business,
                (string) $membership->getKey(),
                'Prepare opening bank account',
                null,
                null,
            );
            self::fail('Approved but unrecorded Capital must not create an Action.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Record the approved Capital Decision',
                $exception->getMessage(),
            );
        }

        $this->recordDecision(
            $user,
            $business,
            $membership,
        );

        $action = $workflow->createCustom(
            $user,
            $business,
            (string) $membership->getKey(),
            'Prepare opening bank account',
            'Prepare documents for review.',
            '2027-02-15',
        );

        self::assertNotNull($action);
        $this->assertDatabaseCount('capital_action_links', 1);
    }

    public function test_generic_action_is_reused_and_exact_record_decision_formal_version_and_business_are_pinned(): void
    {
        [$user, $business, $membership] = $this->business(
            'capital-act-link@example.test',
            'Capital Act Linkage',
        );

        $this->approveCapital($user, $business, $membership);
        $this->recordDecision($user, $business, $membership);

        $record = DB::table('capital_decision_records')
            ->where('business_id', $business->getKey())
            ->sole();

        self::assertFalse(Schema::hasTable('capital_actions'));

        $beforeActions = DB::table('actions')
            ->where('business_id', $business->getKey())
            ->count();

        $action = $this->workflow()->createSuggested(
            $user,
            $business,
            CapitalActionPlanContract::SUGGESTION_REVIEW,
            (string) $membership->getKey(),
        );

        self::assertNotNull($action);
        self::assertSame($beforeActions + 1, DB::table('actions')
            ->where('business_id', $business->getKey())
            ->count());

        $link = DB::table('capital_action_links')
            ->where('business_id', $business->getKey())
            ->where('action_id', $action->getKey())
            ->sole();

        self::assertSame(
            (string) $record->id,
            (string) $link->capital_decision_record_id,
        );
        self::assertSame(
            CapitalActionPlanContract::SUGGESTION_REVIEW,
            (string) $link->suggestion_key,
        );

        $generic = Action::query()
            ->where('business_id', $business->getKey())
            ->whereKey($action->getKey())
            ->sole();

        self::assertSame(
            (string) $record->governance_decision_id,
            (string) $generic->decision_id,
        );
        self::assertSame(
            (string) $record->formal_record_version_id,
            (string) $generic->formal_record_version_id,
        );
        self::assertSame(
            (string) $membership->getKey(),
            (string) $generic->assigned_membership_id,
        );
        self::assertSame(ActionStatus::Open, $generic->status);
        self::assertSame('2027-04-15', $generic->due_at?->format('Y-m-d'));

        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'governance.action.created',
            'target_id' => $action->getKey(),
        ]);
        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'capital.action.linked',
            'target_id' => $action->getKey(),
        ]);

        $read = $this->readModel($user, $business);

        self::assertNotNull($read);
        self::assertTrue($read['available']);
        self::assertTrue($read['established']);
        self::assertSame(1, $read['outstandingCount']);
        self::assertSame(0, $read['completedCount']);
        self::assertSame('Base', ucfirst((string) $read['sourceDecisionRecord']['preferredPlan']));
        self::assertSame('1430.00', $read['sourceDecisionRecord']['totalCapitalRequirement']);
        self::assertSame('930.00', $read['sourceDecisionRecord']['fundingGap']);
        self::assertSame(
            'Approved Base Capital Plan for opening.',
            $read['sourceDecisionRecord']['decisionSummary'],
        );
        self::assertSame('capital-action-plan-v1', $read['actionPlanContractVersion']);
    }

    public function test_suggestions_are_explicit_and_derive_only_from_frozen_approved_rule_and_signature_truth(): void
    {
        [$user, $business, $membership] = $this->business(
            'capital-act-suggestions@example.test',
            'Capital Act Suggestions',
        );

        $this->approveCapital(
            $user,
            $business,
            $membership,
            signatureRequired: true,
            responses: [
                'reduce_scope',
                'delay',
                'borrow',
                'capital_call',
            ],
        );
        $this->recordDecision($user, $business, $membership);

        self::assertSame(
            0,
            DB::table('capital_action_links')
                ->where('business_id', $business->getKey())
                ->count(),
            'Suggestions must not auto-create Actions.',
        );

        $read = $this->readModel($user, $business);

        self::assertNotNull($read);

        $keys = collect($read['suggestions'])
            ->pluck('key')
            ->all();

        foreach ([
            CapitalActionPlanContract::SUGGESTION_REVIEW,
            CapitalActionPlanContract::SUGGESTION_REDUCE_SCOPE,
            CapitalActionPlanContract::SUGGESTION_DELAY,
            CapitalActionPlanContract::SUGGESTION_BORROW,
            CapitalActionPlanContract::SUGGESTION_CAPITAL_CALL,
            CapitalActionPlanContract::SUGGESTION_SIGNATURE,
        ] as $expected) {
            self::assertContains($expected, $keys);
        }

        $planningBefore = DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->sole();
        $comparisonBefore = DB::table('capital_comparison_drafts')
            ->where('business_id', $business->getKey())
            ->sole();
        $decisionRecordBefore = DB::table('capital_decision_records')
            ->where('business_id', $business->getKey())
            ->sole();

        $createdSuggestions = [];

        foreach ([
            CapitalActionPlanContract::SUGGESTION_REDUCE_SCOPE,
            CapitalActionPlanContract::SUGGESTION_DELAY,
            CapitalActionPlanContract::SUGGESTION_BORROW,
            CapitalActionPlanContract::SUGGESTION_CAPITAL_CALL,
            CapitalActionPlanContract::SUGGESTION_SIGNATURE,
        ] as $suggestionKey) {
            $created = $this->workflow()->createSuggested(
                $user,
                $business,
                $suggestionKey,
                (string) $membership->getKey(),
            );

            self::assertNotNull($created);
            $createdSuggestions[$suggestionKey] = $created;
        }

        self::assertSame(
            'Prepare Capital Call / Contribution process',
            $createdSuggestions[
                CapitalActionPlanContract::SUGGESTION_CAPITAL_CALL
            ]->title,
        );

        self::assertSame(
            5,
            DB::table('capital_action_links')
                ->where('business_id', $business->getKey())
                ->count(),
        );

        $planningAfter = DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->sole();
        $comparisonAfter = DB::table('capital_comparison_drafts')
            ->where('business_id', $business->getKey())
            ->sole();
        $decisionRecordAfter = DB::table('capital_decision_records')
            ->where('business_id', $business->getKey())
            ->sole();

        self::assertSame((string) $planningBefore->input_payload, (string) $planningAfter->input_payload);
        self::assertSame((int) $planningBefore->revision, (int) $planningAfter->revision);
        self::assertSame((string) $comparisonBefore->input_payload, (string) $comparisonAfter->input_payload);
        self::assertSame((int) $comparisonBefore->revision, (int) $comparisonAfter->revision);
        self::assertSame((string) $decisionRecordBefore->approved_content_hash, (string) $decisionRecordAfter->approved_content_hash);

        $this->assertDatabaseCount('signature_requests', 0);
        $this->assertDatabaseCount('signatures', 0);
        $this->assertDatabaseCount('contributions', 0);
        $this->assertDatabaseCount('ownership_scenarios', 0);
        $this->assertDatabaseCount('ownership_registers', 0);

        self::assertFalse($read['semantics']['capitalCallExecuted']);
        self::assertFalse($read['semantics']['contributionTruth']);
        self::assertFalse($read['semantics']['equityTruth']);
        self::assertFalse($read['semantics']['ownershipTruth']);
    }

    public function test_custom_owner_validation_and_permission_boundaries_fail_closed(): void
    {
        [$owner, $business, $ownerMembership] = $this->business(
            'capital-act-owner@example.test',
            'Capital Act Owner',
        );
        [, $foreignBusiness, $foreignMembership] = $this->business(
            'capital-act-foreign@example.test',
            'Capital Act Foreign',
        );

        $this->approveCapital($owner, $business, $ownerMembership);
        $this->recordDecision($owner, $business, $ownerMembership);

        [$sameBusinessUser, $sameBusinessMembership] = $this->extraMember(
            $business,
            'capital-act-assignee@example.test',
        );

        $custom = $this->workflow()->createCustom(
            $owner,
            $business,
            (string) $sameBusinessMembership->getKey(),
            'Collect vendor quotations',
            null,
            '2027-03-01',
        );

        self::assertNotNull($custom);
        self::assertSame(
            (string) $sameBusinessMembership->getKey(),
            (string) $custom->assigned_membership_id,
        );

        $ownerRead = $this->readModel($owner, $business);
        self::assertNotNull($ownerRead);
        self::assertSame(
            (string) $ownerMembership->getKey(),
            $ownerRead['defaultOwnerMembershipId'],
            'Decision Owner is the suggested default, not a mandatory owner.',
        );

        self::assertNull(
            $this->workflow()->createCustom(
                $owner,
                $foreignBusiness,
                (string) $foreignMembership->getKey(),
                'Cross-Business create must fail',
                null,
                null,
            ),
        );

        self::assertNull(
            $this->workflow()->updateStatus(
                $owner,
                $foreignBusiness,
                (string) $custom->getKey(),
                ActionStatus::Completed,
            ),
        );

        self::assertNull(
            $this->readModel($owner, $foreignBusiness),
        );

        self::assertNull(
            $this->workflow()->createCustom(
                $owner,
                $business,
                (string) $foreignMembership->getKey(),
                'Foreign owner must fail',
                null,
                null,
            ),
        );

        $inactive = Membership::query()->create([
            'user_id' => $sameBusinessUser->getKey(),
            'business_id' => $foreignBusiness->getKey(),
            'access_status' => 'revoked',
        ]);

        self::assertNull(
            $this->workflow()->createCustom(
                $owner,
                $business,
                (string) $inactive->getKey(),
                'Inactive foreign owner must fail',
                null,
                null,
            ),
        );

        [$capitalOnly, $capitalOnlyMembership] = $this->extraMember(
            $business,
            'capital-only@example.test',
        );
        $this->grant(
            $business,
            $capitalOnlyMembership,
            CapabilityCatalog::CAPITAL_MANAGE,
            [],
        );

        self::assertNull(
            $this->workflow()->createCustom(
                $capitalOnly,
                $business,
                (string) $ownerMembership->getKey(),
                'Capital permission alone is insufficient',
                null,
                null,
            ),
        );

        [$governanceOnly, $governanceOnlyMembership] = $this->extraMember(
            $business,
            'governance-only@example.test',
        );
        $this->grant(
            $business,
            $governanceOnlyMembership,
            CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
            [
                Decision::class,
                FormalRecordVersion::class,
                Action::class,
            ],
        );

        self::assertNull(
            $this->workflow()->createCustom(
                $governanceOnly,
                $business,
                (string) $ownerMembership->getKey(),
                'Governance permission alone is insufficient',
                null,
                null,
            ),
        );

        self::assertNull(
            $this->readModel($capitalOnly, $foreignBusiness),
        );

        try {
            $this->workflow()->createCustom(
                $owner,
                $business,
                (string) $ownerMembership->getKey(),
                '',
                null,
                null,
            );
            self::fail('Custom Action title must be required.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('title is required', $exception->getMessage());
        }

        try {
            $this->workflow()->createCustom(
                $owner,
                $business,
                (string) $ownerMembership->getKey(),
                'Invalid date',
                null,
                '2027-02-30',
            );
            self::fail('Invalid Due Date must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('valid calendar date', $exception->getMessage());
        }
    }

    public function test_existing_action_status_lifecycle_is_reused_without_business_truth_side_effects(): void
    {
        [$user, $business, $membership] = $this->business(
            'capital-act-status@example.test',
            'Capital Act Status',
        );

        $this->approveCapital($user, $business, $membership);
        $this->recordDecision($user, $business, $membership);

        $action = $this->workflow()->createCustom(
            $user,
            $business,
            (string) $membership->getKey(),
            'Confirm supplier timing',
            'Track implementation only.',
            '2027-02-20',
        );

        self::assertNotNull($action);
        self::assertSame(ActionStatus::Open, $action->status);

        $inProgress = $this->workflow()->updateStatus(
            $user,
            $business,
            (string) $action->getKey(),
            ActionStatus::InProgress,
        );
        self::assertNotNull($inProgress);
        self::assertSame(ActionStatus::InProgress, $inProgress->status);

        try {
            $this->workflow()->updateStatus(
                $user,
                $business,
                (string) $action->getKey(),
                ActionStatus::Blocked,
                null,
            );
            self::fail('Blocked Action must require a reason.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('requires a reason', $exception->getMessage());
        }

        $blocked = $this->workflow()->updateStatus(
            $user,
            $business,
            (string) $action->getKey(),
            ActionStatus::Blocked,
            'Waiting for supplier response.',
        );
        self::assertNotNull($blocked);
        self::assertSame(ActionStatus::Blocked, $blocked->status);
        self::assertSame('Waiting for supplier response.', $blocked->blocked_reason);

        $reopened = $this->workflow()->updateStatus(
            $user,
            $business,
            (string) $action->getKey(),
            ActionStatus::InProgress,
        );
        self::assertNotNull($reopened);
        self::assertNull($reopened->blocked_reason);

        $capitalSnapshotBefore = DB::table('capital_approval_snapshots')
            ->where('business_id', $business->getKey())
            ->orderByDesc('prepared_at')
            ->sole();
        $decisionRecordBefore = DB::table('capital_decision_records')
            ->where('business_id', $business->getKey())
            ->sole();

        $completed = $this->workflow()->updateStatus(
            $user,
            $business,
            (string) $action->getKey(),
            ActionStatus::Completed,
        );

        self::assertNotNull($completed);
        self::assertSame(ActionStatus::Completed, $completed->status);
        self::assertNotNull($completed->completed_at);

        self::assertNull(
            $this->workflow()->updateStatus(
                $user,
                $business,
                (string) $action->getKey(),
                ActionStatus::Open,
            ),
            'Completed Action remains terminal under existing V3 Action behavior.',
        );

        $capitalSnapshotAfter = DB::table('capital_approval_snapshots')
            ->where('business_id', $business->getKey())
            ->orderByDesc('prepared_at')
            ->sole();
        $decisionRecordAfter = DB::table('capital_decision_records')
            ->where('business_id', $business->getKey())
            ->sole();

        self::assertSame((string) $capitalSnapshotBefore->content_hash, (string) $capitalSnapshotAfter->content_hash);
        self::assertSame((string) $decisionRecordBefore->approved_content_hash, (string) $decisionRecordAfter->approved_content_hash);

        $formalState = DB::table('record_version_state_transitions')
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $decisionRecordBefore->formal_record_version_id,
            )
            ->orderByDesc('sequence')
            ->value('to_state');

        self::assertSame('approved', $formalState);

        $familyId = DB::table('formal_record_versions')
            ->where('id', $decisionRecordBefore->formal_record_version_id)
            ->value('formal_record_family_id');

        self::assertSame(
            0,
            DB::table('record_family_effective_heads')
                ->where('business_id', $business->getKey())
                ->where('formal_record_family_id', $familyId)
                ->count(),
        );
        $this->assertDatabaseCount('signature_requests', 0);
        $this->assertDatabaseCount('signatures', 0);
        $this->assertDatabaseCount('contributions', 0);
        $this->assertDatabaseCount('ownership_scenarios', 0);
        $this->assertDatabaseCount('ownership_registers', 0);

        $read = $this->readModel($user, $business);
        self::assertNotNull($read);
        self::assertTrue($read['established']);
        self::assertSame(0, $read['outstandingCount']);
        self::assertSame(1, $read['completedCount']);
        self::assertTrue($read['semantics']['chapterComplete']);
        self::assertFalse($read['semantics']['allActionsCompleteRequired']);
        self::assertFalse($read['semantics']['signedTruth']);
        self::assertFalse($read['semantics']['effectiveTruth']);
    }

    public function test_historical_approved_source_is_not_retargeted_when_mutable_planning_changes(): void
    {
        [$user, $business, $membership] = $this->business(
            'capital-act-history@example.test',
            'Capital Act History',
        );

        $this->approveCapital($user, $business, $membership);
        $this->recordDecision($user, $business, $membership);

        $action = $this->workflow()->createSuggested(
            $user,
            $business,
            CapitalActionPlanContract::SUGGESTION_REVIEW,
            (string) $membership->getKey(),
        );

        self::assertNotNull($action);

        $record = DB::table('capital_decision_records')
            ->where('business_id', $business->getKey())
            ->sole();
        $beforeDecision = (string) $action->decision_id;
        $beforeFormal = (string) $action->formal_record_version_id;

        $planning = DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->sole();

        $input = json_decode(
            (string) $planning->input_payload,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $input['confirmedFunding'] = '700.00';

        $this->savePlanning(
            $user,
            $business,
            (int) $planning->revision,
            $input,
        );

        $read = $this->readModel($user, $business);

        self::assertNotNull($read);
        self::assertTrue($read['planningChangedSinceApproval']);
        self::assertSame('1430.00', $read['sourceDecisionRecord']['totalCapitalRequirement']);
        self::assertSame('930.00', $read['sourceDecisionRecord']['fundingGap']);

        $action->refresh();

        self::assertSame($beforeDecision, (string) $action->decision_id);
        self::assertSame($beforeFormal, (string) $action->formal_record_version_id);
        self::assertSame(
            (string) $record->governance_decision_id,
            (string) $action->decision_id,
        );
        self::assertSame(
            (string) $record->formal_record_version_id,
            (string) $action->formal_record_version_id,
        );
    }

    public function test_master_journey_marks_capital_complete_only_when_non_cancelled_action_plan_is_established(): void
    {
        [$user, $business, $membership] = $this->business(
            'capital-act-journey@example.test',
            'Capital Act Journey',
        );

        $this->approveCapital($user, $business, $membership);
        $this->recordDecision($user, $business, $membership);

        $before = $this->masterJourney($user, $business);
        self::assertNotSame(
            'recorded',
            $this->journeyState($before, 'capital'),
        );

        $action = $this->workflow()->createCustom(
            $user,
            $business,
            (string) $membership->getKey(),
            'Implement approved Capital opening checklist',
            null,
            null,
        );

        self::assertNotNull($action);

        $established = $this->masterJourney($user, $business);
        self::assertSame(
            'recorded',
            $this->journeyState($established, 'capital'),
        );

        $completed = $this->workflow()->updateStatus(
            $user,
            $business,
            (string) $action->getKey(),
            ActionStatus::Completed,
        );
        self::assertNotNull($completed);

        self::assertSame(
            'recorded',
            $this->journeyState(
                $this->masterJourney($user, $business),
                'capital',
            ),
            'Completed Actions remain valid Action Plan history.',
        );

        $changed = $this->planningInput();
        $changed['confirmedFunding'] = '700.00';
        $this->savePlanning($user, $business, 1, $changed);
        $this->saveRule($user, $business, 1, ['reduce_scope']);

        $comparison = $this->app
            ->make(RefreshCapitalComparisonDraftFromCanonical::class)
            ->execute($user, $business, 2);

        self::assertNotNull($comparison);

        $comparisonInput = $comparison['input'];
        $comparisonInput['preferredPlan'] = 'growth';

        self::assertNotNull(
            $this->app
                ->make(SaveCapitalComparisonDraft::class)
                ->execute($user, $business, 3, $comparisonInput),
        );

        $approval = $this->app->make(CapitalApprovalWorkflow::class);
        self::assertNotNull($approval->prepare($user, $business));
        $approval->createProposalReview(
            $user,
            $business,
            (string) $membership->getKey(),
        );
        $approval->completeProposalReview(
            $user,
            $business,
            ProposalReviewOutcome::Approved,
        );
        $approval->openDecision($user, $business);
        $approval->approve($user, $business, 'Approved revised Capital plan.');
        $approval->resolve($user, $business);

        self::assertNotSame(
            'recorded',
            $this->journeyState(
                $this->masterJourney($user, $business),
                'capital',
            ),
            'Historical Actions must not complete a newly approved Capital version.',
        );

        $this->recordDecision($user, $business, $membership);

        self::assertNotSame(
            'recorded',
            $this->journeyState(
                $this->masterJourney($user, $business),
                'capital',
            ),
            'A newly recorded Capital Decision needs its own Action Plan.',
        );

        self::assertNotNull(
            $this->workflow()->createCustom(
                $user,
                $business,
                (string) $membership->getKey(),
                'Establish revised Capital implementation plan',
                null,
                null,
            ),
        );

        self::assertSame(
            'recorded',
            $this->journeyState(
                $this->masterJourney($user, $business),
                'capital',
            ),
        );

        [$user2, $business2, $membership2] = $this->business(
            'capital-act-cancelled@example.test',
            'Capital Act Cancelled',
        );
        $this->approveCapital($user2, $business2, $membership2);
        $this->recordDecision($user2, $business2, $membership2);

        $cancelledAction = $this->workflow()->createCustom(
            $user2,
            $business2,
            (string) $membership2->getKey(),
            'Temporary action',
            null,
            null,
        );
        self::assertNotNull($cancelledAction);

        $cancelled = $this->workflow()->updateStatus(
            $user2,
            $business2,
            (string) $cancelledAction->getKey(),
            ActionStatus::Cancelled,
        );

        self::assertNotNull($cancelled);
        self::assertSame(ActionStatus::Cancelled, $cancelled->status);
        self::assertNull(
            $cancelled->completed_at,
            'Cancelled Action must not imply completion.',
        );

        self::assertNotSame(
            'recorded',
            $this->journeyState(
                $this->masterJourney($user2, $business2),
                'capital',
            ),
            'All-cancelled Actions must not falsely establish the ACT stage.',
        );
    }

    private function workflow(): CapitalActionPlanWorkflow
    {
        return $this->app->make(CapitalActionPlanWorkflow::class);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readModel(
        User $user,
        Business $business,
    ): ?array {
        return $this->app
            ->make(GetCapitalActionPlanReadModel::class)
            ->execute($user, $business);
    }

    /**
     * @return array<string,mixed>
     */
    private function masterJourney(
        User $user,
        Business $business,
    ): array {
        return $this->app
            ->make(GetMasterBusinessJourney::class)
            ->execute($user, $business, null);
    }

    /**
     * @param  array<string,mixed>  $journey
     */
    private function journeyState(
        array $journey,
        string $key,
    ): ?string {
        foreach ($journey['steps'] as $step) {
            if (($step['key'] ?? null) === $key) {
                return (string) ($step['state'] ?? '');
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $responses
     */
    private function approveCapital(
        User $user,
        Business $business,
        Membership $membership,
        bool $signatureRequired = false,
        array $responses = ['reduce_scope'],
    ): void {
        $this->readyCapital(
            $user,
            $business,
            'base',
            $responses,
        );
        $this->temporaryAuthority(
            $user,
            $business,
            $membership,
            signatureRequired: $signatureRequired,
        );

        $approval = $this->app->make(CapitalApprovalWorkflow::class);
        $approval->prepare($user, $business);
        $approval->createProposalReview(
            $user,
            $business,
            (string) $membership->getKey(),
        );
        $approval->completeProposalReview(
            $user,
            $business,
            ProposalReviewOutcome::Approved,
        );
        $approval->openDecision($user, $business);
        $approval->approve(
            $user,
            $business,
            'Approved exact frozen Capital plan.',
        );
        $approval->resolve($user, $business);
    }

    private function recordDecision(
        User $user,
        Business $business,
        Membership $membership,
    ): void {
        $created = $this->app
            ->make(CapitalDecisionRecordWorkflow::class)
            ->create(
                $user,
                $business,
                [
                    'decisionOwnerMembershipId' => (string) $membership->getKey(),
                    'effectiveDate' => '2027-01-15',
                    'reviewDate' => '2027-04-15',
                    'decisionSummary' => 'Approved Base Capital Plan for opening.',
                    'evidenceReferences' => [
                        'Partner meeting note',
                    ],
                ],
            );

        self::assertNotNull($created);
    }

    /**
     * @param  list<string>  $responses
     */
    private function readyCapital(
        User $user,
        Business $business,
        string $preferred,
        array $responses,
    ): void {
        $this->savePlanning(
            $user,
            $business,
            0,
            $this->planningInput(),
        );
        $this->saveRule(
            $user,
            $business,
            0,
            $responses,
        );

        $comparison = $this->app
            ->make(RefreshCapitalComparisonDraftFromCanonical::class)
            ->execute($user, $business, 0);

        self::assertNotNull($comparison);

        $input = $comparison['input'];
        $input['preferredPlan'] = $preferred;

        self::assertNotNull(
            $this->app
                ->make(SaveCapitalComparisonDraft::class)
                ->execute($user, $business, 1, $input),
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function savePlanning(
        User $user,
        Business $business,
        int $revision,
        array $input,
    ): void {
        self::assertNotNull(
            $this->app
                ->make(SaveCapitalPlanningDraft::class)
                ->execute($user, $business, $revision, $input),
        );
    }

    /**
     * @param  list<string>  $responses
     */
    private function saveRule(
        User $user,
        Business $business,
        int $revision,
        array $responses,
    ): void {
        self::assertNotNull(
            $this->app
                ->make(SaveCapitalRuleDraft::class)
                ->execute(
                    $user,
                    $business,
                    $revision,
                    [
                        'shortfallResponses' => $responses,
                        'allocationNotes' => 'Preserve operating buffer.',
                        'shortfallRuleNotes' => $responses === []
                            ? null
                            : 'Use only the approved shortfall responses.',
                        'capitalCallRuleNote' => in_array(
                            'capital_call',
                            $responses,
                            true,
                        )
                            ? 'Prepare a later governed Capital Call process.'
                            : null,
                    ],
                ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function planningInput(): array
    {
        return [
            'openingDate' => '2026-12-01',
            'preOpeningItems' => [[
                'category' => 'registration_legal',
                'label' => 'Registration',
                'amount' => '100.00',
            ]],
            'initialAssetsInventoryItems' => [[
                'category' => 'equipment',
                'label' => 'Equipment',
                'amount' => '200.00',
            ]],
            'workingCapital' => [
                'method' => 'fixed_amount',
                'amount' => '1000.00',
            ],
            'contingency' => [
                'method' => 'percentage',
                'percentage' => '10.00',
            ],
            'confirmedFunding' => '500.00',
        ];
    }

    private function temporaryAuthority(
        User $user,
        Business $business,
        Membership $membership,
        bool $signatureRequired,
    ): void {
        $workflow = $this->app->make(
            FormationAuthorityPolicyWorkflow::class,
        );

        $draft = $workflow->createDraft(
            $user,
            $business,
            [[
                'decision_type' => CapitalApprovalContract::DECISION_TYPE,
                'decision_method' => 'approval',
                'required_approvals' => 1,
                'required_votes' => 0,
                'quorum_count' => 1,
                'signature_required' => $signatureRequired,
                'reserved_matter' => false,
                'amount_min' => null,
                'amount_max' => null,
                'actors' => [[
                    'membership_id' => (string) $membership->getKey(),
                    'capacity' => 'Capital Approver',
                    'can_approve' => true,
                    'can_vote' => false,
                    'can_sign' => $signatureRequired,
                ]],
            ]],
            now()->subMinute(),
        );

        self::assertNotNull($draft);
        self::assertNotNull(
            $workflow->freezeForBootstrap(
                $user,
                $business,
                $draft['formal_record_version_id'],
                $draft['revision'],
            ),
        );
        self::assertNotNull(
            $this->app
                ->make(EstablishInitialFormationAuthority::class)
                ->execute(
                    $user,
                    $business,
                    $draft['formal_record_version_id'],
                ),
        );
    }

    /**
     * @return array{0:User,1:Business,2:Membership}
     */
    private function business(
        string $email,
        string $name,
    ): array {
        $user = User::query()->create([
            'email' => $email,
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            $name,
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        $membership = Membership::query()
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->sole();

        return [$user, $business, $membership];
    }

    /**
     * @return array{0:User,1:Membership}
     */
    private function extraMember(
        Business $business,
        string $email,
    ): array {
        $user = User::query()->create([
            'email' => $email,
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        return [$user, $membership];
    }

    /**
     * @param  list<class-string>  $resourceTypes
     */
    private function grant(
        Business $business,
        Membership $membership,
        string $capability,
        array $resourceTypes,
    ): void {
        $permission = Permission::query()->firstOrCreate([
            'key' => $capability,
        ]);

        PermissionGrant::query()->updateOrCreate([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
        ], [
            'effect' => 'allow',
        ]);

        foreach ($resourceTypes as $resourceType) {
            AccessPolicy::query()->updateOrCreate([
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
}
