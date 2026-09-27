<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Rewards;

use App\Application\Rewards\DistributionRunWorkflow;
use App\Application\Rewards\GetRewardsWorkspace;
use App\Application\Rewards\RewardPaymentWorkflow;
use App\Application\Rewards\RewardPolicyWorkflow;
use App\Application\Rewards\SimulateDistribution;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Rewards\Enums\RewardPaymentType;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

final class RewardsWorkspaceController
{
    public function index(
        Request $request,
        GetRewardsWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);
        $payload = $workspace->execute($user, $business);

        abort_if($payload === null, 404);

        return Inertia::render('Rewards/Index', ['rewards' => $payload]);
    }

    public function createPolicy(
        Request $request,
        RewardPolicyWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'review_due_at' => ['nullable', 'date'],
            'reward_owner_membership_id' => ['required', 'uuid'],
            'currency' => ['required', 'string', 'size:3'],
            'payment_frequency' => ['required', 'string', 'max:80'],
            'minimum_reserve_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'target_cash_buffer_minor_units' => ['required', 'integer', 'min:0'],
            'reinvestment_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'minimum_cash_after_distribution_minor_units' => ['required', 'integer', 'min:0'],
            'distribution_governance_decision_type' => ['required', 'string', 'max:120'],
            'manual_adjustments_allowed' => ['required', 'boolean'],
            'role_compensation_rules' => ['present', 'array', 'max:100'],
            'reimbursement_rules' => ['present', 'array', 'max:100'],
            'bonus_rules' => ['present', 'array', 'max:100'],
            'loan_repayment_rules' => ['present', 'array', 'max:100'],
            'distribution_rule' => ['required', 'array'],
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
            'rewards',
        );

        abort_if($created === null, 404);

        return back();
    }

    public function submitPolicy(
        Request $request,
        string $formalRecordVersion,
        RewardPolicyWorkflow $workflow,
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
            'rewards',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function reviewPolicy(
        Request $request,
        string $formalRecordVersion,
        RewardPolicyWorkflow $workflow,
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
            'rewards',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function createRewardPayment(
        Request $request,
        RewardPaymentWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'type' => ['required', Rule::in([
                'salary_service_fee',
                'reimbursement',
                'bonus',
                'loan_repayment',
            ])],
            'rule_id' => ['required', 'uuid'],
            'bank_account_reference_id' => ['required', 'uuid'],
            'partner_id' => ['nullable', 'uuid'],
            'amount_minor_units' => ['nullable', 'integer', 'min:1'],
            'expense_date' => [
                'nullable',
                'date',
                Rule::requiredIf(
                    fn (): bool => $request->input('type') === 'reimbursement',
                ),
            ],
        ]);

        $id = $this->validated(
            fn () => $workflow->create(
                $user,
                $business,
                RewardPaymentType::from($data['type']),
                $data['rule_id'],
                $data,
            ),
            'reward_payment',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function simulateDistribution(
        Request $request,
        SimulateDistribution $simulation,
    ): JsonResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'approved_net_profit_minor_units' => ['required', 'integer'],
            'tax_due_minor_units' => ['required', 'integer', 'min:0'],
            'debt_due_minor_units' => ['required', 'integer', 'min:0'],
            'required_reserve_minor_units' => ['required', 'integer', 'min:0'],
            'reinvestment_minor_units' => ['required', 'integer', 'min:0'],
            'adjustments_minor_units' => ['required', 'integer'],
            'weights' => ['present', 'array', 'max:500'],
        ]);

        $result = $this->validated(
            fn () => $simulation->execute($user, $business, $data),
            'distribution',
        );

        abort_if($result === null, 404);

        return response()->json($result);
    }

    public function createDistribution(
        Request $request,
        DistributionRunWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'reconciliation_review_id' => ['required', 'uuid'],
            'record_date' => ['required', 'date'],
            'required_reserve_minor_units' => ['nullable', 'integer', 'min:0'],
            'reinvestment_minor_units' => ['nullable', 'integer', 'min:0'],
            'adjustments_minor_units' => ['nullable', 'integer'],
            'special_weights' => ['present', 'array', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $id = $this->validated(
            fn () => $workflow->createDraft($user, $business, $data),
            'distribution',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function attachDistributionEvidence(
        Request $request,
        string $distribution,
        DistributionRunWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate(['evidence_id' => ['required', 'uuid']]);

        $ok = $this->validated(
            fn () => $workflow->attachEvidence(
                $user,
                $business,
                $distribution,
                $data['evidence_id'],
            ),
            'distribution',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function adjustDistributionLine(
        Request $request,
        string $distribution,
        string $line,
        DistributionRunWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'adjustment_minor_units' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->adjustLine(
                $user,
                $business,
                $distribution,
                $line,
                (int) $data['expected_revision'],
                (int) $data['adjustment_minor_units'],
                $data['reason'],
            ),
            'distribution',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function verifyDistribution(
        Request $request,
        string $distribution,
        DistributionRunWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->validated(
            fn () => $workflow->financeVerifyAndSubmit(
                $user,
                $business,
                $distribution,
                (int) $data['expected_revision'],
            ),
            'distribution',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function syncDistributionDecision(
        Request $request,
        string $distribution,
        DistributionRunWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $ok = $this->validated(
            fn () => $workflow->syncGovernanceApproval(
                $user,
                $business,
                $distribution,
            ),
            'distribution',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function scheduleDistributionPayments(
        Request $request,
        string $distribution,
        DistributionRunWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'bank_account_reference_id' => ['required', 'uuid'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->schedulePayments(
                $user,
                $business,
                $distribution,
                $data['bank_account_reference_id'],
            ),
            'distribution',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function completeDistribution(
        Request $request,
        string $distribution,
        DistributionRunWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $ok = $this->validated(
            fn () => $workflow->complete(
                $user,
                $business,
                $distribution,
            ),
            'distribution',
        );

        abort_unless($ok, 404);

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
