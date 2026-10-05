<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\BuildCapitalApprovalCandidate;
use App\Application\Formation\CapitalApprovalWorkflow;
use App\Application\Formation\GetCapitalApprovalReadModel;
use App\Application\Formation\RefreshCapitalComparisonDraftFromCanonical;
use App\Application\Formation\SaveCapitalComparisonDraft;
use App\Application\Formation\SaveCapitalPlanningDraft;
use App\Application\Formation\SaveCapitalRuleDraft;
use App\Application\Governance\EstablishInitialFormationAuthority;
use App\Application\Governance\FormationAuthorityPolicyWorkflow;
use App\Application\Governance\GovernanceMeetingWorkflow;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Capital\CapitalApprovalContract;
use App\Domain\Governance\Enums\ProposalReviewOutcome;
use App\Domain\Governance\Enums\VoteChoice;
use App\Domain\Identity\Enums\AccountStatus;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class CapitalApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_fails_closed_until_exact_upstream_capital_truth_is_ready(): void
    {
        [$user, $business] = $this->business(
            'capital-approval-prereq@example.test',
            'Capital Approval Prerequisites',
        );

        $workflow = $this->workflow();

        try {
            $workflow->prepare($user, $business);
            self::fail('Approval must not prepare before Capital planning.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Capital Plan', $exception->getMessage());
        }

        $this->savePlanning($user, $business, 0, $this->planningInput());

        try {
            $workflow->prepare($user, $business);
            self::fail('Approval must not prepare before the Capital Rule.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Capital Rule', $exception->getMessage());
        }

        $this->saveRule($user, $business, 0);

        try {
            $workflow->prepare($user, $business);
            self::fail('Approval must not prepare before comparison.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('comparison', strtolower($exception->getMessage()));
        }

        $comparison = $this->app
            ->make(RefreshCapitalComparisonDraftFromCanonical::class)
            ->execute($user, $business, 0);

        self::assertNotNull($comparison);

        try {
            $workflow->prepare($user, $business);
            self::fail('Approval must not prepare without Preferred Plan.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Preferred Plan', $exception->getMessage());
        }

        $partial = $comparison['input'];
        $partial['preferredPlan'] = 'base';
        $partial['scenarios']['base'] = null;

        $savedPartial = $this->app
            ->make(SaveCapitalComparisonDraft::class)
            ->execute($user, $business, 1, $partial);

        self::assertNotNull($savedPartial);

        $candidate = $this->app
            ->make(BuildCapitalApprovalCandidate::class)
            ->execute($user, $business);

        self::assertNotNull($candidate);
        self::assertFalse($candidate['ready']);
        self::assertContains(
            'capital_comparison_incomplete',
            $candidate['reasons'],
        );
        self::assertContains(
            'preferred_plan_incomplete',
            $candidate['reasons'],
        );
        $this->assertDatabaseCount('capital_approval_snapshots', 0);

        $refreshed = $this->app
            ->make(RefreshCapitalComparisonDraftFromCanonical::class)
            ->execute($user, $business, 2);

        self::assertNotNull($refreshed);

        $readyInput = $refreshed['input'];
        $readyInput['preferredPlan'] = 'base';

        $readyComparison = $this->app
            ->make(SaveCapitalComparisonDraft::class)
            ->execute($user, $business, 3, $readyInput);

        self::assertNotNull($readyComparison);

        $prepared = $workflow->prepare($user, $business);

        self::assertNotNull($prepared);
        self::assertTrue($prepared['created']);
        self::assertSame('base', $prepared['preferredPlan']);
    }

    public function test_prepare_freezes_exact_preferred_plan_pins_revisions_is_idempotent_and_does_not_mutate_sources(): void
    {
        [$user, $business] = $this->business(
            'capital-approval-freeze@example.test',
            'Capital Approval Freeze',
        );

        $this->readyCapital($user, $business, 'base');

        $planningBefore = DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->sole();
        $ruleBefore = DB::table('capital_rule_drafts')
            ->where('business_id', $business->getKey())
            ->sole();
        $comparisonBefore = DB::table('capital_comparison_drafts')
            ->where('business_id', $business->getKey())
            ->sole();

        $prepared = $this->workflow()->prepare($user, $business);

        self::assertNotNull($prepared);
        self::assertSame(
            CapitalApprovalContract::CONTRACT_VERSION,
            $prepared['contractVersion'],
        );

        $snapshot = DB::table('capital_approval_snapshots')
            ->where('business_id', $business->getKey())
            ->sole();

        self::assertSame(1, (int) $snapshot->capital_planning_revision);
        self::assertSame(1, (int) $snapshot->capital_rule_revision);
        self::assertSame(2, (int) $snapshot->capital_comparison_revision);
        self::assertSame('base', (string) $snapshot->preferred_plan);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            (string) $snapshot->content_hash,
        );

        $payload = json_decode(
            (string) $snapshot->snapshot_payload,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'capital-calculation-v1',
            $payload['calculation']['contractVersion'],
        );
        self::assertSame(
            '1430.00',
            $payload['calculation']['totalCapitalRequirement']['amount'],
        );
        self::assertSame(
            '930.00',
            $payload['calculation']['fundingPosition']['fundingGap'],
        );
        self::assertSame(
            ['reduce_scope'],
            $payload['capitalRule']['shortfallResponses'],
        );
        self::assertSame(
            (string) $snapshot->content_hash,
            $this->app
                ->make(CapitalApprovalContract::class)
                ->contentHash($payload),
        );

        $formal = FormalRecordVersion::query()
            ->whereKey($snapshot->formal_record_version_id)
            ->sole();

        self::assertNotNull($formal->frozen_at);
        self::assertSame(
            (string) $snapshot->content_hash,
            (string) $formal->content_hash,
        );
        self::assertSame(
            'ready_for_review',
            DB::table('record_version_state_transitions')
                ->where('formal_record_version_id', $formal->getKey())
                ->orderByDesc('sequence')
                ->value('to_state'),
        );

        $this->assertDatabaseHas('proposal_version_records', [
            'business_id' => $business->getKey(),
            'proposal_version_id' => $snapshot->proposal_version_id,
            'formal_record_version_id' => $snapshot->formal_record_version_id,
            'captured_content_hash' => $snapshot->content_hash,
        ]);

        $again = $this->workflow()->prepare($user, $business);

        self::assertNotNull($again);
        self::assertFalse($again['created']);
        self::assertSame($prepared['id'], $again['id']);
        $this->assertDatabaseCount('capital_approval_snapshots', 1);

        $planningAfter = DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->sole();
        $ruleAfter = DB::table('capital_rule_drafts')
            ->where('business_id', $business->getKey())
            ->sole();
        $comparisonAfter = DB::table('capital_comparison_drafts')
            ->where('business_id', $business->getKey())
            ->sole();

        self::assertSame((string) $planningBefore->input_payload, (string) $planningAfter->input_payload);
        self::assertSame((int) $planningBefore->revision, (int) $planningAfter->revision);
        self::assertSame((string) $ruleBefore->input_payload, (string) $ruleAfter->input_payload);
        self::assertSame((int) $ruleBefore->revision, (int) $ruleAfter->revision);
        self::assertSame((string) $comparisonBefore->input_payload, (string) $comparisonAfter->input_payload);
        self::assertSame((int) $comparisonBefore->revision, (int) $comparisonAfter->revision);

        $this->assertDatabaseRejects(function () use ($snapshot): void {
            DB::table('capital_approval_snapshots')
                ->where('id', $snapshot->id)
                ->update(['preferred_plan' => 'lean']);
        });
    }

    public function test_explicit_zero_remains_zero_in_frozen_approval_candidate(): void
    {
        [$user, $business] = $this->business(
            'capital-approval-zero@example.test',
            'Capital Approval Zero',
        );

        $zero = [
            'openingDate' => null,
            'preOpeningItems' => [],
            'initialAssetsInventoryItems' => [],
            'workingCapital' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'contingency' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'confirmedFunding' => '0.00',
        ];

        $this->savePlanning($user, $business, 0, $zero);
        $this->saveRule($user, $business, 0, []);
        $this->selectPreferred($user, $business, 'base');

        $prepared = $this->workflow()->prepare($user, $business);

        self::assertNotNull($prepared);

        $payload = json_decode(
            (string) DB::table('capital_approval_snapshots')
                ->where('business_id', $business->getKey())
                ->value('snapshot_payload'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            '0.00',
            $payload['calculation']['totalCapitalRequirement']['amount'],
        );
        self::assertSame(
            '0.00',
            $payload['calculation']['fundingPosition']['confirmedFunding'],
        );
        self::assertSame(
            '0.00',
            $payload['calculation']['fundingPosition']['fundingGap'],
        );
        self::assertSame(
            [],
            $payload['preferredPlanInput']['preOpeningItems'],
        );
        self::assertSame(
            [],
            $payload['preferredPlanInput']['initialAssetsInventoryItems'],
        );
    }

    public function test_direct_governed_approval_reaches_approved_but_not_signed_or_effective(): void
    {
        [$user, $business, $membership] = $this->business(
            'capital-approval-direct@example.test',
            'Capital Approval Direct',
        );

        $this->readyCapital($user, $business, 'base');
        $this->temporaryAuthority(
            $user,
            $business,
            [
                $this->authorityActor(
                    $membership,
                    canApprove: true,
                    canVote: false,
                    canSign: true,
                ),
            ],
            method: 'approval',
            requiredApprovals: 1,
            requiredVotes: 0,
            quorum: 1,
            signatureRequired: true,
        );

        $workflow = $this->workflow();
        $prepared = $workflow->prepare($user, $business);

        self::assertNotNull($prepared);

        $review = $workflow->createProposalReview(
            $user,
            $business,
            (string) $membership->getKey(),
        );

        self::assertNotNull($review);
        self::assertSame(
            'under_review',
            $this->latestCapitalRecordState($business),
        );

        $review = $workflow->completeProposalReview(
            $user,
            $business,
            ProposalReviewOutcome::Approved,
            'The exact final Capital Plan was reviewed.',
        );

        self::assertNotNull($review);
        self::assertSame('approved', $review->outcome->value);
        self::assertSame(
            'under_review',
            $this->latestCapitalRecordState($business),
        );

        $decision = $workflow->openDecision($user, $business);

        self::assertNotNull($decision);

        $before = $this->readModel($user, $business);
        self::assertNotNull($before);
        self::assertSame('formation_authority', $before['authority']['sourceKind']);
        self::assertSame('approval', $before['authority']['method']);
        self::assertTrue($before['signatureRequired']);
        self::assertTrue($before['actions']['canApprove']);

        $approval = $workflow->approve(
            $user,
            $business,
            'Capital requirement and shortfall rule reviewed.',
        );

        self::assertNotNull($approval);

        $approvedRecord = $workflow->resolve($user, $business);

        self::assertNotNull($approvedRecord);
        self::assertSame(
            'approved',
            $this->latestCapitalRecordState($business),
        );

        $read = $this->readModel($user, $business);

        self::assertNotNull($read);
        self::assertTrue($read['approved']);
        self::assertFalse($read['signed']);
        self::assertFalse($read['effective']);
        self::assertSame('approved', $read['formalState']);
        self::assertSame('approved', $read['status']);
        self::assertTrue($read['signatureRequired']);
        self::assertNotEmpty($read['approvedBy']);
        self::assertNotNull($read['approvalDate']);
        self::assertSame(
            'capital-approval-direct@example.test',
            $read['approvedBy'][0]['name'],
        );

        self::assertFalse($read['semantics']['signedTruth']);
        self::assertFalse($read['semantics']['effectiveTruth']);
        self::assertFalse($read['semantics']['decisionRecordComplete']);
        self::assertFalse($read['semantics']['actionPlanCreated']);
        self::assertFalse($read['semantics']['capitalCallExecuted']);
        self::assertFalse($read['semantics']['contributionTruth']);
        self::assertFalse($read['semantics']['acceptedContributionTruth']);
        self::assertFalse($read['semantics']['equityTruth']);
        self::assertFalse($read['semantics']['ownershipTruth']);

        $capitalFamily = FormalRecordFamily::query()
            ->where('business_id', $business->getKey())
            ->where('record_type', 'capital_plan')
            ->sole();

        self::assertSame(
            0,
            RecordFamilyEffectiveHead::query()
                ->where('business_id', $business->getKey())
                ->where('formal_record_family_id', $capitalFamily->getKey())
                ->count(),
        );

        $this->assertDatabaseCount('signature_requests', 0);
        $this->assertDatabaseCount('signatures', 0);
        $this->assertDatabaseCount('actions', 0);
        $this->assertDatabaseCount('contributions', 0);
        $this->assertDatabaseCount('ownership_scenarios', 0);
        $this->assertDatabaseCount('ownership_registers', 0);
        self::assertSame(
            0,
            FormalRecordFamily::query()
                ->where('business_id', $business->getKey())
                ->where('record_type', 'capital_decision')
                ->count(),
        );

        $states = DB::table('record_version_state_transitions')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $approvedRecord->getKey())
            ->pluck('to_state')
            ->all();

        self::assertContains('approved', $states);
        self::assertNotContains('ready_for_effect', $states);
        self::assertNotContains('effective', $states);
    }

    public function test_missing_authority_fails_closed_after_exact_review(): void
    {
        [$user, $business, $membership] = $this->business(
            'capital-approval-no-authority@example.test',
            'Capital Approval Missing Authority',
        );

        $this->readyCapital($user, $business, 'base');

        $workflow = $this->workflow();
        $workflow->prepare($user, $business);
        $workflow->createProposalReview(
            $user,
            $business,
            (string) $membership->getKey(),
        );
        $workflow->completeProposalReview(
            $user,
            $business,
            ProposalReviewOutcome::Approved,
        );

        try {
            $workflow->openDecision($user, $business);
            self::fail('Capital approval must fail closed without configured authority.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Capital approval authority has not been configured yet.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('decisions', 0);

        $read = $this->readModel($user, $business);
        self::assertNotNull($read);
        self::assertSame('authority_not_configured', $read['status']);
        self::assertFalse($read['authority']['configured']);
    }

    public function test_voting_rule_enforces_threshold_quorum_and_nonparticipant_cannot_vote(): void
    {
        [$owner, $business, $ownerMembership] = $this->business(
            'capital-approval-vote-owner@example.test',
            'Capital Approval Vote',
        );

        [$voter, $voterMembership] = $this->extraMember(
            $business,
            'capital-approval-voter@example.test',
        );

        [$nonParticipant, $nonParticipantMembership] = $this->extraMember(
            $business,
            'capital-approval-nonparticipant@example.test',
        );

        foreach ([
            [$voterMembership, CapabilityCatalog::CAPITAL_VIEW, []],
            [
                $voterMembership,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                [Decision::class],
            ],
            [$nonParticipantMembership, CapabilityCatalog::CAPITAL_VIEW, []],
            [
                $nonParticipantMembership,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                [Decision::class],
            ],
        ] as [$membership, $capability, $resourceTypes]) {
            $this->grant(
                $business,
                $membership,
                $capability,
                $resourceTypes,
            );
        }

        $this->readyCapital($owner, $business, 'base');

        $this->temporaryAuthority(
            $owner,
            $business,
            [
                $this->authorityActor(
                    $ownerMembership,
                    canApprove: false,
                    canVote: true,
                ),
                $this->authorityActor(
                    $voterMembership,
                    canApprove: false,
                    canVote: true,
                ),
            ],
            method: 'vote',
            requiredApprovals: 0,
            requiredVotes: 2,
            quorum: 2,
        );

        $workflow = $this->workflow();
        $workflow->prepare($owner, $business);
        $workflow->createProposalReview(
            $owner,
            $business,
            (string) $ownerMembership->getKey(),
        );
        $workflow->completeProposalReview(
            $owner,
            $business,
            ProposalReviewOutcome::Approved,
        );
        $workflow->openDecision($owner, $business);

        self::assertNull(
            $workflow->vote(
                $nonParticipant,
                $business,
                VoteChoice::For,
            ),
            'Governance capability alone must not turn a nonparticipant into a voter.',
        );

        self::assertNotNull(
            $workflow->vote(
                $owner,
                $business,
                VoteChoice::For,
                'Owner supports the exact frozen plan.',
            ),
        );

        try {
            $workflow->resolve($owner, $business);
            self::fail('One vote must not satisfy a two-vote threshold/quorum.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'threshold',
                strtolower($exception->getMessage()),
            );
        }

        self::assertNotNull(
            $workflow->vote(
                $voter,
                $business,
                VoteChoice::For,
                'Second eligible voter supports the plan.',
            ),
        );

        self::assertNotNull($workflow->resolve($owner, $business));

        $read = $this->readModel($owner, $business);
        self::assertNotNull($read);
        self::assertTrue($read['approved']);
        self::assertSame('vote', $read['authority']['method']);
        self::assertSame(2, $read['decision']['progress']['supportingVotes']);
        self::assertCount(2, $read['approvalEvidence']['votes']);
        self::assertCount(2, $read['approvedBy']);
    }

    public function test_effective_governance_charter_wins_and_meeting_required_rule_blocks_until_valid_meeting(): void
    {
        [$user, $business, $membership] = $this->business(
            'capital-approval-charter@example.test',
            'Capital Approval Charter',
        );

        $this->readyCapital($user, $business, 'base');

        $this->temporaryAuthority(
            $user,
            $business,
            [
                $this->authorityActor(
                    $membership,
                    canApprove: true,
                    canVote: false,
                ),
            ],
        );

        $charter = $this->seedEffectiveCapitalCharter(
            $business,
            $user,
            $membership,
            meetingRequired: true,
        );

        $workflow = $this->workflow();
        $workflow->prepare($user, $business);
        $workflow->createProposalReview(
            $user,
            $business,
            (string) $membership->getKey(),
        );
        $workflow->completeProposalReview(
            $user,
            $business,
            ProposalReviewOutcome::Approved,
        );

        $read = $this->readModel($user, $business);

        self::assertNotNull($read);
        self::assertSame(
            'governance_charter',
            $read['authority']['sourceKind'],
        );
        self::assertTrue($read['authority']['meetingRequired']);
        self::assertFalse($read['actions']['canOpenDecision']);
        self::assertSame([], $read['eligibleMeetings']);

        try {
            $workflow->openDecision($user, $business);
            self::fail('Meeting-required authority must block without a valid held meeting.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'requires a held Governance Meeting',
                $exception->getMessage(),
            );
        }

        $meetings = $this->app->make(GovernanceMeetingWorkflow::class);
        $meetingId = $meetings->schedule(
            $user,
            $business,
            'Capital Approval Meeting',
            now()->subMinute(),
            1,
            (string) $membership->getKey(),
            (string) $membership->getKey(),
            'Review and decide the final Capital Plan.',
            [(string) $membership->getKey()],
            now()->subMinutes(2),
        );

        self::assertNotNull($meetingId);
        self::assertTrue(
            $meetings->hold(
                $user,
                $business,
                $meetingId,
                'Quorum present. Final Capital Plan reviewed.',
                [(string) $membership->getKey() => 'present'],
            ),
        );

        $read = $this->readModel($user, $business);
        self::assertNotNull($read);
        self::assertCount(1, $read['eligibleMeetings']);
        self::assertTrue($read['actions']['canOpenDecision']);

        $decision = $workflow->openDecision(
            $user,
            $business,
            $meetingId,
        );

        self::assertNotNull($decision);
        self::assertSame($meetingId, (string) $decision->meeting_id);

        $snapshot = DB::table('authority_snapshots')
            ->where('id', $decision->authority_snapshot_id)
            ->sole();

        self::assertSame(
            'governance_charter',
            (string) $snapshot->source_kind,
        );
        self::assertSame(
            (string) $charter->getKey(),
            (string) $snapshot->source_formal_record_version_id,
        );
    }

    public function test_source_revision_or_preferred_plan_change_blocks_old_candidate_and_preserves_history(): void
    {
        [$user, $business] = $this->business(
            'capital-approval-stale@example.test',
            'Capital Approval Stale',
        );

        $this->readyCapital($user, $business, 'base');

        $workflow = $this->workflow();
        $first = $workflow->prepare($user, $business);
        self::assertNotNull($first);

        $firstRow = DB::table('capital_approval_snapshots')
            ->where('id', $first['id'])
            ->sole();
        $firstPayload = (string) $firstRow->snapshot_payload;
        $firstHash = (string) $firstRow->content_hash;

        $comparison = DB::table('capital_comparison_drafts')
            ->where('business_id', $business->getKey())
            ->sole();

        $input = json_decode(
            (string) $comparison->input_payload,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $input['preferredPlan'] = 'growth';

        $changed = $this->app
            ->make(SaveCapitalComparisonDraft::class)
            ->execute(
                $user,
                $business,
                (int) $comparison->revision,
                $input,
            );

        self::assertNotNull($changed);

        $read = $this->readModel($user, $business);
        self::assertNotNull($read);
        self::assertTrue($read['planChanged']);
        self::assertSame('plan_changed', $read['status']);
        self::assertTrue($read['actions']['canPrepare']);

        try {
            $workflow->createProposalReview(
                $user,
                $business,
                (string) Membership::query()
                    ->where('business_id', $business->getKey())
                    ->where('user_id', $user->getKey())
                    ->value('id'),
            );
            self::fail('Stale frozen candidate must not progress.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'prepare a new approval version',
                strtolower($exception->getMessage()),
            );
        }

        $firstAfter = DB::table('capital_approval_snapshots')
            ->where('id', $first['id'])
            ->sole();

        self::assertSame($firstPayload, (string) $firstAfter->snapshot_payload);
        self::assertSame($firstHash, (string) $firstAfter->content_hash);

        $second = $workflow->prepare($user, $business);

        self::assertNotNull($second);
        self::assertTrue($second['created']);
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame('growth', $second['preferredPlan']);
        $this->assertDatabaseCount('capital_approval_snapshots', 2);

        $secondRecord = FormalRecordVersion::query()
            ->whereKey($second['formalRecordVersionId'])
            ->sole();

        self::assertSame(2, (int) $secondRecord->version_number);
        self::assertSame(
            $first['formalRecordVersionId'],
            (string) $secondRecord->predecessor_version_id,
        );

        self::assertSame(
            $firstHash,
            (string) DB::table('capital_approval_snapshots')
                ->where('id', $first['id'])
                ->value('content_hash'),
        );
    }

    public function test_unauthorized_and_cross_tenant_access_fail_closed(): void
    {
        [$owner, $business] = $this->business(
            'capital-approval-owner@example.test',
            'Capital Approval Private',
        );
        [, $otherBusiness] = $this->business(
            'capital-approval-other@example.test',
            'Capital Approval Other',
        );

        $this->readyCapital($owner, $business, 'base');

        $outsider = User::query()->create([
            'email' => 'capital-approval-outsider@example.test',
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        self::assertNull(
            $this->workflow()->prepare($outsider, $business),
        );
        self::assertNull(
            $this->readModel($outsider, $business),
        );

        $prepared = $this->workflow()->prepare($owner, $business);
        self::assertNotNull($prepared);

        self::assertSame(
            0,
            DB::table('capital_approval_snapshots')
                ->where('business_id', $otherBusiness->getKey())
                ->count(),
        );
    }

    private function workflow(): CapitalApprovalWorkflow
    {
        return $this->app->make(CapitalApprovalWorkflow::class);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readModel(
        User $user,
        Business $business,
    ): ?array {
        return $this->app
            ->make(GetCapitalApprovalReadModel::class)
            ->execute($user, $business);
    }

    private function readyCapital(
        User $user,
        Business $business,
        string $preferred,
    ): void {
        $this->savePlanning(
            $user,
            $business,
            0,
            $this->planningInput(),
        );
        $this->saveRule($user, $business, 0);
        $this->selectPreferred($user, $business, $preferred);
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
        $result = $this->app
            ->make(SaveCapitalPlanningDraft::class)
            ->execute(
                $user,
                $business,
                $revision,
                $input,
            );

        self::assertNotNull($result);
    }

    /**
     * @param  list<string>  $responses
     */
    private function saveRule(
        User $user,
        Business $business,
        int $revision,
        array $responses = ['reduce_scope'],
    ): void {
        $result = $this->app
            ->make(SaveCapitalRuleDraft::class)
            ->execute(
                $user,
                $business,
                $revision,
                [
                    'shortfallResponses' => $responses,
                    'allocationNotes' => 'Preserve the operating buffer.',
                    'shortfallRuleNotes' => $responses === []
                        ? null
                        : 'Reduce scope before asking for more Capital.',
                    'capitalCallRuleNote' => null,
                ],
            );

        self::assertNotNull($result);
    }

    private function selectPreferred(
        User $user,
        Business $business,
        string $preferred,
    ): void {
        $comparison = $this->app
            ->make(RefreshCapitalComparisonDraftFromCanonical::class)
            ->execute($user, $business, 0);

        self::assertNotNull($comparison);

        $input = $comparison['input'];
        $input['preferredPlan'] = $preferred;

        $saved = $this->app
            ->make(SaveCapitalComparisonDraft::class)
            ->execute(
                $user,
                $business,
                1,
                $input,
            );

        self::assertNotNull($saved);
    }

    /**
     * @return array<string,mixed>
     */
    private function planningInput(): array
    {
        return [
            'openingDate' => '2026-12-01',
            'preOpeningItems' => [
                [
                    'category' => 'registration_legal',
                    'label' => 'Registration',
                    'amount' => '100.00',
                ],
            ],
            'initialAssetsInventoryItems' => [
                [
                    'category' => 'equipment',
                    'label' => 'Equipment',
                    'amount' => '200.00',
                ],
            ],
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

    /**
     * @param  list<array<string,mixed>>  $actors
     */
    private function temporaryAuthority(
        User $user,
        Business $business,
        array $actors,
        string $method = 'approval',
        int $requiredApprovals = 1,
        int $requiredVotes = 0,
        int $quorum = 1,
        bool $signatureRequired = false,
    ): void {
        $workflow = $this->app->make(
            FormationAuthorityPolicyWorkflow::class,
        );

        $draft = $workflow->createDraft(
            $user,
            $business,
            [[
                'decision_type' => CapitalApprovalContract::DECISION_TYPE,
                'decision_method' => $method,
                'required_approvals' => $requiredApprovals,
                'required_votes' => $requiredVotes,
                'quorum_count' => $quorum,
                'signature_required' => $signatureRequired,
                'reserved_matter' => false,
                'amount_min' => null,
                'amount_max' => null,
                'actors' => $actors,
            ]],
            now()->subMinute(),
        );

        self::assertNotNull($draft);

        $frozen = $workflow->freezeForBootstrap(
            $user,
            $business,
            $draft['formal_record_version_id'],
            $draft['revision'],
        );

        self::assertNotNull($frozen);

        $established = $this->app
            ->make(EstablishInitialFormationAuthority::class)
            ->execute(
                $user,
                $business,
                $draft['formal_record_version_id'],
            );

        self::assertNotNull($established);
    }

    /**
     * @return array<string,mixed>
     */
    private function authorityActor(
        Membership $membership,
        bool $canApprove,
        bool $canVote,
        bool $canSign = false,
    ): array {
        return [
            'membership_id' => (string) $membership->getKey(),
            'capacity' => 'Capital Approver',
            'can_approve' => $canApprove,
            'can_vote' => $canVote,
            'can_sign' => $canSign,
        ];
    }

    private function seedEffectiveCapitalCharter(
        Business $business,
        User $user,
        Membership $actor,
        bool $meetingRequired,
    ): FormalRecordVersion {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'governance_charter',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Capital authority test charter.',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('a', 64),
            'frozen_at' => null,
        ]);

        DB::table('governance_charter_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'governance_owner_membership_id' => $actor->getKey(),
            'voting_basis' => 'One eligible participant, one vote',
            'default_approval_rule' => 'Exact authority rule',
            'meeting_frequency' => 'As required',
            'default_quorum_count' => 1,
            'minutes_owner_membership_id' => $actor->getKey(),
            'conflict_of_interest_rule' => 'Disclose and recuse.',
            'deadlock_rule' => 'Escalate under the approved process.',
            'remote_voting_allowed' => true,
            'written_resolution_allowed' => true,
            'created_at' => now(),
        ]);

        $ruleId = (string) Str::uuid7();

        DB::table('governance_charter_rules')->insert([
            'id' => $ruleId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'decision_type' => CapitalApprovalContract::DECISION_TYPE,
            'category' => 'management',
            'decision_method' => 'approval',
            'required_approvals' => 1,
            'required_votes' => 0,
            'quorum_count' => 1,
            'signature_required' => false,
            'reserved_matter' => false,
            'meeting_required' => $meetingRequired,
            'record_required' => true,
            'amount_min' => null,
            'amount_max' => null,
            'created_at' => now(),
        ]);

        DB::table('governance_charter_rule_actors')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'governance_charter_rule_id' => $ruleId,
            'membership_id' => $actor->getKey(),
            'capacity' => 'Governance Capital Approver',
            'is_decision_owner' => true,
            'is_consulted' => false,
            'can_approve' => true,
            'can_vote' => false,
            'can_sign' => false,
            'created_at' => now(),
        ]);

        $version->frozen_at = now();
        $version->save();

        $states = [
            [null, 'draft'],
            ['draft', 'ready_for_review'],
            ['ready_for_review', 'under_review'],
            ['under_review', 'approved'],
            ['approved', 'ready_for_effect'],
            ['ready_for_effect', 'effective'],
        ];

        foreach ($states as $index => [$from, $to]) {
            RecordVersionStateTransition::query()->create([
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => $index + 1,
                'from_state' => $from,
                'to_state' => $to,
                'transitioned_by_user_id' => $user->getKey(),
                'occurred_at' => now(),
            ]);
        }

        RecordFamilyEffectiveHead::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'activated_at' => now(),
        ]);

        return $version->fresh();
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

    private function latestCapitalRecordState(
        Business $business,
    ): ?string {
        $versionId = DB::table('capital_approval_snapshots')
            ->where('business_id', $business->getKey())
            ->orderByDesc('prepared_at')
            ->value('formal_record_version_id');

        if ($versionId === null) {
            return null;
        }

        $state = DB::table('record_version_state_transitions')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $versionId)
            ->orderByDesc('sequence')
            ->value('to_state');

        return is_string($state) ? $state : null;
    }

    private function assertDatabaseRejects(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected PostgreSQL database constraint/trigger rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
