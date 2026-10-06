<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\CapitalApprovalWorkflow;
use App\Application\Formation\CapitalDecisionRecordWorkflow;
use App\Application\Formation\GetCapitalDecisionRecordReadModel;
use App\Application\Formation\RefreshCapitalComparisonDraftFromCanonical;
use App\Application\Formation\SaveCapitalComparisonDraft;
use App\Application\Formation\SaveCapitalPlanningDraft;
use App\Application\Formation\SaveCapitalRuleDraft;
use App\Application\Governance\EstablishInitialFormationAuthority;
use App\Application\Governance\FormationAuthorityPolicyWorkflow;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Capital\CapitalApprovalContract;
use App\Domain\Capital\CapitalDecisionRecordContract;
use App\Domain\Governance\Enums\ProposalReviewOutcome;
use App\Domain\Governance\Enums\VoteChoice;
use App\Domain\Identity\Enums\AccountStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class CapitalDecisionRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_decision_record_requires_resolved_governed_capital_approval(): void
    {
        [$user, $business, $membership] = $this->business(
            'capital-record-prereq@example.test',
            'Capital Record Prerequisites',
        );

        $this->readyCapital($user, $business, 'base');
        $this->temporaryAuthority(
            $user,
            $business,
            $membership,
            signatureRequired: false,
        );

        $record = $this->recordWorkflow();
        $approval = $this->approvalWorkflow();

        $this->assertCreateFailsWithoutApprovedSource(
            fn () => $record->create(
                $user,
                $business,
                $this->recordInput($membership),
            ),
        );

        $approval->prepare($user, $business);

        $this->assertCreateFailsWithoutApprovedSource(
            fn () => $record->create(
                $user,
                $business,
                $this->recordInput($membership),
            ),
        );

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

        $this->assertCreateFailsWithoutApprovedSource(
            fn () => $record->create(
                $user,
                $business,
                $this->recordInput($membership),
            ),
        );

        $approval->openDecision($user, $business);
        $approval->approve($user, $business, 'Approval evidence recorded.');

        $this->assertCreateFailsWithoutApprovedSource(
            fn () => $record->create(
                $user,
                $business,
                $this->recordInput($membership),
            ),
        );

        $approval->resolve($user, $business);

        $created = $record->create(
            $user,
            $business,
            $this->recordInput($membership),
        );

        self::assertNotNull($created);
        self::assertTrue($created['created']);
        $this->assertDatabaseCount('capital_decision_records', 1);
    }

    public function test_record_pins_exact_approved_truth_governance_evidence_and_is_idempotent_immutable(): void
    {
        [$user, $business, $membership] = $this->business(
            'capital-record-exact@example.test',
            'Capital Record Exact',
        );

        $this->approveCapital(
            $user,
            $business,
            $membership,
            signatureRequired: true,
        );

        $snapshot = DB::table('capital_approval_snapshots')
            ->where('business_id', $business->getKey())
            ->orderByDesc('prepared_at')
            ->sole();

        $decision = Decision::query()
            ->where('business_id', $business->getKey())
            ->where('proposal_version_id', $snapshot->proposal_version_id)
            ->sole();

        $formalBefore = DB::table('formal_record_versions')
            ->where('id', $snapshot->formal_record_version_id)
            ->sole();
        $proposalCountBefore = DB::table('proposals')
            ->where('business_id', $business->getKey())
            ->count();
        $decisionCountBefore = DB::table('decisions')
            ->where('business_id', $business->getKey())
            ->count();
        $effectiveHeadCountBefore = DB::table('record_family_effective_heads')
            ->where('business_id', $business->getKey())
            ->count();

        $input = [
            ...$this->recordInput($membership),
            'approvedBy' => ['Fake Approver'],
            'approvalDate' => '1900-01-01',
            'lastUpdated' => '1900-01-01',
        ];
        $created = $this->recordWorkflow()->create(
            $user,
            $business,
            $input,
        );

        self::assertNotNull($created);
        self::assertTrue($created['created']);
        self::assertSame(
            CapitalDecisionRecordContract::CONTRACT_VERSION,
            $created['contractVersion'],
        );

        $row = DB::table('capital_decision_records')
            ->where('business_id', $business->getKey())
            ->sole();

        self::assertSame(
            (string) $snapshot->id,
            (string) $row->capital_approval_snapshot_id,
        );
        self::assertSame(
            (string) $snapshot->formal_record_version_id,
            (string) $row->formal_record_version_id,
        );
        self::assertSame(
            (string) $snapshot->proposal_version_id,
            (string) $row->proposal_version_id,
        );
        self::assertSame(
            (string) $decision->getKey(),
            (string) $row->governance_decision_id,
        );
        self::assertSame(
            (string) $snapshot->content_hash,
            (string) $row->approved_content_hash,
        );
        self::assertSame('2027-01-15', (string) $row->effective_date);
        self::assertSame('2027-04-15', (string) $row->review_date);
        self::assertSame(
            'Approved Base Capital Plan for opening.',
            (string) $row->decision_summary,
        );
        self::assertSame(
            ['Partner meeting note', 'Bank confirmation'],
            json_decode(
                (string) $row->evidence_references,
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );

        $read = $this->readModel($user, $business);

        self::assertNotNull($read);
        self::assertTrue($read['available']);
        self::assertTrue($read['recorded']);
        self::assertSame(
            'approved_signature_required',
            $read['status'],
        );
        self::assertSame('base', $read['approvedPlan']['preferredPlan']);
        self::assertSame(
            '1430.00',
            $read['approvedPlan']['totalCapitalRequirement'],
        );
        self::assertSame(
            '1000.00',
            $read['approvedPlan']['workingCapital'],
        );
        self::assertFalse(
            $read['approvedPlan']['workingCapitalMonthsApplicable'],
        );
        self::assertSame(
            '130.00',
            $read['approvedPlan']['contingency'],
        );
        self::assertTrue(
            $read['approvedPlan']['contingencyPercentageApplicable'],
        );
        self::assertSame(
            '10.00',
            $read['approvedPlan']['contingencyPercentage'],
        );
        self::assertSame(
            '500.00',
            $read['approvedPlan']['confirmedFunding'],
        );
        self::assertSame(
            '930.00',
            $read['approvedPlan']['fundingGap'],
        );
        self::assertSame(
            (string) $snapshot->content_hash,
            $read['approvedPlan']['approvedContentHash'],
        );
        self::assertSame(
            ['reduce_scope'],
            $read['approvedPlan']['capitalRule']['shortfallResponses'],
        );
        self::assertSame(
            1,
            $read['approvedPlan']['sourceRevisions']['capitalPlanning'],
        );
        self::assertSame(
            1,
            $read['approvedPlan']['sourceRevisions']['capitalRule'],
        );
        self::assertSame(
            2,
            $read['approvedPlan']['sourceRevisions']['capitalComparison'],
        );

        self::assertSame(
            'capital-record-exact@example.test',
            $read['governedApproval']['approvedBy'][0]['name'],
        );
        self::assertSame(
            'approval',
            $read['governedApproval']['approvedBy'][0]['evidenceKind'],
        );
        self::assertSame(
            $decision->resolved_at->toIso8601String(),
            $read['governedApproval']['approvalDate'],
        );
        self::assertSame(
            'capital-record-exact@example.test',
            $read['record']['decisionOwner'],
        );
        self::assertSame(
            '2027-01-15',
            $read['record']['effectiveDate'],
        );
        self::assertSame(
            '2027-04-15',
            $read['record']['reviewDate'],
        );
        self::assertNotNull($read['record']['lastUpdated']);

        self::assertFalse($read['semantics']['signedTruth']);
        self::assertFalse($read['semantics']['effectiveTruth']);
        self::assertTrue($read['semantics']['decisionRecordTruth']);
        self::assertFalse($read['semantics']['actionPlanTruth']);
        self::assertFalse($read['semantics']['capitalCallExecuted']);
        self::assertFalse($read['semantics']['contributionTruth']);
        self::assertFalse($read['semantics']['acceptedContributionTruth']);
        self::assertFalse($read['semantics']['equityTruth']);
        self::assertFalse($read['semantics']['ownershipTruth']);

        $again = $this->recordWorkflow()->create(
            $user,
            $business,
            [
                ...$input,
                'decisionSummary' => 'This different text must not overwrite history.',
            ],
        );

        self::assertNotNull($again);
        self::assertFalse($again['created']);
        self::assertSame($created['id'], $again['id']);
        $this->assertDatabaseCount('capital_decision_records', 1);
        self::assertSame(
            'Approved Base Capital Plan for opening.',
            DB::table('capital_decision_records')
                ->where('id', $created['id'])
                ->value('decision_summary'),
        );

        self::assertSame(
            $proposalCountBefore,
            DB::table('proposals')
                ->where('business_id', $business->getKey())
                ->count(),
        );
        self::assertSame(
            $decisionCountBefore,
            DB::table('decisions')
                ->where('business_id', $business->getKey())
                ->count(),
        );

        $formalAfter = DB::table('formal_record_versions')
            ->where('id', $snapshot->formal_record_version_id)
            ->sole();

        self::assertSame(
            (string) $formalBefore->content_hash,
            (string) $formalAfter->content_hash,
        );

        $states = DB::table('record_version_state_transitions')
            ->where('formal_record_version_id', $snapshot->formal_record_version_id)
            ->pluck('to_state')
            ->all();

        self::assertSame('approved', end($states));
        self::assertNotContains('ready_for_effect', $states);
        self::assertNotContains('effective', $states);

        $this->assertDatabaseCount('signature_requests', 0);
        $this->assertDatabaseCount('signatures', 0);
        $this->assertDatabaseCount('actions', 0);
        self::assertSame(
            $effectiveHeadCountBefore,
            DB::table('record_family_effective_heads')
                ->where('business_id', $business->getKey())
                ->count(),
        );
        self::assertSame(
            0,
            DB::table('record_family_effective_heads')
                ->where('formal_record_version_id', $snapshot->formal_record_version_id)
                ->count(),
        );
        $this->assertDatabaseCount('contributions', 0);
        $this->assertDatabaseCount('ownership_scenarios', 0);
        $this->assertDatabaseCount('ownership_registers', 0);

        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'capital.decision_record.created',
            'target_type' => 'capital_decision_record',
            'target_id' => $created['id'],
        ]);

        try {
            DB::table('capital_decision_records')
                ->where('id', $created['id'])
                ->update(['decision_summary' => 'Mutated']);
            self::fail('Decision Record must be immutable.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_newer_planning_changes_warn_without_recalculating_historical_approved_values(): void
    {
        [$user, $business, $membership] = $this->business(
            'capital-record-stale@example.test',
            'Capital Record Stale',
        );

        $this->approveCapital($user, $business, $membership);

        $this->recordWorkflow()->create(
            $user,
            $business,
            $this->recordInput($membership),
        );

        $before = $this->readModel($user, $business);
        self::assertNotNull($before);
        self::assertFalse($before['planningChangedSinceApproval']);
        self::assertSame(
            '1430.00',
            $before['approvedPlan']['totalCapitalRequirement'],
        );
        self::assertSame(
            '930.00',
            $before['approvedPlan']['fundingGap'],
        );

        $changed = $this->planningInput();
        $changed['confirmedFunding'] = '1200.00';

        $this->savePlanning($user, $business, 1, $changed);

        $after = $this->readModel($user, $business);

        self::assertNotNull($after);
        self::assertTrue($after['planningChangedSinceApproval']);
        self::assertTrue($after['historicalWarningRequired']);
        self::assertSame(
            '1430.00',
            $after['approvedPlan']['totalCapitalRequirement'],
        );
        self::assertSame(
            '930.00',
            $after['approvedPlan']['fundingGap'],
        );
        self::assertSame(
            $before['approvedPlan']['approvedContentHash'],
            $after['approvedPlan']['approvedContentHash'],
        );
    }

    public function test_owner_is_business_scoped_and_cross_tenant_access_fails_closed(): void
    {
        [$owner, $business, $membership] = $this->business(
            'capital-record-owner@example.test',
            'Capital Record Owner',
        );
        [$otherUser, $otherBusiness, $otherMembership] = $this->business(
            'capital-record-other@example.test',
            'Capital Record Other',
        );

        $this->approveCapital($owner, $business, $membership);

        try {
            $this->recordWorkflow()->create(
                $owner,
                $business,
                $this->recordInput($otherMembership),
            );
            self::fail('Cross-Business Decision Owner must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'active member of this Business',
                $exception->getMessage(),
            );
        }

        self::assertNull(
            $this->recordWorkflow()->create(
                $otherUser,
                $business,
                $this->recordInput($membership),
            ),
        );
        self::assertNull(
            $this->readModel($otherUser, $business),
        );

        self::assertSame(
            0,
            DB::table('capital_decision_records')
                ->where('business_id', $otherBusiness->getKey())
                ->count(),
        );
    }

    public function test_vote_based_approved_by_uses_actual_supporting_vote_evidence(): void
    {
        [$user, $business, $membership] = $this->business(
            'capital-record-vote@example.test',
            'Capital Record Vote',
        );

        $this->readyCapital($user, $business, 'base');
        $this->temporaryAuthority(
            $user,
            $business,
            $membership,
            method: 'vote',
            requiredApprovals: 0,
            requiredVotes: 1,
        );

        $approval = $this->approvalWorkflow();
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
        $approval->vote(
            $user,
            $business,
            VoteChoice::For,
            'Supporting vote for frozen Capital plan.',
        );
        $approval->resolve($user, $business);

        $this->recordWorkflow()->create(
            $user,
            $business,
            $this->recordInput($membership),
        );

        $read = $this->readModel($user, $business);

        self::assertNotNull($read);
        self::assertSame(
            'supporting_vote',
            $read['governedApproval']['approvedBy'][0]['evidenceKind'],
        );
        self::assertSame(
            'capital-record-vote@example.test',
            $read['governedApproval']['approvedBy'][0]['name'],
        );
    }

    public function test_genuinely_new_approved_version_can_have_a_new_decision_record(): void
    {
        [$user, $business, $membership] = $this->business(
            'capital-record-version@example.test',
            'Capital Record Version',
        );

        $this->approveCapital($user, $business, $membership);

        $first = $this->recordWorkflow()->create(
            $user,
            $business,
            $this->recordInput($membership),
        );

        self::assertNotNull($first);

        $changed = $this->planningInput();
        $changed['confirmedFunding'] = '700.00';
        $this->savePlanning($user, $business, 1, $changed);
        $this->saveRule($user, $business, 1);

        $comparison = $this->app
            ->make(RefreshCapitalComparisonDraftFromCanonical::class)
            ->execute($user, $business, 2);

        self::assertNotNull($comparison);

        $input = $comparison['input'];
        $input['preferredPlan'] = 'growth';

        $savedComparison = $this->app
            ->make(SaveCapitalComparisonDraft::class)
            ->execute($user, $business, 3, $input);

        self::assertNotNull($savedComparison);

        $approval = $this->approvalWorkflow();
        $secondPrepared = $approval->prepare($user, $business);
        self::assertNotNull($secondPrepared);

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
        $approval->approve($user, $business);
        $approval->resolve($user, $business);

        $second = $this->recordWorkflow()->create(
            $user,
            $business,
            [
                ...$this->recordInput($membership),
                'effectiveDate' => '2027-05-01',
                'reviewDate' => '2027-08-01',
                'decisionSummary' => 'Approved Growth Capital Plan after updated planning.',
            ],
        );

        self::assertNotNull($second);
        self::assertTrue($second['created']);
        self::assertNotSame($first['id'], $second['id']);
        $this->assertDatabaseCount('capital_decision_records', 2);

        $read = $this->readModel($user, $business);
        self::assertNotNull($read);
        self::assertSame('growth', $read['approvedPlan']['preferredPlan']);
        self::assertSame(
            'Approved Growth Capital Plan after updated planning.',
            $read['record']['decisionSummary'],
        );
    }

    private function assertCreateFailsWithoutApprovedSource(callable $callback): void
    {
        try {
            $callback();
            self::fail('Decision Record must require resolved governed approval.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'approved Capital Plan',
                $exception->getMessage(),
            );
        }
    }

    private function recordWorkflow(): CapitalDecisionRecordWorkflow
    {
        return $this->app->make(CapitalDecisionRecordWorkflow::class);
    }

    private function approvalWorkflow(): CapitalApprovalWorkflow
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
            ->make(GetCapitalDecisionRecordReadModel::class)
            ->execute($user, $business);
    }

    private function approveCapital(
        User $user,
        Business $business,
        Membership $membership,
        bool $signatureRequired = false,
    ): void {
        $this->readyCapital($user, $business, 'base');
        $this->temporaryAuthority(
            $user,
            $business,
            $membership,
            signatureRequired: $signatureRequired,
        );

        $approval = $this->approvalWorkflow();
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

        $comparison = $this->app
            ->make(RefreshCapitalComparisonDraftFromCanonical::class)
            ->execute($user, $business, 0);

        self::assertNotNull($comparison);

        $input = $comparison['input'];
        $input['preferredPlan'] = $preferred;

        $saved = $this->app
            ->make(SaveCapitalComparisonDraft::class)
            ->execute($user, $business, 1, $input);

        self::assertNotNull($saved);
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

    private function saveRule(
        User $user,
        Business $business,
        int $revision,
    ): void {
        self::assertNotNull(
            $this->app
                ->make(SaveCapitalRuleDraft::class)
                ->execute(
                    $user,
                    $business,
                    $revision,
                    [
                        'shortfallResponses' => ['reduce_scope'],
                        'allocationNotes' => 'Preserve operating buffer.',
                        'shortfallRuleNotes' => 'Reduce scope first.',
                        'capitalCallRuleNote' => null,
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
        string $method = 'approval',
        int $requiredApprovals = 1,
        int $requiredVotes = 0,
        bool $signatureRequired = false,
    ): void {
        $actors = [[
            'membership_id' => (string) $membership->getKey(),
            'capacity' => 'Capital Approver',
            'can_approve' => in_array(
                $method,
                ['approval', 'approval_and_vote'],
                true,
            ),
            'can_vote' => in_array(
                $method,
                ['vote', 'approval_and_vote'],
                true,
            ),
            'can_sign' => $signatureRequired,
        ]];

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
                'quorum_count' => 1,
                'signature_required' => $signatureRequired,
                'reserved_matter' => false,
                'amount_min' => null,
                'amount_max' => null,
                'actors' => $actors,
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
     * @return array<string,mixed>
     */
    private function recordInput(Membership $owner): array
    {
        return [
            'decisionOwnerMembershipId' => (string) $owner->getKey(),
            'effectiveDate' => '2027-01-15',
            'reviewDate' => '2027-04-15',
            'decisionSummary' => 'Approved Base Capital Plan for opening.',
            'evidenceReferences' => [
                'Partner meeting note',
                'Bank confirmation',
            ],
        ];
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
}
