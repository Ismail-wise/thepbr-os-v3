<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Formation;

use App\Application\Formation\BusinessModelPlanning;
use App\Application\Formation\BusinessValuationPlanning;
use App\Application\Formation\CapitalApprovalWorkflow;
use App\Application\Formation\CapitalDecisionRecordWorkflow;
use App\Application\Formation\CapitalPlanning;
use App\Application\Formation\ExistingBusinessBaseline;
use App\Application\Formation\GetFormationWorkspace;
use App\Application\Formation\NewBusinessPlanning;
use App\Application\Formation\RecordDeepFeasibilityAssessmentRun;
use App\Application\Formation\RefreshCapitalComparisonDraftFromCanonical;
use App\Application\Formation\SaveCapitalComparisonDraft;
use App\Application\Formation\SaveCapitalPlanningDraft;
use App\Application\Formation\SaveCapitalRuleDraft;
use App\Domain\Capital\ValueObjects\CapitalRequirement;
use App\Domain\Governance\Enums\ProposalReviewOutcome;
use App\Domain\Governance\Enums\VoteChoice;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;
use RuntimeException;

final class FormationController
{
    public function index(
        Request $request,
        GetFormationWorkspace $workspace,
    ): InertiaResponse {
        [$user, $business] = $this->context($request);

        $payload = $workspace->execute($user, $business);

        abort_if($payload === null, 404);

        return Inertia::render('Formation/Index', [
            'formation' => $payload,
        ]);
    }

