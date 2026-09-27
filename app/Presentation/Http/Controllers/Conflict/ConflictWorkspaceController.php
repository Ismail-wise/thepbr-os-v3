<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Conflict;

use App\Application\Conflict\ConflictCaseWorkflow;
use App\Application\Conflict\ConflictDeadlockWorkflow;
use App\Application\Conflict\ConflictDecisionWorkflow;
use App\Application\Conflict\ConflictDirectDiscussionWorkflow;
use App\Application\Conflict\ConflictEscalationWorkflow;
use App\Application\Conflict\ConflictInvestigationWorkflow;
use App\Application\Conflict\ConflictMediationWorkflow;
use App\Application\Conflict\ConflictPolicyWorkflow;
use App\Application\Conflict\ConflictReferralWorkflow;
use App\Application\Conflict\ConflictReviewWorkflow;
use App\Application\Conflict\ConflictSettlementWorkflow;
use App\Application\Conflict\ConflictUrgentRiskWorkflow;
use App\Application\Conflict\CreateConflictAction;
use App\Application\Conflict\GetConflictWorkspace;
use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Conflict\Enums\DirectDiscussionOutcome;
use App\Domain\Conflict\Enums\MediationResponseOutcome;
use App\Domain\Records\Enums\FormalRecordState;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

final class ConflictWorkspaceController
{
    public function index(
        Request $request,
        GetConflictWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);
        $selected = $request->query('case');
        $payload = $workspace->execute(
            $user,
            $business,
            is_string($selected) ? $selected : null,
        );

        abort_if($payload === null, 404);

