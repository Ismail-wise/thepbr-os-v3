<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Exit;

use App\Application\Exit\ExitCaseWorkflow;
use App\Application\Exit\GetExitWorkspace;
use App\Domain\Exit\Enums\ExitCaseStatus;
use App\Domain\Exit\Enums\ExitTrigger;
use App\Domain\Exit\Enums\LeaverClassification;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\Exceptions\StaleRevision;
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

final class ExitWorkspaceController
{
    public function index(
        Request $request,
        GetExitWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);
        $payload = $workspace->execute($user, $business);

        abort_if($payload === null, 404);

        return Inertia::render('Changes/Exit', [
            'exitWorkspace' => $payload,
        ]);
    }

    public function createCase(
        Request $request,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'partner_id' => ['required', 'uuid'],
            'trigger' => ['required', Rule::enum(ExitTrigger::class)],
            'governance_decision_type' => [
                'required',
                'string',
                'max:160',
            ],
            'trigger_detail' => ['nullable', 'string', 'max:10000'],
            'effective_from' => ['nullable', 'date'],
        ]);

        $created = $this->validated(
            fn () => $workflow->createCase(
                $user,
                $business,
                $data['partner_id'],
                ExitTrigger::from($data['trigger']),
                $data['governance_decision_type'],
                $data['trigger_detail'] ?? null,
                isset($data['effective_from'])
                    ? CarbonImmutable::parse($data['effective_from'])
                    : null,
            ),
            'exit',
        );

        abort_if($created === null, 404);

        return back();
    }

    public function recordNotice(
        Request $request,
        string $case,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'notice_date' => ['required', 'date'],
            'intended_exit_date' => ['nullable', 'date'],
            'required_notice_days' => ['nullable', 'integer', 'min:0'],
            'notice_summary' => ['required', 'string', 'max:10000'],
        ]);

        $updated = $this->validated(
            fn () => $workflow->recordNotice(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                CarbonImmutable::parse($data['notice_date']),
                isset($data['intended_exit_date'])
                    ? CarbonImmutable::parse($data['intended_exit_date'])
                    : null,
                isset($data['required_notice_days'])
                    ? (int) $data['required_notice_days']
                    : null,
                $data['notice_summary'],
            ),
            'notice',
        );

        abort_if($updated === null, 404);

        return back();
    }

    public function recordShareTreatment(
        Request $request,
        string $case,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'share_treatment' => [
                'required',
                Rule::in([
                    'remaining_partners_buy',
                    'company_buyback',
                    'third_party_sale',
                    'partial_buyout',
                    'permitted_person_transfer',
                    'cancellation',
                    'retain_per_agreement',
                    'no_shares',
                    'other',
                ]),
            ],
            'leaver_classification' => [
                'required',
                Rule::enum(LeaverClassification::class),
            ],
            'buyer_partner_id' => ['nullable', 'uuid'],
            'partner_change_case_id' => ['nullable', 'uuid'],
            'ownership_scenario_id' => ['nullable', 'uuid'],
            'valuation_method' => ['nullable', 'string', 'max:4000'],
            'approved_business_value_minor_units' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'leaver_adjustment_minor_units' => ['nullable', 'integer'],
            'final_buyout_value_minor_units' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'currency' => ['nullable', 'regex:/\A[A-Z]{3}\z/'],
            'leaver_rule_reference' => [
                'nullable',
                'string',
                'max:10000',
            ],
        ]);

        $updated = $this->validated(
            fn () => $workflow->recordShareTreatment(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                $data['share_treatment'],
                LeaverClassification::from($data['leaver_classification']),
                $data['buyer_partner_id'] ?? null,
                $data['partner_change_case_id'] ?? null,
                $data['ownership_scenario_id'] ?? null,
                $data['valuation_method'] ?? null,
                isset($data['approved_business_value_minor_units'])
                    ? (int) $data['approved_business_value_minor_units']
                    : null,
                isset($data['leaver_adjustment_minor_units'])
                    ? (int) $data['leaver_adjustment_minor_units']
                    : null,
                isset($data['final_buyout_value_minor_units'])
                    ? (int) $data['final_buyout_value_minor_units']
                    : null,
                $data['currency'] ?? null,
                $data['leaver_rule_reference'] ?? null,
            ),
            'share_treatment',
        );

        abort_if($updated === null, 404);

        return back();
    }

    public function recordPaymentTerms(
        Request $request,
        string $case,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'payment_total_minor_units' => ['nullable', 'integer', 'min:0'],
            'payment_terms_summary' => ['nullable', 'string', 'max:10000'],
            'deposit_minor_units' => ['nullable', 'integer', 'min:0'],
            'installment_minor_units' => ['nullable', 'integer', 'min:0'],
            'installment_count' => ['nullable', 'integer', 'min:1'],
            'payment_frequency' => ['nullable', 'string', 'max:40'],
            'first_payment_date' => ['nullable', 'date'],
            'final_payment_date' => ['nullable', 'date'],
            'interest_terms' => ['nullable', 'string', 'max:10000'],
            'security_terms' => ['nullable', 'string', 'max:10000'],
            'late_payment_rule' => ['nullable', 'string', 'max:10000'],
            'affordability_status' => [
                'required',
                Rule::in([
                    'pending',
                    'affordable',
                    'not_affordable',
                    'alternative_approved',
                    'not_applicable',
                ]),
            ],
            'alternative_payment_structure' => [
                'nullable',
                'string',
                'max:10000',
            ],
        ]);

        $updated = $this->validated(
            fn () => $workflow->recordPaymentTerms(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                isset($data['payment_total_minor_units'])
                    ? (int) $data['payment_total_minor_units']
                    : null,
                $data['payment_terms_summary'] ?? null,
                isset($data['deposit_minor_units'])
                    ? (int) $data['deposit_minor_units']
                    : null,
                isset($data['installment_minor_units'])
                    ? (int) $data['installment_minor_units']
                    : null,
                isset($data['installment_count'])
                    ? (int) $data['installment_count']
                    : null,
                $data['payment_frequency'] ?? null,
                isset($data['first_payment_date'])
                    ? CarbonImmutable::parse($data['first_payment_date'])
                    : null,
                isset($data['final_payment_date'])
                    ? CarbonImmutable::parse($data['final_payment_date'])
                    : null,
                $data['interest_terms'] ?? null,
                $data['security_terms'] ?? null,
                $data['late_payment_rule'] ?? null,
                $data['affordability_status'],
                $data['alternative_payment_structure'] ?? null,
            ),
            'payment_terms',
        );

        abort_if($updated === null, 404);

        return back();
    }

    public function transitionCase(
        Request $request,
        string $case,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'target' => ['required', Rule::enum(ExitCaseStatus::class)],
        ]);

        $updated = $this->validated(
            fn () => $workflow->transition(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                ExitCaseStatus::from($data['target']),
            ),
            'exit',
        );

        abort_if($updated === null, 404);

        return back();
    }

    public function recordRequirement(
        Request $request,
        string $case,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'requirement_type' => [
                'required',
                Rule::in([
                    'ownership',
                    'finance',
                    'handover',
                    'access',
                    'operations',
                    'continuity',
                    'post_exit',
                    'legal',
                    'governance',
                    'conflict',
                    'other',
                ]),
            ],
            'requirement_key' => ['required', 'string', 'max:96'],
            'status' => [
                'required',
                Rule::in(['pending', 'met', 'blocked', 'not_applicable']),
            ],
            'detail' => ['nullable', 'string', 'max:10000'],
            'source_type' => ['nullable', 'string', 'max:80'],
            'source_id' => ['nullable', 'uuid'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->recordRequirement(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                $data['requirement_type'],
                $data['requirement_key'],
                $data['status'],
                $data['detail'] ?? null,
                $data['source_type'] ?? null,
                $data['source_id'] ?? null,
            ),
            'requirement',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function linkFinancePayment(
        Request $request,
        string $case,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'finance_payment_id' => ['required', 'uuid'],
            'purpose' => [
                'required',
                Rule::in([
                    'deposit',
                    'installment',
                    'final_settlement',
                    'loan_settlement',
                    'other',
                ]),
            ],
            'installment_sequence' => ['nullable', 'integer', 'min:1'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->linkFinancePayment(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                $data['finance_payment_id'],
                $data['purpose'],
                isset($data['installment_sequence'])
                    ? (int) $data['installment_sequence']
                    : null,
            ),
            'finance',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function submitGovernance(
        Request $request,
        string $case,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->validated(
            fn () => $workflow->submitGovernance(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
            ),
            'governance',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function review(
        Request $request,
        string $case,
        string $formalRecordVersion,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'target' => [
                'required',
                Rule::in([
                    FormalRecordState::UnderReview->value,
                    FormalRecordState::Approved->value,
                    FormalRecordState::ChangesRequested->value,
                ]),
            ],
        ]);

        $ok = $this->validated(
            fn () => $workflow->advanceContentReview(
                $user,
                $business,
                $case,
                $formalRecordVersion,
                FormalRecordState::from($data['target']),
            ),
            'governance',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function syncDecision(
        Request $request,
        string $case,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $this->validated(
            fn () => $workflow->syncDecision(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
            ),
            'governance',
        );

        abort_if($updated === null, 404);

        return back();
    }

    public function prepareEffect(
        Request $request,
        string $case,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $this->validated(
            fn () => $workflow->prepareForEffect(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
            ),
            'effectivity',
        );

        abort_if($updated === null, 404);

        return back();
    }

    public function effect(
        Request $request,
        string $case,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $this->validated(
            fn () => $workflow->effect(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
            ),
            'effectivity',
        );

        abort_if($updated === null, 404);

        return back();
    }

    public function transitionAccess(
        Request $request,
        string $case,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'expected_access' => [
                'required',
                Rule::enum(MembershipAccessStatus::class),
            ],
            'target_access' => [
                'required',
                Rule::enum(MembershipAccessStatus::class),
            ],
        ]);

        $ok = $this->validated(
            fn () => $workflow->transitionMembershipAccess(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                MembershipAccessStatus::from($data['expected_access']),
                MembershipAccessStatus::from($data['target_access']),
            ),
            'access',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function refreshSettlement(
        Request $request,
        string $case,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $this->validated(
            fn () => $workflow->refreshSettlement(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
            ),
            'settlement',
        );

        abort_if($updated === null, 404);

        return back();
    }

    public function complete(
        Request $request,
        string $case,
        ExitCaseWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $this->validated(
            fn () => $workflow->complete(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
            ),
            'completion',
        );

        abort_if($updated === null, 404);

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

    private function validated(
        callable $callback,
        string $field,
    ): mixed {
        try {
            return $callback();
        } catch (
            InvalidArgumentException
            |StaleRevision
            |RuntimeException $exception
        ) {
            throw ValidationException::withMessages([
                $field => $exception->getMessage(),
            ]);
        }
    }
}