    public function saveIdea(
        Request $request,
        NewBusinessPlanning $planning,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'summary' => ['nullable', 'string', 'max:4000'],
            'problem' => ['nullable', 'string', 'max:4000'],
            'target_customer' => ['nullable', 'string', 'max:4000'],
            'proposed_solution' => ['nullable', 'string', 'max:4000'],
        ]);

        $result = $this->validatedCall(fn () => $planning->saveIdea(
            $user,
            $business,
            (int) $data['expected_revision'],
            [
                'summary' => $data['summary'] ?? null,
                'problem' => $data['problem'] ?? null,
                'target_customer' => $data['target_customer'] ?? null,
                'proposed_solution' => $data['proposed_solution'] ?? null,
            ],
        ));

        abort_if($result === null, 404);

        return back();
    }

    public function saveBmc(
        Request $request,
        BusinessModelPlanning $planning,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $rules = [
            'expected_revision' => ['required', 'integer', 'min:0'],
        ];

        foreach ($this->bmcKeys() as $key) {
            $rules[$key] = ['nullable', 'string', 'max:8000'];
        }

        $data = $request->validate($rules);
        $blocks = [];

        foreach ($this->bmcKeys() as $key) {
            $blocks[$key] = $data[$key] ?? null;
        }

        $result = $this->validatedCall(fn () => $planning->saveBmc(
            $user,
            $business,
            (int) $data['expected_revision'],
            $blocks,
        ));

        abort_if($result === null, 404);

        return back();
    }

    public function saveBusinessModelFoundation(
        Request $request,
        BusinessModelPlanning $planning,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $money = [
            'nullable',
            'regex:/\A\d{1,16}(?:\.\d{1,2})?\z/',
        ];

        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'business_purpose' => ['nullable', 'string', 'max:8000'],
            'market' => ['nullable', 'string', 'max:8000'],
            'location' => ['nullable', 'string', 'max:4000'],
            'competition_alternatives' => ['nullable', 'string', 'max:8000'],
            'operating_model' => ['nullable', 'string', 'max:8000'],
            'excluded_activities' => ['nullable', 'string', 'max:8000'],
            'pricing_notes' => ['nullable', 'string', 'max:8000'],
            'unit_name' => ['nullable', 'string', 'max:80'],
            'average_selling_price' => $money,
            'variable_cost_per_unit' => $money,
            'monthly_fixed_cost' => $money,
            'expected_monthly_units' => [
                'nullable',
                'regex:/\A\d{1,16}(?:\.\d{1,2})?\z/',
            ],
            'scalability_strategy' => ['nullable', 'string', 'max:8000'],
            'scalability_constraints' => ['nullable', 'string', 'max:8000'],
            'first_12_month_plan' => ['nullable', 'string', 'max:12000'],
        ]);

        $fields = [];

        foreach ($this->businessModelFoundationKeys() as $key) {
            $fields[$key] = $data[$key] ?? null;
        }

        $result = $this->validatedCall(
            fn () => $planning->saveOperatingProfile(
                $user,
                $business,
                (int) $data['expected_revision'],
                $fields,
            ),
        );

        abort_if($result === null, 404);

        return back();
    }

    public function addAssumption(
        Request $request,
        NewBusinessPlanning $planning,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'category' => ['required', 'string', 'max:80'],
            'statement' => ['required', 'string', 'max:4000'],
            'status' => [
                'required',
                Rule::in(['planned', 'testing', 'validated', 'invalidated']),
            ],
        ]);

        $id = $this->validatedCall(fn () => $planning->addAssumption(
            $user,
            $business,
            $data['category'],
            $data['statement'],
            $data['status'],
        ));

        abort_if($id === null, 404);

        return back();
    }

    public function addValidation(
        Request $request,
        NewBusinessPlanning $planning,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'assumption_id' => ['nullable', 'uuid'],
            'method' => ['required', 'string', 'max:160'],
            'status' => [
                'required',
                Rule::in(['planned', 'in_progress', 'completed']),
            ],
            'result_summary' => ['nullable', 'string', 'max:4000'],
            'occurred_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $id = $this->validatedCall(fn () => $planning->addValidationActivity(
            $user,
            $business,
            $data['assumption_id'] ?? null,
            $data['method'],
            $data['status'],
            $data['result_summary'] ?? null,
            $data['occurred_on'] ?? null,
        ));

        abort_if($id === null, 404);

        return back();
    }

    public function linkValidationEvidence(
        Request $request,
        string $validationActivity,
        NewBusinessPlanning $planning,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'evidence_id' => ['required', 'uuid'],
        ]);

        $id = $planning->linkValidationEvidence(
            $user,
            $business,
            $validationActivity,
            $data['evidence_id'],
        );

        abort_if($id === null, 404);

        return back();
    }

    public function recordDeepFeasibilityAssessment(
        Request $request,
        RecordDeepFeasibilityAssessmentRun $recorder,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $result = $recorder->execute($user, $business);

        abort_if($result === null, 404);

        return back();
    }

    public function addFeasibility(
        Request $request,
        NewBusinessPlanning $planning,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'projected_monthly_revenue' => [
                'required',
                'regex:/\A\d{1,16}(?:\.\d{1,2})?\z/',
            ],
            'projected_monthly_cost' => [
                'required',
                'regex:/\A\d{1,16}(?:\.\d{1,2})?\z/',
            ],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $id = $planning->addFeasibilityScenario(
            $user,
            $business,
            $data['name'],
            $data['projected_monthly_revenue'],
            $data['projected_monthly_cost'],
            $data['notes'] ?? null,
        );

        abort_if($id === null, 404);

        return back();
    }

    public function savePartnershipFit(
        Request $request,
        NewBusinessPlanning $planning,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'goals_alignment' => ['nullable', 'string', 'max:4000'],
            'role_expectations' => ['nullable', 'string', 'max:4000'],
            'decision_process' => ['nullable', 'string', 'max:4000'],
            'risk_tolerance' => ['nullable', 'string', 'max:4000'],
            'unresolved_questions' => ['nullable', 'string', 'max:4000'],
        ]);

        $result = $this->validatedCall(
            fn () => $planning->savePartnershipFit(
                $user,
                $business,
                (int) $data['expected_revision'],
                [
                    'goals_alignment' => $data['goals_alignment'] ?? null,
                    'role_expectations' => $data['role_expectations'] ?? null,
                    'decision_process' => $data['decision_process'] ?? null,
                    'risk_tolerance' => $data['risk_tolerance'] ?? null,
                    'unresolved_questions' => $data['unresolved_questions'] ?? null,
                ],
            ),
        );

        abort_if($result === null, 404);

        return back();
    }

    public function recordDirection(
        Request $request,
        NewBusinessPlanning $planning,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'direction' => [
                'required',
                Rule::in(['go', 'revise', 'hold', 'no_go']),
            ],
            'rationale' => ['required', 'string', 'max:4000'],
        ]);

        $id = $this->validatedCall(fn () => $planning->recordDirection(
            $user,
            $business,
            $data['direction'],
            $data['rationale'],
        ));

        abort_if($id === null, 404);

        return back();
    }

    public function saveExistingProfile(
        Request $request,
        ExistingBusinessBaseline $baseline,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'operating_since' => ['nullable', 'date_format:Y-m-d'],
            'summary' => ['nullable', 'string', 'max:4000'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $result = $this->validatedCall(fn () => $baseline->saveProfile(
            $user,
            $business,
            (int) $data['expected_revision'],
            [
                'operating_since' => $data['operating_since'] ?? null,
                'summary' => $data['summary'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
        ));

        abort_if($result === null, 404);

        return back();
    }

    public function addFinancialSnapshot(
        Request $request,
        ExistingBusinessBaseline $baseline,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $money = ['required', 'regex:/\A\d{1,16}(?:\.\d{1,2})?\z/'];

        $data = $request->validate([
            'as_of_date' => ['required', 'date_format:Y-m-d'],
            'revenue' => $money,
            'expenses' => $money,
            'cash' => $money,
            'receivables' => $money,
            'payables' => $money,
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $id = $baseline->addFinancialSnapshot(
            $user,
            $business,
            $data,
        );

        abort_if($id === null, 404);

        return back();
    }

    public function addAsset(
        Request $request,
        ExistingBusinessBaseline $baseline,
    ): RedirectResponse {
        return $this->appendNamedMoney(
            $request,
            $baseline,
            'asset',
        );
    }

    public function addLiability(
        Request $request,
        ExistingBusinessBaseline $baseline,
    ): RedirectResponse {
        return $this->appendNamedMoney(
            $request,
            $baseline,
            'liability',
        );
    }

    public function addOwnerPosition(
        Request $request,
        ExistingBusinessBaseline $baseline,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'owner_name' => ['required', 'string', 'max:160'],
            'baseline_percent' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $id = $baseline->addOwnerPosition(
            $user,
            $business,
            $data,
        );

        abort_if($id === null, 404);

        return back();
    }

    public function addObligation(
        Request $request,
        ExistingBusinessBaseline $baseline,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'details' => ['nullable', 'string', 'max:4000'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $id = $baseline->addObligation(
            $user,
            $business,
            $data,
        );

        abort_if($id === null, 404);

        return back();
    }

    public function addRisk(
        Request $request,
        ExistingBusinessBaseline $baseline,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'risk' => ['required', 'string', 'max:160'],
            'control_status' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $id = $baseline->addRiskSnapshot(
            $user,
            $business,
            $data,
        );

        abort_if($id === null, 404);

        return back();
    }

    public function addConstraint(
        Request $request,
        ExistingBusinessBaseline $baseline,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'details' => ['nullable', 'string', 'max:4000'],
        ]);

        $id = $baseline->addAgreementConstraint(
            $user,
            $business,
            $data,
        );

        abort_if($id === null, 404);

        return back();
    }

    public function saveGap(
        Request $request,
        ExistingBusinessBaseline $baseline,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'gaps' => ['nullable', 'string', 'max:8000'],
            'priorities' => ['nullable', 'string', 'max:8000'],
        ]);

        $result = $this->validatedCall(fn () => $baseline->saveGapAssessment(
            $user,
            $business,
            (int) $data['expected_revision'],
            [
                'gaps' => $data['gaps'] ?? null,
                'priorities' => $data['priorities'] ?? null,
            ],
        ));

        abort_if($result === null, 404);

        return back();
    }

    public function saveConversion(
        Request $request,
        ExistingBusinessBaseline $baseline,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'plan' => ['required', 'string', 'max:10000'],
        ]);

        $result = $this->validatedCall(
            fn () => $baseline->saveConversionPlan(
                $user,
                $business,
                (int) $data['expected_revision'],
                $data['plan'],
            ),
        );

        abort_if($result === null, 404);

        return back();
    }

    public function calculateBusinessValuation(
        Request $request,
        BusinessValuationPlanning $valuation,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $money = [
            'nullable',
            'regex:/\A\d{1,16}(?:\.\d{1,2})?\z/',
        ];
        $assumption = [
            'nullable',
            'regex:/\A\d{1,4}(?:\.\d{1,4})?\z/',
        ];

        $data = $request->validate([
            'as_of_date' => ['required', 'date_format:Y-m-d'],
            'historical' => ['sometimes', 'array'],
            'historical.ebitda' => $money,
            'historical.owner_earnings' => $money,
            'historical.free_cash_flow' => $money,
            'historical.debt' => $money,
            'assumptions' => ['sometimes', 'array'],
            'assumptions.ebitda_multiple' => $assumption,
            'assumptions.sde_multiple' => $assumption,
            'assumptions.growth_rate_percent' => [
                'nullable',
                'regex:/\A-?\d{1,3}(?:\.\d{1,4})?\z/',
            ],
            'assumptions.discount_rate_percent' => $assumption,
            'assumptions.terminal_growth_rate_percent' => $assumption,
            'review_state' => [
                'required',
                Rule::in(['draft', 'reviewed']),
            ],
        ]);

        $historical = array_filter(
            $data['historical'] ?? [],
            static fn (mixed $value): bool => $value !== null
                && $value !== '',
        );
        $assumptions = array_filter(
            $data['assumptions'] ?? [],
            static fn (mixed $value): bool => $value !== null
                && $value !== '',
        );

        $result = $this->validatedCall(
            fn () => $valuation->calculateAndRecord(
                $user,
                $business,
                $data['as_of_date'],
                $historical,
                $assumptions,
                $data['review_state'],
            ),
        );

        abort_if($result === null, 404);

        return back();
    }

    public function addValuation(
        Request $request,
        ExistingBusinessBaseline $baseline,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'as_of_date' => ['required', 'date_format:Y-m-d'],
            'amount' => [
                'required',
                'regex:/\A\d{1,16}(?:\.\d{1,2})?\z/',
            ],
            'method' => ['required', 'string', 'max:160'],
            'review_state' => [
                'required',
                Rule::in(['draft', 'reviewed']),
            ],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $id = $this->validatedCall(fn () => $baseline->addValuation(
            $user,
            $business,
            $data,
        ));

        abort_if($id === null, 404);

        return back();
    }

    public function saveCapitalPlanningDraft(
        Request $request,
        SaveCapitalPlanningDraft $drafts,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'input' => ['required', 'array'],
        ]);

        try {
            $result = $drafts->execute(
                $user,
                $business,
                (int) $data['expected_revision'],
                $data['input'],
            );
        } catch (StaleRevision) {
            throw ValidationException::withMessages([
                'capital_draft' => 'This Capital planning draft changed after you opened it. Reload the latest saved draft, review it, and then save your changes again.',
            ]);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'capital_draft' => 'Check the visible Capital planning fields. Use non-negative amounts and complete the description for every cost item you started.',
            ]);
        }

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Capital planning draft saved.',
        );
    }

    public function saveCapitalRuleDraft(
        Request $request,
        SaveCapitalRuleDraft $drafts,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'input' => ['required', 'array'],
        ]);

        try {
            $result = $drafts->execute(
                $user,
                $business,
                (int) $data['expected_revision'],
                $data['input'],
            );
        } catch (StaleRevision) {
            throw ValidationException::withMessages([
                'capital_rule' => 'This Capital Rule draft changed after you opened it. Reload the latest saved rule, review it, and then save again.',
            ]);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'capital_rule' => 'Complete the current Capital calculation and check the visible Capital Rule fields before saving.',
            ]);
        }

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Capital Rule draft saved.',
        );
    }

    public function refreshCapitalComparisonDraft(
        Request $request,
        RefreshCapitalComparisonDraftFromCanonical $comparisons,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $result = $comparisons->execute(
                $user,
                $business,
                (int) $data['expected_revision'],
            );
        } catch (StaleRevision) {
            throw ValidationException::withMessages([
                'capital_comparison' => 'This Capital comparison draft changed after you opened it. Reload the latest comparison before refreshing.',
            ]);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'capital_comparison' => 'Save the current Capital planning draft before preparing or refreshing Lean, Base and Growth.',
            ]);
        }

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Capital comparison prepared from the current Capital plan.',
        );
    }

    public function saveCapitalComparisonDraft(
        Request $request,
        SaveCapitalComparisonDraft $comparisons,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'input' => ['required', 'array'],
        ]);

        try {
            $result = $comparisons->execute(
                $user,
                $business,
                (int) $data['expected_revision'],
                $data['input'],
            );
        } catch (StaleRevision) {
            throw ValidationException::withMessages([
                'capital_comparison' => 'This Capital comparison draft changed after you opened it. Reload the latest comparison, review it, and then save again.',
            ]);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'capital_comparison' => 'Check the visible Lean, Base and Growth planning fields before saving.',
            ]);
        }

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Capital comparison draft saved.',
        );
    }

    public function prepareCapitalApproval(
        Request $request,
        CapitalApprovalWorkflow $approval,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $result = $this->capitalApprovalCall(
            fn () => $approval->prepare($user, $business),
        );

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Final Capital Plan prepared for governed approval.',
        );
    }

    public function createCapitalApprovalReview(
        Request $request,
        CapitalApprovalWorkflow $approval,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'reviewer_membership_id' => ['required', 'uuid'],
        ]);

        $result = $this->capitalApprovalCall(
            fn () => $approval->createProposalReview(
                $user,
                $business,
                (string) $data['reviewer_membership_id'],
            ),
        );

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Final Capital Plan review started.',
        );
    }

    public function completeCapitalApprovalReview(
        Request $request,
        CapitalApprovalWorkflow $approval,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'outcome' => ['required', Rule::in([
                ProposalReviewOutcome::Approved->value,
                ProposalReviewOutcome::ChangesRequested->value,
                ProposalReviewOutcome::Rejected->value,
            ])],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $result = $this->capitalApprovalCall(
            fn () => $approval->completeProposalReview(
                $user,
                $business,
                ProposalReviewOutcome::from((string) $data['outcome']),
                $data['notes'] ?? null,
            ),
        );

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Final Capital Plan review recorded.',
        );
    }

    public function openCapitalApproval(
        Request $request,
        CapitalApprovalWorkflow $approval,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'meeting_id' => ['nullable', 'uuid'],
        ]);

        $result = $this->capitalApprovalCall(
            fn () => $approval->openDecision(
                $user,
                $business,
                $data['meeting_id'] ?? null,
            ),
        );

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Capital approval requirements are now open.',
        );
    }

    public function recordCapitalApproval(
        Request $request,
        CapitalApprovalWorkflow $approval,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'rationale' => ['nullable', 'string', 'max:4000'],
        ]);

        $result = $this->capitalApprovalCall(
            fn () => $approval->approve(
                $user,
                $business,
                $data['rationale'] ?? null,
            ),
        );

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Your governed Capital approval was recorded.',
        );
    }

    public function castCapitalApprovalVote(
        Request $request,
        CapitalApprovalWorkflow $approval,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'choice' => ['required', Rule::in([
                VoteChoice::For->value,
                VoteChoice::Against->value,
                VoteChoice::Abstain->value,
            ])],
            'rationale' => ['nullable', 'string', 'max:4000'],
        ]);

        $result = $this->capitalApprovalCall(
            fn () => $approval->vote(
                $user,
                $business,
                VoteChoice::from((string) $data['choice']),
                $data['rationale'] ?? null,
            ),
        );

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Your governed Capital vote was recorded.',
        );
    }

    public function resolveCapitalApproval(
        Request $request,
        CapitalApprovalWorkflow $approval,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $result = $this->capitalApprovalCall(
            fn () => $approval->resolve($user, $business),
        );

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Capital Plan approved. It is not yet signed or effective.',
        );
    }

    public function createCapitalDecisionRecord(
        Request $request,
        CapitalDecisionRecordWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'decision_owner_membership_id' => ['required', 'uuid'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'review_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:effective_date',
            ],
            'decision_summary' => ['required', 'string', 'max:2000'],
            'evidence_references' => ['sometimes', 'array', 'max:20'],
            'evidence_references.*' => ['string', 'max:500'],
        ]);

        $result = $this->capitalDecisionRecordCall(
            fn () => $workflow->create(
                $user,
                $business,
                [
                    'decisionOwnerMembershipId' => $data['decision_owner_membership_id'],
                    'effectiveDate' => $data['effective_date'],
                    'reviewDate' => $data['review_date'],
                    'decisionSummary' => $data['decision_summary'],
                    'evidenceReferences' => $data['evidence_references'] ?? [],
                ],
            ),
        );

        abort_if($result === null, 404);

        return redirect('/formation?step=capital')->with(
            'status',
            $result['created']
                ? 'Capital Decision Record saved.'
                : 'Capital Decision Record already exists for this approved version.',
        );
    }

    public function saveCapitalScenario(
        Request $request,
        string $kind,
        CapitalPlanning $capital,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $money = ['required', 'regex:/\A\d{1,16}(?:\.\d{1,2})?\z/'];

        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'name' => ['required', 'string', 'max:160'],
            'pre_opening_costs' => $money,
            'initial_assets_inventory' => $money,
            'working_capital' => $money,
            'contingency_reserve' => $money,
            'available_funding' => $money,
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $result = $this->validatedCall(
            fn () => $capital->saveScenario(
                $user,
                $business,
                $kind,
                (int) $data['expected_revision'],
                $data['name'],
                new CapitalRequirement(
                    $data['pre_opening_costs'],
                    $data['initial_assets_inventory'],
                    $data['working_capital'],
                    $data['contingency_reserve'],
                    $data['available_funding'],
                ),
                $data['notes'] ?? null,
            ),
        );

        abort_if($result === null, 404);

        return back();
    }

    public function promoteCapitalScenario(
        Request $request,
        string $kind,
        CapitalPlanning $capital,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $result = $this->validatedCall(
            fn () => $capital->promoteScenario(
                $user,
                $business,
                $kind,
            ),
        );

        abort_if($result === null, 404);

        return back();
    }

    public function advanceCapitalContentReview(
        Request $request,
        string $promotion,
        CapitalPlanning $capital,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'target' => [
                'required',
                Rule::in(['under_review', 'approved']),
            ],
        ]);

        $ok = $this->validatedCall(
            fn () => $capital->advanceContentReview(
                $user,
                $business,
                $promotion,
                FormalRecordState::from($data['target']),
            ),
        );

        abort_unless($ok === true, 404);

        return back();
    }

    public function exportCapitalScenario(
        Request $request,
        string $kind,
        CapitalPlanning $capital,
    ): HttpResponse {
        [$user, $business] = $this->context($request);

        $scenario = $this->validatedCall(
            fn () => $capital->scenarioForExport(
                $user,
                $business,
                $kind,
            ),
        );

        abort_if($scenario === null, 404);

        $handle = fopen('php://temp', 'w+');

        if ($handle === false) {
            abort(500);
        }

        fputcsv($handle, array_keys($scenario));
        fputcsv(
            $handle,
            array_map(
                static fn (mixed $value): string => $value === null ? '' : (string) $value,
                array_values($scenario),
            ),
        );

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            abort(500);
        }

        return Response::make(
            $csv,
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => sprintf(
                    'attachment; filename="capital-%s-scenario.csv"',
                    $kind,
                ),
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    private function appendNamedMoney(
        Request $request,
        ExistingBusinessBaseline $baseline,
        string $type,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $amountKey = $type === 'asset'
            ? 'estimated_value'
            : 'outstanding_amount';

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            $amountKey => [
                'required',
                'regex:/\A\d{1,16}(?:\.\d{1,2})?\z/',
            ],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $id = $type === 'asset'
            ? $baseline->addAsset($user, $business, $data)
            : $baseline->addLiability($user, $business, $data);

        abort_if($id === null, 404);

        return back();
    }

    /**
     * @return array{User, Business}
     */
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

    private function validatedCall(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (StaleRevision|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'formation' => $exception->getMessage(),
            ]);
        }
    }

    private function capitalApprovalCall(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (RuntimeException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'capital_approval' => $exception->getMessage(),
            ]);
        }
    }

    private function capitalDecisionRecordCall(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (RuntimeException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'capital_decision_record' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function businessModelFoundationKeys(): array
    {
        return [
            'business_purpose',
            'market',
            'location',
            'competition_alternatives',
            'operating_model',
            'excluded_activities',
            'pricing_notes',
            'unit_name',
            'average_selling_price',
            'variable_cost_per_unit',
            'monthly_fixed_cost',
            'expected_monthly_units',
            'scalability_strategy',
            'scalability_constraints',
            'first_12_month_plan',
        ];
    }

    /**
     * @return list<string>
     */
    private function bmcKeys(): array
    {
        return [
            'customer_segments',
            'value_propositions',
            'channels',
            'customer_relationships',
            'revenue_streams',
            'key_resources',
            'key_activities',
            'key_partnerships',
            'cost_structure',
        ];
    }
}
