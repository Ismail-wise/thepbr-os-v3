<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Partnership;

use App\Application\Partnership\ContributionWorkflow;
use App\Application\Partnership\OwnershipGovernanceWorkflow;
use App\Application\Partnership\OwnershipWorkflow;
use App\Domain\Partnership\Enums\ContributionType;
use App\Domain\Partnership\ValueObjects\ContributionValue;
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
use InvalidArgumentException;
use RuntimeException;

final class PartnershipWorkflowController
{
    public function createContribution(
        Request $request,
        ContributionWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $types = array_map(
            static fn (ContributionType $type): string => $type->value,
            ContributionType::cases(),
        );

        $data = $request->validate([
            'partner_id' => ['required', 'uuid'],
            'contribution_type' => ['required', Rule::in($types)],
            'currency' => ['required', 'regex:/\A[A-Z]{3}\z/'],
            'description' => ['required', 'string', 'max:300'],
            'proposed_value' => [
                'required',
                'regex:/\A\d+(?:\.\d{1,2})?\z/',
            ],
            'conditions' => ['nullable', 'string', 'max:4000'],
            'committed_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],

            'amount_committed' => [
                'required_if:contribution_type,cash',
                'nullable',
                'regex:/\A\d+(?:\.\d{1,2})?\z/',
            ],
            'amount_received' => [
                'nullable',
                'regex:/\A\d+(?:\.\d{1,2})?\z/',
            ],
            'payment_date' => ['nullable', 'date'],

            'role_work' => [
                'required_if:contribution_type,time_skill',
                'nullable',
                'string',
                'max:300',
            ],
            'hours_per_month' => [
                'required_if:contribution_type,time_skill',
                'nullable',
                'numeric',
                'gt:0',
            ],
            'fair_market_rate' => [
                'required_if:contribution_type,time_skill',
                'nullable',
                'regex:/\A\d+(?:\.\d{1,2})?\z/',
            ],
            'number_of_months' => [
                'required_if:contribution_type,time_skill',
                'nullable',
                'integer',
                'min:1',
            ],
            'cash_compensation_received' => [
                'nullable',
                'regex:/\A\d+(?:\.\d{1,2})?\z/',
            ],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'performance_condition' => [
                'nullable',
                'string',
                'max:4000',
            ],
            'vesting_rule' => ['nullable', 'string', 'max:4000'],

            'asset_description' => [
                'required_if:contribution_type,property_asset',
                'nullable',
                'string',
                'max:300',
            ],
            'ownership_transferred' => [
                'required_if:contribution_type,property_asset',
                'nullable',
                'boolean',
            ],
            'usage_period' => ['nullable', 'string', 'max:160'],
            'market_value' => [
                'nullable',
                'regex:/\A\d+(?:\.\d{1,2})?\z/',
            ],
            'fair_rental_use_value' => [
                'nullable',
                'regex:/\A\d+(?:\.\d{1,2})?\z/',
            ],
            'asset_valuation_method' => [
                'nullable',
                'string',
                'max:200',
            ],

            'intangible_kind' => [
                'required_if:contribution_type,ip_intangible',
                'nullable',
                'string',
                'max:200',
            ],
            'intangible_description' => [
                'required_if:contribution_type,ip_intangible',
                'nullable',
                'string',
                'max:4000',
            ],
            'legal_beneficial_owner' => [
                'required_if:contribution_type,ip_intangible',
                'nullable',
                'string',
                'max:300',
            ],
            'contribution_form' => [
                'required_if:contribution_type,ip_intangible',
                'nullable',
                'string',
                'max:200',
            ],
            'contribution_period' => [
                'nullable',
                'string',
                'max:200',
            ],
            'intangible_valuation_method' => [
                'required_if:contribution_type,ip_intangible',
                'nullable',
                'string',
                'max:200',
            ],
        ]);

        $type = ContributionType::from(
            $data['contribution_type'],
        );

        $details = match ($type) {
            ContributionType::Cash => [
                'amount_committed' => (string) $data['amount_committed'],
                'amount_received' => isset($data['amount_received'])
                        ? (string) $data['amount_received']
                        : null,
                'payment_date' => $data['payment_date'] ?? null,
            ],
            ContributionType::TimeSkill => [
                'role_work' => $data['role_work'],
                'hours_per_month' => (string) $data['hours_per_month'],
                'fair_market_rate' => (string) $data['fair_market_rate'],
                'number_of_months' => (int) $data['number_of_months'],
                'cash_compensation_received' => isset($data['cash_compensation_received'])
                        ? (string) $data['cash_compensation_received']
                        : null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'performance_condition' => $data['performance_condition'] ?? null,
                'vesting_rule' => $data['vesting_rule'] ?? null,
            ],
            ContributionType::PropertyAsset => [
                'asset_description' => $data['asset_description'],
                'ownership_transferred' => (bool) $data['ownership_transferred'],
                'usage_period' => $data['usage_period'] ?? null,
                'market_value' => isset($data['market_value'])
                        ? (string) $data['market_value']
                        : null,
                'fair_rental_use_value' => isset($data['fair_rental_use_value'])
                        ? (string) $data['fair_rental_use_value']
                        : null,
                'valuation_method' => $data['asset_valuation_method'] ?? null,
            ],
            ContributionType::IpIntangible => [
                'intangible_kind' => $data['intangible_kind'],
                'intangible_description' => $data['intangible_description'],
                'legal_beneficial_owner' => $data['legal_beneficial_owner'],
                'contribution_form' => $data['contribution_form'],
                'contribution_period' => $data['contribution_period'] ?? null,
                'valuation_method' => $data['intangible_valuation_method'],
            ],
        };

        $result = $this->validatedCall(
            fn () => $workflow->create(
                $user,
                $business,
                $data['partner_id'],
                $type,
                $data['currency'],
                $data['description'],
                new ContributionValue(
                    (string) $data['proposed_value'],
                ),
                $data['conditions'] ?? null,
                $data['committed_date'] ?? null,
                $data['due_date'] ?? null,
                $details,
            ),
            'contribution',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function reviewContribution(
        Request $request,
        string $contribution,
        ContributionWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'expected_revision' => [
                'required',
                'integer',
                'min:1',
            ],
            'reviewed_value' => [
                'required',
                'regex:/\A\d+(?:\.\d{1,2})?\z/',
            ],
            'valuation_method' => [
                'required',
                'string',
                'max:200',
            ],
            'note' => ['nullable', 'string', 'max:4000'],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->review(
                $user,
                $business,
                $contribution,
                (int) $data['expected_revision'],
                new ContributionValue(
                    (string) $data['reviewed_value'],
                ),
                $data['valuation_method'],
                $data['note'] ?? null,
            ),
            'contribution',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function submitContributionGovernance(
        Request $request,
        string $contribution,
        ContributionWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'phase' => [
                'required',
                Rule::in(['approval', 'acceptance']),
            ],
            'proposed_accepted_value' => [
                'required_if:phase,acceptance',
                'nullable',
                'regex:/\A\d+(?:\.\d{1,2})?\z/',
            ],
        ]);

        $accepted = isset($data['proposed_accepted_value'])
            && $data['proposed_accepted_value'] !== null
            ? new ContributionValue(
                (string) $data['proposed_accepted_value'],
            )
            : null;

        $result = $this->validatedCall(
            fn () => $workflow->submitGovernance(
                $user,
                $business,
                $contribution,
                $data['phase'],
                $accepted,
            ),
            'contribution_governance',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function advanceContributionReview(
        Request $request,
        string $submission,
        ContributionWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'target' => [
                'required',
                Rule::in(['under_review', 'approved']),
            ],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->advanceContentReview(
                $user,
                $business,
                $submission,
                FormalRecordState::from($data['target']),
            ),
            'contribution_governance',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function syncContributionDecision(
        Request $request,
        string $submission,
        ContributionWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $result = $this->validatedCall(
            fn () => $workflow->syncGovernanceDecision(
                $user,
                $business,
                $submission,
            ),
            'contribution_governance',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function recordDelivery(
        Request $request,
        string $contribution,
        ContributionWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'expected_revision' => [
                'required',
                'integer',
                'min:1',
            ],
            'delivered_value' => [
                'required',
                'regex:/\A\d+(?:\.\d{1,2})?\z/',
            ],
            'delivered_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'mark_delivered' => ['required', 'boolean'],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->recordDelivery(
                $user,
                $business,
                $contribution,
                (int) $data['expected_revision'],
                new ContributionValue(
                    (string) $data['delivered_value'],
                ),
                CarbonImmutable::parse($data['delivered_at']),
                $data['notes'] ?? null,
                (bool) $data['mark_delivered'],
            ),
            'delivery',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function createOwnershipScenario(
        Request $request,
        OwnershipWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'currency' => ['required', 'regex:/\A[A-Z]{3}\z/'],
            'share_value_minor_units' => [
                'required',
                'integer',
                'min:1',
            ],
            'authorized_shares' => [
                'required',
                'regex:/\A\d+(?:\.\d{1,8})?\z/',
            ],
            'reserved_unissued_shares' => [
                'required',
                'regex:/\A\d+(?:\.\d{1,8})?\z/',
            ],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->createFromAcceptedContributions(
                $user,
                $business,
                $data['name'],
                $data['currency'],
                (int) $data['share_value_minor_units'],
                $data['authorized_shares'],
                $data['reserved_unissued_shares'],
            ),
            'ownership',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function setShareClassRights(
        Request $request,
        string $scenario,
        string $shareClass,
        OwnershipWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'voting_right_per_share' => [
                'required',
                'regex:/\A\d+(?:\.\d{1,8})?\z/',
            ],
            'profit_right_per_share' => [
                'required',
                'regex:/\A\d+(?:\.\d{1,8})?\z/',
            ],
            'transfer_allowed' => ['required', 'boolean'],
            'restrictions' => ['nullable', 'string', 'max:4000'],
            'special_rights' => ['nullable', 'string', 'max:4000'],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->setShareClassRights(
                $user,
                $business,
                $scenario,
                $shareClass,
                $data['voting_right_per_share'],
                $data['profit_right_per_share'],
                (bool) $data['transfer_allowed'],
                $data['restrictions'] ?? null,
                $data['special_rights'] ?? null,
            ),
            'ownership',
        );

        abort_if($result === false, 404);

        return back();
    }

    public function setVesting(
        Request $request,
        string $scenario,
        string $position,
        OwnershipWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'vested_shares' => [
                'required',
                'regex:/\A\d+(?:\.\d{1,8})?\z/',
            ],
            'start_date' => ['nullable', 'date'],
            'period_months' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'cliff_months' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'conditions' => ['nullable', 'string', 'max:4000'],
            'early_exit_treatment' => [
                'nullable',
                'string',
                'max:4000',
            ],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->setVesting(
                $user,
                $business,
                $scenario,
                $position,
                $data['vested_shares'],
                $data['start_date'] ?? null,
                isset($data['period_months'])
                    ? (int) $data['period_months']
                    : null,
                isset($data['cliff_months'])
                    ? (int) $data['cliff_months']
                    : null,
                $data['conditions'] ?? null,
                $data['early_exit_treatment'] ?? null,
            ),
            'ownership',
        );

        abort_if($result === false, 404);

        return back();
    }

    public function freezeOwnershipScenario(
        Request $request,
        string $scenario,
        OwnershipWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'expected_revision' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->freeze(
                $user,
                $business,
                $scenario,
                (int) $data['expected_revision'],
            ),
            'ownership',
        );

        abort_if($result === false, 404);

        return back();
    }

    public function submitOwnershipGovernance(
        Request $request,
        string $scenario,
        OwnershipGovernanceWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'effective_until' => [
                'nullable',
                'date',
                'after:effective_from',
            ],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->submitGovernance(
                $user,
                $business,
                $scenario,
                CarbonImmutable::parse($data['effective_from']),
                isset($data['effective_until'])
                    && $data['effective_until'] !== null
                    ? CarbonImmutable::parse(
                        $data['effective_until'],
                    )
                    : null,
            ),
            'ownership_governance',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function advanceOwnershipReview(
        Request $request,
        string $submission,
        OwnershipGovernanceWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'target' => [
                'required',
                Rule::in(['under_review', 'approved']),
            ],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->advanceContentReview(
                $user,
                $business,
                $submission,
                FormalRecordState::from($data['target']),
            ),
            'ownership_governance',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function effectOwnership(
        Request $request,
        string $submission,
        OwnershipGovernanceWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $result = $this->validatedCall(
            fn () => $workflow->effectApprovedGovernance(
                $user,
                $business,
                $submission,
            ),
            'ownership_governance',
        );

        abort_if($result === null, 404);

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

        if (
            ! $user instanceof User
            || ! $business instanceof Business
        ) {
            abort(403);
        }

        return [$user, $business];
    }

    private function validatedCall(
        callable $callback,
        string $field,
    ): mixed {
        try {
            return $callback();
        } catch (StaleRevision|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                $field => $exception->getMessage(),
            ]);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                $field => $exception->getMessage(),
            ]);
        }
    }
}
