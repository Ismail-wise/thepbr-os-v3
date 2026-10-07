<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Partnership;

use App\Application\Partnership\OwnershipActionPlanWorkflow;
use App\Application\Partnership\OwnershipDecisionRecordWorkflow;
use App\Application\Partnership\OwnershipWorkflow;
use App\Domain\Governance\Enums\ActionStatus;
use App\Domain\Partnership\ValueObjects\ContributionValue;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class OwnershipChapterController
{
    public function createScenario(
        Request $request,
        OwnershipWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:240'],
            'share_value' => ['required', 'regex:/\A\d+(?:\.\d{1,2})?\z/'],
            'authorized_shares' => ['required', 'regex:/\A\d+(?:\.\d{1,8})?\z/'],
            'reserved_unissued_shares' => ['required', 'regex:/\A\d+(?:\.\d{1,8})?\z/'],
        ]);

        $shareValue = new ContributionValue((string) $data['share_value']);
        if ($shareValue->minorUnits() <= 0) {
            throw ValidationException::withMessages([
                'share_value' => 'Share Value must be greater than zero.',
            ]);
        }

        $result = $this->validatedCall(
            fn () => $workflow->createFromAcceptedContributions(
                $user,
                $business,
                (string) $data['name'],
                (string) $business->base_currency,
                $shareValue->minorUnits(),
                (string) $data['authorized_shares'],
                (string) $data['reserved_unissued_shares'],
            ),
            'ownership',
        );

        abort_if($result === null, 404);

        return back()->with('status', 'Ownership planning scenario created from Accepted Contributions.');
    }

    public function saveShareRights(
        Request $request,
        string $scenario,
        string $shareClass,
        OwnershipWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'share_class_name' => ['required', 'string', 'max:120'],
            'voting_right_per_share' => ['required', 'regex:/\A\d+(?:\.\d{1,8})?\z/'],
            'profit_right_per_share' => ['required', 'regex:/\A\d+(?:\.\d{1,8})?\z/'],
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
                (string) $data['voting_right_per_share'],
                (string) $data['profit_right_per_share'],
                (bool) $data['transfer_allowed'],
                $data['restrictions'] ?? null,
                $data['special_rights'] ?? null,
                (string) $data['share_class_name'],
            ),
            'share_class',
        );

        abort_if(! $result, 404);

        return back()->with('status', 'Share Class and rights reviewed.');
    }

    public function saveVesting(
        Request $request,
        string $scenario,
        string $position,
        OwnershipWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'vesting_applies' => ['required', 'boolean'],
            'vested_shares' => ['nullable', 'regex:/\A\d+(?:\.\d{1,8})?\z/'],
            'vesting_start_date' => ['nullable', 'date_format:Y-m-d'],
            'vesting_period_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'vesting_cliff_months' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'vesting_conditions' => ['nullable', 'string', 'max:4000'],
            'early_exit_treatment' => ['nullable', 'string', 'max:4000'],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->setVestingDecision(
                $user,
                $business,
                $scenario,
                $position,
                (bool) $data['vesting_applies'],
                $data['vested_shares'] ?? null,
                $data['vesting_start_date'] ?? null,
                isset($data['vesting_period_months'])
                    ? (int) $data['vesting_period_months']
                    : null,
                isset($data['vesting_cliff_months'])
                    ? (int) $data['vesting_cliff_months']
                    : null,
                $data['vesting_conditions'] ?? null,
                $data['early_exit_treatment'] ?? null,
            ),
            'vesting',
        );

        abort_if(! $result, 404);

        return back()->with('status', 'Vesting decision saved.');
    }

    public function saveCapacity(
        Request $request,
        string $scenario,
        OwnershipWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'authorized_shares' => ['required', 'regex:/\A\d+(?:\.\d{1,8})?\z/'],
            'reserved_unissued_shares' => ['required', 'regex:/\A\d+(?:\.\d{1,8})?\z/'],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->setCapacity(
                $user,
                $business,
                $scenario,
                (string) $data['authorized_shares'],
                (string) $data['reserved_unissued_shares'],
            ),
            'capacity',
        );

        abort_if(! $result, 404);

        return back()->with('status', 'Share capacity reviewed.');
    }

    public function saveIssuanceRule(
        Request $request,
        string $scenario,
        OwnershipWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'approval_rule' => ['required', 'string', 'max:500'],
            'approval_threshold_percent' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'preemption_right' => ['required', 'boolean'],
            'valuation_method' => ['required', 'string', 'max:500'],
            'dilution_acknowledged' => ['required', 'boolean'],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->setIssuanceRule(
                $user,
                $business,
                $scenario,
                (string) $data['approval_rule'],
                (string) $data['approval_threshold_percent'],
                (bool) $data['preemption_right'],
                (string) $data['valuation_method'],
                (bool) $data['dilution_acknowledged'],
            ),
            'issuance_rule',
        );

        abort_if(! $result, 404);

        return back()->with('status', 'New Share Issuance Rule saved.');
    }

    public function createDecisionRecord(
        Request $request,
        OwnershipDecisionRecordWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'decision_owner_membership_id' => ['required', 'uuid'],
            'review_date' => ['required', 'date_format:Y-m-d'],
            'decision_summary' => ['required', 'string', 'max:4000'],
            'evidence_references' => ['nullable', 'array', 'max:20'],
            'evidence_references.*' => ['string', 'max:500'],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->create(
                $user,
                $business,
                [
                    'decisionOwnerMembershipId' => $data['decision_owner_membership_id'],
                    'reviewDate' => $data['review_date'],
                    'decisionSummary' => $data['decision_summary'],
                    'evidenceReferences' => $data['evidence_references'] ?? [],
                ],
            ),
            'ownership_decision',
        );

        abort_if($result === null, 404);

        return back()->with(
            'status',
            $result['created']
                ? 'Ownership Decision Record saved.'
                : 'Ownership Decision Record already exists for this Share Register.',
        );
    }

    public function createSuggestedAction(
        Request $request,
        OwnershipActionPlanWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'suggestion_key' => ['required', 'string', 'max:120'],
            'assigned_membership_id' => ['required', 'uuid'],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->createSuggested(
                $user,
                $business,
                (string) $data['suggestion_key'],
                (string) $data['assigned_membership_id'],
            ),
            'ownership_action',
        );

        abort_if($result === null, 404);

        return back()->with('status', 'Ownership Action added.');
    }

    public function createCustomAction(
        Request $request,
        OwnershipActionPlanWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'assigned_membership_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:240'],
            'description' => ['nullable', 'string', 'max:4000'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->createCustom(
                $user,
                $business,
                (string) $data['assigned_membership_id'],
                (string) $data['title'],
                $data['description'] ?? null,
                $data['due_date'] ?? null,
            ),
            'ownership_action',
        );

        abort_if($result === null, 404);

        return back()->with('status', 'Ownership Action added.');
    }

    public function updateActionStatus(
        Request $request,
        string $action,
        OwnershipActionPlanWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in(array_map(
                    static fn (ActionStatus $status): string => $status->value,
                    ActionStatus::cases(),
                )),
            ],
            'blocked_reason' => ['nullable', 'string', 'max:4000'],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->updateStatus(
                $user,
                $business,
                $action,
                ActionStatus::from((string) $data['status']),
                $data['blocked_reason'] ?? null,
            ),
            'ownership_action',
        );

        abort_if($result === null, 404);

        return back()->with('status', 'Ownership Action updated.');
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

    private function validatedCall(callable $callback, string $field): mixed
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
