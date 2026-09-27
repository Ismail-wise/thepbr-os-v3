<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Finance;

use App\Application\Finance\FinanceExceptionWorkflow;
use App\Application\Finance\FinancePaymentWorkflow;
use App\Application\Finance\FinancePolicyWorkflow;
use App\Application\Finance\FinanceReconciliationWorkflow;
use App\Application\Finance\GetFinanceWorkspace;
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

final class FinanceWorkspaceController
{
    public function index(
        Request $request,
        GetFinanceWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);
        $payload = $workspace->execute($user, $business);

        abort_if($payload === null, 404);

        return Inertia::render('Finance/Index', ['finance' => $payload]);
    }

    public function createPolicy(
        Request $request,
        FinancePolicyWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'review_due_at' => ['nullable', 'date'],
            'finance_owner_membership_id' => ['required', 'uuid'],
            'control_owner_membership_id' => ['required', 'uuid'],
            'bookkeeping_owner_membership_id' => ['required', 'uuid'],
            'accounting_method' => ['required', 'string', 'max:80'],
            'fiscal_period' => ['required', 'string', 'max:120'],
            'base_currency' => ['required', 'string', 'size:3'],
            'cash_handling_rules' => ['nullable', 'string', 'max:5000'],
            'monthly_closing_rules' => ['nullable', 'string', 'max:5000'],
            'tax_coordination_rules' => ['nullable', 'string', 'max:5000'],
            'audit_review_rules' => ['nullable', 'string', 'max:5000'],
            'bank_accounts' => ['required', 'array', 'min:1', 'max:20'],
            'bank_access' => ['required', 'array', 'min:1', 'max:100'],
            'payment_rules' => ['required', 'array', 'min:1', 'max:100'],
            'expense_procurement_rules' => ['present', 'array', 'max:100'],
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
            'finance',
        );

        abort_if($created === null, 404);

        return back();
    }

    public function submitPolicy(
        Request $request,
        string $formalRecordVersion,
        FinancePolicyWorkflow $workflow,
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
            'finance',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function reviewPolicy(
        Request $request,
        string $formalRecordVersion,
        FinancePolicyWorkflow $workflow,
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
            'finance',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function createReconciliation(
        Request $request,
        FinanceReconciliationWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'currency' => ['required', 'string', 'size:3'],
            'opening_cash_minor_units' => ['required', 'integer'],
            'inflows_minor_units' => ['required', 'integer'],
            'outflows_minor_units' => ['required', 'integer'],
            'closing_cash_minor_units' => ['required', 'integer'],
            'approved_net_profit_minor_units' => ['required', 'integer'],
            'tax_due_minor_units' => ['required', 'integer', 'min:0'],
            'debt_due_minor_units' => ['required', 'integer', 'min:0'],
            'cash_available_minor_units' => ['required', 'integer'],
            'unreconciled_items_count' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $id = $this->validated(
            fn () => $workflow->create($user, $business, $data),
            'reconciliation',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function completeReconciliation(
        Request $request,
        string $reconciliation,
        FinanceReconciliationWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->validated(
            fn () => $workflow->complete(
                $user,
                $business,
                $reconciliation,
                (int) $data['expected_revision'],
            ),
            'reconciliation',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function createPayment(
        Request $request,
        FinancePaymentWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'transaction_type' => ['required', 'string', 'max:96'],
            'amount_minor_units' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'bank_account_reference_id' => ['required', 'uuid'],
            'payee_reference' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'related_party' => ['required', 'boolean'],
        ]);

        $id = $this->validated(
            fn () => $workflow->createDraft($user, $business, $data),
            'payment',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function attachPaymentEvidence(
        Request $request,
        string $payment,
        FinancePaymentWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'evidence_id' => ['required', 'uuid'],
            'purpose' => ['required', Rule::in([
                'request_support',
                'payment_proof',
            ])],
        ]);

        $ok = $this->validated(
            fn () => $workflow->attachEvidence(
                $user,
                $business,
                $payment,
                $data['evidence_id'],
                $data['purpose'],
            ),
            'payment',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function verifyPayment(
        Request $request,
        string $payment,
        FinancePaymentWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->validated(
            fn () => $workflow->financeVerifyAndSubmit(
                $user,
                $business,
                $payment,
                (int) $data['expected_revision'],
            ),
            'payment',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function syncPaymentDecision(
        Request $request,
        string $payment,
        FinancePaymentWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $ok = $this->validated(
            fn () => $workflow->syncGovernanceAuthorization(
                $user,
                $business,
                $payment,
            ),
            'payment',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function recordPayment(
        Request $request,
        string $payment,
        FinancePaymentWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'payment_reference' => ['required', 'string', 'max:200'],
            'payment_evidence_id' => ['required', 'uuid'],
            'paid_at' => ['required', 'date'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->recordPayment(
                $user,
                $business,
                $payment,
                $data['payment_reference'],
                $data['payment_evidence_id'],
                CarbonImmutable::parse($data['paid_at']),
            ),
            'payment',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function completePayment(
        Request $request,
        string $payment,
        FinancePaymentWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'reconciliation_review_id' => ['required', 'uuid'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->completePayment(
                $user,
                $business,
                $payment,
                $data['reconciliation_review_id'],
            ),
            'payment',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function openException(
        Request $request,
        FinanceExceptionWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'exception_type' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'max:5000'],
            'finance_payment_id' => ['nullable', 'uuid'],
            'reconciliation_review_id' => ['nullable', 'uuid'],
            'requires_compensating_review' => ['required', 'boolean'],
            'severity' => ['required', Rule::in([
                'low', 'medium', 'high', 'critical',
            ])],
        ]);

        $id = $this->validated(
            fn () => $workflow->open(
                $user,
                $business,
                $data['exception_type'],
                $data['reason'],
                $data['finance_payment_id'] ?? null,
                $data['reconciliation_review_id'] ?? null,
                (bool) $data['requires_compensating_review'],
                $data['severity'],
            ),
            'exception',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function reviewException(
        Request $request,
        string $exception,
        FinanceExceptionWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'result' => ['required', Rule::in(['cleared', 'blocked'])],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->completeCompensatingReview(
                $user,
                $business,
                $exception,
                $data['result'],
                $data['note'] ?? null,
            ),
            'exception',
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