        return Inertia::render('Conflict/Index', [
            'conflict' => $payload,
        ]);
    }

    public function createPolicy(
        Request $request,
        ConflictPolicyWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'review_due_at' => ['nullable', 'date'],
            'conflict_owner_membership_id' => ['required', 'uuid'],
            'formal_decision_type' => ['required', 'string', 'max:160'],
            'deadlock_decision_type' => ['required', 'string', 'max:160'],
            'misconduct_decision_type' => ['required', 'string', 'max:160'],
            'urgent_risk_decision_type' => ['required', 'string', 'max:160'],
            'settlement_decision_type' => ['required', 'string', 'max:160'],
            'review_frequency' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'escalation_rules' => ['required', 'array', 'min:1', 'max:20'],
            'special_path_rules' => ['required', 'array', 'size:3'],
        ]);

        $effective = CarbonImmutable::parse($data['effective_from']);
        $review = isset($data['review_due_at'])
            ? CarbonImmutable::parse($data['review_due_at'])->endOfDay()
            : null;
        unset($data['effective_from'], $data['review_due_at']);

        $created = $this->validated(
            fn () => $workflow->createDraft(
                $user,
                $business,
                $data,
                $effective,
                $review,
            ),
            'conflict',
        );

        abort_if($created === null, 404);

        return back();
    }

    public function submitPolicy(
        Request $request,
        string $formalRecordVersion,
        ConflictPolicyWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->validated(
            fn () => $workflow->submitForGovernance(
                $user,
                $business,
                $formalRecordVersion,
                (int) $data['expected_revision'],
            ),
            'conflict',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function reviewPolicy(
        Request $request,
        string $formalRecordVersion,
        ConflictPolicyWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'target' => ['required', Rule::in([
                FormalRecordState::UnderReview->value,
                FormalRecordState::Approved->value,
                FormalRecordState::ChangesRequested->value,
            ])],
        ]);

        $ok = $this->validated(
            fn () => $workflow->advanceContentReview(
                $user,
                $business,
                $formalRecordVersion,
                FormalRecordState::from($data['target']),
            ),
            'conflict',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function syncPolicyDecision(
        Request $request,
        string $formalRecordVersion,
        ConflictPolicyWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        abort_unless(
            $workflow->syncApprovedDecision(
                $user,
                $business,
                $formalRecordVersion,
            ),
            404,
        );

        return back();
    }

    public function openCase(
        Request $request,
        ConflictCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'conflict_type' => ['required', 'string', 'max:48'],
            'description' => ['required', 'string', 'max:10000'],
            'business_impact' => ['required', 'string', 'max:10000'],
            'urgency' => ['required', Rule::in([
                'low', 'normal', 'high', 'critical',
            ])],
            'related_rule_reference' => ['nullable', 'string', 'max:1000'],
            'review_due_at' => ['nullable', 'date'],
            'participants' => ['required', 'array', 'min:1', 'max:50'],
            'view_membership_ids' => ['present', 'array', 'max:50'],
            'view_membership_ids.*' => ['uuid'],
            'manage_membership_ids' => ['present', 'array', 'max:50'],
            'manage_membership_ids.*' => ['uuid'],
        ]);

        $result = $this->validated(
            fn () => $workflow->open($user, $business, $data),
            'case',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function transitionCase(
        Request $request,
        string $case,
        ConflictCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'target' => ['required', Rule::enum(ConflictCaseStage::class)],
            'note_code' => ['nullable', 'string', 'max:120'],
        ]);

        $updated = $this->validated(
            fn () => $workflow->transition(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                ConflictCaseStage::from($data['target']),
                $data['note_code'] ?? null,
            ),
            'case',
        );

        abort_if($updated === null, 404);

        return back();
    }

    public function recordDirectDiscussion(
        Request $request,
        string $case,
        ConflictDirectDiscussionWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_case_revision' => ['required', 'integer', 'min:1'],
            'issues_discussed' => ['required', 'string', 'max:10000'],
            'party_position_summary' => ['required', 'string', 'max:10000'],
            'proposed_solutions' => ['required', 'string', 'max:10000'],
            'outcome' => ['required', Rule::enum(DirectDiscussionOutcome::class)],
            'participant_membership_ids' => [
                'required', 'array', 'min:1', 'max:50',
            ],
            'participant_membership_ids.*' => ['uuid'],
            'meeting_at' => ['nullable', 'date'],
            'follow_up_at' => ['nullable', 'date'],
        ]);

        $id = $this->validated(
            fn () => $workflow->record(
                $user,
                $business,
                $case,
                (int) $data['expected_case_revision'],
                $data['issues_discussed'],
                $data['party_position_summary'],
                $data['proposed_solutions'],
                DirectDiscussionOutcome::from($data['outcome']),
                $data['participant_membership_ids'],
                isset($data['meeting_at'])
                    ? CarbonImmutable::parse($data['meeting_at'])
                    : null,
                isset($data['follow_up_at'])
                    ? CarbonImmutable::parse($data['follow_up_at'])
                    : null,
            ),
            'discussion',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function scheduleMediation(
        Request $request,
        string $case,
        ConflictMediationWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_case_revision' => ['required', 'integer', 'min:1'],
            'mediator_type' => ['required', Rule::in(['internal', 'external'])],
            'neutrality_check' => ['required', 'string', 'max:10000'],
            'mediation_at' => ['required', 'date'],
            'mediator_membership_id' => ['nullable', 'uuid'],
            'external_mediator_reference' => [
                'nullable', 'string', 'max:240',
            ],
            'response_deadline' => ['nullable', 'date'],
        ]);

        $id = $this->validated(
            fn () => $workflow->schedule(
                $user,
                $business,
                $case,
                (int) $data['expected_case_revision'],
                $data['mediator_type'],
                $data['neutrality_check'],
                CarbonImmutable::parse($data['mediation_at']),
                $data['mediator_membership_id'] ?? null,
                $data['external_mediator_reference'] ?? null,
                isset($data['response_deadline'])
                    ? CarbonImmutable::parse($data['response_deadline'])
                    : null,
            ),
            'mediation',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function recordMediationResponse(
        Request $request,
        string $case,
        string $mediation,
        ConflictMediationWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'response' => [
                'required',
                Rule::enum(MediationResponseOutcome::class),
            ],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        abort_unless(
            $workflow->recordResponse(
                $user,
                $business,
                $case,
                $mediation,
                MediationResponseOutcome::from($data['response']),
                $data['note'] ?? null,
            ),
            404,
        );

        return back();
    }

    public function completeMediation(
        Request $request,
        string $case,
        string $mediation,
        ConflictMediationWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_case_revision' => ['required', 'integer', 'min:1'],
            'expected_mediation_revision' => ['required', 'integer', 'min:1'],
            'summary' => ['required', 'string', 'max:10000'],
            'proposed_settlement' => ['required', 'string', 'max:10000'],
        ]);

        $result = $this->validated(
            fn () => $workflow->complete(
                $user,
                $business,
                $case,
                $mediation,
                (int) $data['expected_case_revision'],
                (int) $data['expected_mediation_revision'],
                $data['summary'],
                $data['proposed_settlement'],
            ),
            'mediation',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function submitDecision(
        Request $request,
        string $case,
        ConflictDecisionWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_case_revision' => ['required', 'integer', 'min:1'],
            'reviewer_membership_id' => ['required', 'uuid'],
            'proposed_decision_summary' => ['required', 'string', 'max:10000'],
            'conditions' => ['nullable', 'string', 'max:10000'],
            'appeal_reference' => ['nullable', 'string', 'max:5000'],
            'review_due_at' => ['nullable', 'date'],
        ]);

        $result = $this->validated(
            fn () => $workflow->submit(
                $user,
                $business,
                $case,
                (int) $data['expected_case_revision'],
                $data['reviewer_membership_id'],
                $data['proposed_decision_summary'],
                $data['conditions'] ?? null,
                $data['appeal_reference'] ?? null,
                isset($data['review_due_at'])
                    ? CarbonImmutable::parse($data['review_due_at'])
                    : null,
            ),
            'decision',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function openDecision(
        Request $request,
        string $case,
        string $submission,
        ConflictDecisionWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'meeting_id' => ['nullable', 'uuid'],
        ]);

        $decision = $this->validated(
            fn () => $workflow->open(
                $user,
                $business,
                $case,
                $submission,
                $data['meeting_id'] ?? null,
            ),
            'decision',
        );

        abort_if($decision === null, 404);

        return back();
    }

    public function applyDecision(
        Request $request,
        string $case,
        string $submission,
        ConflictDecisionWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_case_revision' => ['required', 'integer', 'min:1'],
            'target' => ['required', Rule::in([
                ConflictCaseStage::Settlement->value,
                ConflictCaseStage::Escalation->value,
                ConflictCaseStage::ExitLegal->value,
                ConflictCaseStage::Resolved->value,
            ])],
        ]);

        $result = $this->validated(
            fn () => $workflow->applyApprovedDecision(
                $user,
                $business,
                $case,
                $submission,
                (int) $data['expected_case_revision'],
                ConflictCaseStage::from($data['target']),
            ),
            'decision',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function enterEscalation(
        Request $request,
        string $case,
        ConflictEscalationWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_case_revision' => ['required', 'integer', 'min:1'],
            'escalation_rule_id' => ['required', 'uuid'],
        ]);

        $id = $this->validated(
            fn () => $workflow->enter(
                $user,
                $business,
                $case,
                (int) $data['expected_case_revision'],
                $data['escalation_rule_id'],
            ),
            'escalation',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function enterDeadlock(
        Request $request,
        string $case,
        ConflictDeadlockWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_case_revision' => ['required', 'integer', 'min:1'],
            'failed_vote_count' => ['required', 'integer', 'min:0'],
            'cooling_off_until' => ['nullable', 'date'],
            'neutral_reference' => ['nullable', 'string', 'max:240'],
        ]);

        $id = $this->validated(
            fn () => $workflow->enter(
                $user,
                $business,
                $case,
                (int) $data['expected_case_revision'],
                (int) $data['failed_vote_count'],
                isset($data['cooling_off_until'])
                    ? CarbonImmutable::parse($data['cooling_off_until'])
                    : null,
                $data['neutral_reference'] ?? null,
            ),
            'deadlock',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function bindDeadlockDecision(
        Request $request,
        string $case,
        string $deadlock,
        ConflictDeadlockWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'decision_id' => ['required', 'uuid'],
        ]);

        abort_unless(
            $workflow->bindDecision(
                $user,
                $business,
                $case,
                $deadlock,
                (int) $data['expected_revision'],
                $data['decision_id'],
            ),
            404,
        );

        return back();
    }

    public function resolveDeadlock(
        Request $request,
        string $case,
        string $deadlock,
        ConflictDeadlockWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'outcome' => ['required', 'string', 'max:5000'],
        ]);

        abort_unless(
            $this->validated(
                fn () => $workflow->resolve(
                    $user,
                    $business,
                    $case,
                    $deadlock,
                    (int) $data['expected_revision'],
                    $data['outcome'],
                ),
                'deadlock',
            ),
            404,
        );

        return back();
    }

    public function openInvestigation(
        Request $request,
        string $case,
        ConflictInvestigationWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_case_revision' => ['required', 'integer', 'min:1'],
            'investigation_owner_membership_id' => ['required', 'uuid'],
            'allegation' => ['required', 'string', 'max:10000'],
            'temporary_restriction_proposal' => [
                'nullable', 'string', 'max:10000',
            ],
        ]);

        $id = $this->validated(
            fn () => $workflow->open(
                $user,
                $business,
                $case,
                (int) $data['expected_case_revision'],
                $data['investigation_owner_membership_id'],
                $data['allegation'],
                $data['temporary_restriction_proposal'] ?? null,
            ),
            'investigation',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function completeInvestigation(
        Request $request,
        string $case,
        string $investigation,
        ConflictInvestigationWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_case_revision' => ['required', 'integer', 'min:1'],
            'expected_investigation_revision' => [
                'required', 'integer', 'min:1',
            ],
            'finding' => ['required', 'string', 'max:10000'],
            'sanction_remedy_recommendation' => [
                'nullable', 'string', 'max:10000',
            ],
            'appeal_reference' => ['nullable', 'string', 'max:5000'],
            'exit_trigger_recommended' => ['required', 'boolean'],
        ]);

        abort_unless(
            $this->validated(
                fn () => $workflow->completeWithFinding(
                    $user,
                    $business,
                    $case,
                    $investigation,
                    (int) $data['expected_case_revision'],
                    (int) $data['expected_investigation_revision'],
                    $data['finding'],
                    $data['sanction_remedy_recommendation'] ?? null,
                    $data['appeal_reference'] ?? null,
                    (bool) $data['exit_trigger_recommended'],
                ),
                'investigation',
            ),
            404,
        );

        return back();
    }

    public function openUrgentRisk(
        Request $request,
        string $case,
        ConflictUrgentRiskWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_case_revision' => ['required', 'integer', 'min:1'],
            'urgent_risk' => ['required', 'string', 'max:10000'],
            'immediate_action' => ['required', 'string', 'max:10000'],
            'informed_parties' => ['required', 'string', 'max:10000'],
            'review_deadline' => ['required', 'date'],
            'emergency_authority_grant_id' => ['nullable', 'uuid'],
        ]);

        $id = $this->validated(
            fn () => $workflow->open(
                $user,
                $business,
                $case,
                (int) $data['expected_case_revision'],
                $data['urgent_risk'],
                $data['immediate_action'],
                $data['informed_parties'],
                CarbonImmutable::parse($data['review_deadline']),
                $data['emergency_authority_grant_id'] ?? null,
            ),
            'urgent_risk',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function bindUrgentDecision(
        Request $request,
        string $case,
        string $urgentRisk,
        ConflictUrgentRiskWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'decision_id' => ['required', 'uuid'],
        ]);

        abort_unless(
            $workflow->bindFinalDecision(
                $user,
                $business,
                $case,
                $urgentRisk,
                (int) $data['expected_revision'],
                $data['decision_id'],
            ),
            404,
        );

        return back();
    }

    public function createSettlement(
        Request $request,
        string $case,
        ConflictSettlementWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'review_due_at' => ['nullable', 'date'],
            'source_mediation_id' => ['nullable', 'uuid'],
            'source_decision_id' => ['nullable', 'uuid'],
            'settlement_terms' => ['required', 'string', 'max:20000'],
            'required_actions_summary' => ['nullable', 'string', 'max:10000'],
            'responsible_owner_membership_id' => ['required', 'uuid'],
            'due_at' => ['nullable', 'date'],
            'financial_settlement_minor_units' => [
                'nullable', 'integer', 'min:0',
            ],
            'currency' => ['nullable', 'string', 'size:3'],
            'confidentiality_terms' => ['nullable', 'string', 'max:10000'],
            'future_conduct_terms' => ['nullable', 'string', 'max:10000'],
            'review_date' => ['nullable', 'date'],
        ]);

        $effective = CarbonImmutable::parse($data['effective_from']);
        $review = isset($data['review_due_at'])
            ? CarbonImmutable::parse($data['review_due_at'])->endOfDay()
            : null;
        unset($data['effective_from'], $data['review_due_at']);

        $result = $this->validated(
            fn () => $workflow->createDraft(
                $user,
                $business,
                $case,
                $data,
                $effective,
                $review,
            ),
            'settlement',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function submitSettlement(
        Request $request,
        string $case,
        string $formalRecordVersion,
        ConflictSettlementWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->validated(
            fn () => $workflow->submitForGovernance(
                $user,
                $business,
                $case,
                $formalRecordVersion,
                (int) $data['expected_revision'],
            ),
            'settlement',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function reviewSettlement(
        Request $request,
        string $case,
        string $formalRecordVersion,
        ConflictSettlementWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'target' => ['required', Rule::in([
                FormalRecordState::UnderReview->value,
                FormalRecordState::Approved->value,
                FormalRecordState::ChangesRequested->value,
            ])],
        ]);

        abort_unless(
            $this->validated(
                fn () => $workflow->advanceContentReview(
                    $user,
                    $business,
                    $case,
                    $formalRecordVersion,
                    FormalRecordState::from($data['target']),
                ),
                'settlement',
            ),
            404,
        );

        return back();
    }

    public function bindSettlementDocument(
        Request $request,
        string $case,
        string $formalRecordVersion,
        ConflictSettlementWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'document_version_id' => ['required', 'uuid'],
        ]);

        abort_unless(
            $workflow->bindDocument(
                $user,
                $business,
                $case,
                $formalRecordVersion,
                $data['document_version_id'],
            ),
            404,
        );

        return back();
    }

    public function requestSettlementSignature(
        Request $request,
        string $case,
        string $formalRecordVersion,
        ConflictSettlementWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        abort_if(
            $workflow->requestRequiredSignature(
                $user,
                $business,
                $case,
                $formalRecordVersion,
            ) === null,
            404,
        );

        return back();
    }

    public function effectSettlement(
        Request $request,
        string $case,
        string $formalRecordVersion,
        ConflictSettlementWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_case_revision' => ['required', 'integer', 'min:1'],
        ]);

        abort_unless(
            $workflow->makeEffectiveAndResolve(
                $user,
                $business,
                $case,
                (int) $data['expected_case_revision'],
                $formalRecordVersion,
            ),
            404,
        );

        return back();
    }

    public function refer(
        Request $request,
        string $case,
        ConflictReferralWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_case_revision' => ['required', 'integer', 'min:1'],
            'referral_type' => ['required', Rule::in([
                'exit_buyout',
                'share_transfer',
                'external_mediation',
                'arbitration',
                'court',
                'legal_counsel',
            ])],
            'trigger_reason' => ['required', 'string', 'max:10000'],
            'external_reference' => ['nullable', 'string', 'max:240'],
        ]);

        $id = $this->validated(
            fn () => $workflow->refer(
                $user,
                $business,
                $case,
                (int) $data['expected_case_revision'],
                $data['referral_type'],
                $data['trigger_reason'],
                $data['external_reference'] ?? null,
            ),
            'referral',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function createReview(
        Request $request,
        string $case,
        ConflictReviewWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'reviewer_membership_id' => ['required', 'uuid'],
            'due_at' => ['nullable', 'date'],
        ]);

        $id = $workflow->create(
            $user,
            $business,
            $case,
            $data['reviewer_membership_id'],
            isset($data['due_at'])
                ? CarbonImmutable::parse($data['due_at'])
                : null,
        );

        abort_if($id === null, 404);

        return back();
    }

    public function completeReview(
        Request $request,
        string $case,
        string $review,
        ConflictReviewWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'outcome' => ['required', Rule::in([
                'continue', 'resolved', 'escalate', 'amend_policy',
            ])],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        abort_unless(
            $this->validated(
                fn () => $workflow->complete(
                    $user,
                    $business,
                    $case,
                    $review,
                    (int) $data['expected_revision'],
                    $data['outcome'],
                    $data['notes'] ?? null,
                ),
                'review',
            ),
            404,
        );

        return back();
    }

    public function createAction(
        Request $request,
        string $case,
        CreateConflictAction $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'operations_role_id' => ['required', 'uuid'],
            'assigned_membership_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'source_type' => ['required', 'string', 'max:48'],
            'source_id' => ['required', 'uuid'],
            'description' => ['nullable', 'string', 'max:10000'],
            'due_at' => ['nullable', 'date'],
        ]);

        $action = $this->validated(
            fn () => $workflow->execute(
                $user,
                $business,
                $case,
                $data['operations_role_id'],
                $data['assigned_membership_id'],
                $data['title'],
                $data['source_type'],
                $data['source_id'],
                $data['description'] ?? null,
                isset($data['due_at'])
                    ? CarbonImmutable::parse($data['due_at'])
                    : null,
            ),
            'action',
        );

        abort_if($action === null, 404);

        return back();
    }

    /** @return array{User,Business} */
    private function context(Request $request): array
    {
        $user = $request->user();
        $business = $request->attributes->get(
            EnsureCurrentBusinessContext::ATTRIBUTE_KEY,
        );

        if (! $user instanceof User || ! $business instanceof Business) {
            abort(403);
        }

        return [$user, $business];
    }

    private function validated(callable $callback, string $field): mixed
    {
        try {
            return $callback();
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                $field => $exception->getMessage(),
            ]);
        }
    }
}
