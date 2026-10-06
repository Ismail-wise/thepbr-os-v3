<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Partnership;

use App\Application\Partnership\ContributionActionPlanWorkflow;
use App\Application\Partnership\ContributionDecisionRecordWorkflow;
use App\Application\Partnership\ContributionSetupWorkflow;
use App\Application\Partnership\ContributionWorkflow;
use App\Domain\Governance\Enums\ActionStatus;
use App\Domain\Partnership\Enums\ContributionStatus;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class ContributionChapterController
{
    public function saveSetup(
        Request $request,
        ContributionSetupWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] =
            $this->context($request);

        $data = $request->validate([
            'expected_revision' => [
                'required',
                'integer',
                'min:0',
            ],
            'valuation_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'currency' => [
                'required',
                'regex:/\A[A-Z]{3}\z/',
            ],
            'period_start' => [
                'required',
                'date_format:Y-m-d',
            ],
            'period_end' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:period_start',
            ],
            'valuation_owner_membership_id' => [
                'required',
                'uuid',
            ],
            'approver_membership_ids' => [
                'required',
                'array',
                'min:1',
            ],
            'approver_membership_ids.*' => [
                'required',
                'uuid',
                'distinct',
            ],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->save(
                $user,
                $business,
                (int)
                    $data[
                        'expected_revision'
                    ],
                [
                    'valuationDate' => $data[
                            'valuation_date'
                        ],
                    'currency' => $data['currency'],
                    'periodStart' => $data[
                            'period_start'
                        ],
                    'periodEnd' => $data[
                            'period_end'
                        ],
                    'valuationOwnerMembershipId' => $data[
                            'valuation_owner_membership_id'
                        ],
                    'approverMembershipIds' => $data[
                            'approver_membership_ids'
                        ],
                ],
            ),
            'contribution_setup',
        );

        abort_if($result === null, 404);

        return back()->with(
            'status',
            $result['created']
                ? 'Contribution Setup saved.'
                : 'Contribution Setup updated.',
        );
    }

    public function transitionTerminal(
        Request $request,
        string $contribution,
        ContributionWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] =
            $this->context($request);

        $data = $request->validate([
            'expected_revision' => [
                'required',
                'integer',
                'min:1',
            ],
            'target' => [
                'required',
                Rule::in([
                    'rejected',
                    'cancelled',
                    'defaulted',
                ]),
            ],
            'reason' => [
                'required',
                'string',
                'max:4000',
            ],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow
                ->transitionTerminal(
                    $user,
                    $business,
                    $contribution,
                    (int)
                        $data[
                            'expected_revision'
                        ],
                    ContributionStatus::from(
                        $data['target'],
                    ),
                    $data['reason'],
                ),
            'contribution',
        );

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Contribution status updated.',
        );
    }

    public function createDecisionRecord(
        Request $request,
        ContributionDecisionRecordWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] =
            $this->context($request);

        $data = $request->validate([
            'decision_owner_membership_id' => [
                'required',
                'uuid',
            ],
            'effective_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'review_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:effective_date',
            ],
            'decision_summary' => [
                'required',
                'string',
                'max:4000',
            ],
            'evidence_references' => [
                'nullable',
                'array',
                'max:20',
            ],
            'evidence_references.*' => [
                'string',
                'max:500',
            ],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow->create(
                $user,
                $business,
                [
                    'decisionOwnerMembershipId' => $data[
                            'decision_owner_membership_id'
                        ],
                    'effectiveDate' => $data[
                            'effective_date'
                        ],
                    'reviewDate' => $data[
                            'review_date'
                        ],
                    'decisionSummary' => $data[
                            'decision_summary'
                        ],
                    'evidenceReferences' => $data[
                            'evidence_references'
                        ] ?? [],
                ],
            ),
            'contribution_decision',
        );

        abort_if($result === null, 404);

        return back()->with(
            'status',
            $result['created']
                ? 'Contribution Decision Record saved.'
                : 'Contribution Decision Record already exists for this Accepted Register.',
        );
    }

    public function createSuggestedAction(
        Request $request,
        ContributionActionPlanWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] =
            $this->context($request);

        $data = $request->validate([
            'suggestion_key' => [
                'required',
                'string',
                'max:120',
            ],
            'assigned_membership_id' => [
                'required',
                'uuid',
            ],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow
                ->createSuggested(
                    $user,
                    $business,
                    $data[
                        'suggestion_key'
                    ],
                    $data[
                        'assigned_membership_id'
                    ],
                ),
            'contribution_action',
        );

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Contribution Action added.',
        );
    }

    public function createCustomAction(
        Request $request,
        ContributionActionPlanWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] =
            $this->context($request);

        $data = $request->validate([
            'assigned_membership_id' => [
                'required',
                'uuid',
            ],
            'title' => [
                'required',
                'string',
                'max:240',
            ],
            'description' => [
                'nullable',
                'string',
                'max:4000',
            ],
            'due_date' => [
                'nullable',
                'date_format:Y-m-d',
            ],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow
                ->createCustom(
                    $user,
                    $business,
                    $data[
                        'assigned_membership_id'
                    ],
                    $data['title'],
                    $data[
                        'description'
                    ] ?? null,
                    $data[
                        'due_date'
                    ] ?? null,
                ),
            'contribution_action',
        );

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Contribution Action added.',
        );
    }

    public function updateActionStatus(
        Request $request,
        string $action,
        ContributionActionPlanWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] =
            $this->context($request);

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in(
                    array_map(
                        static fn (
                            ActionStatus $status,
                        ): string => $status->value,
                        ActionStatus::cases(),
                    ),
                ),
            ],
            'blocked_reason' => [
                'nullable',
                'string',
                'max:4000',
            ],
        ]);

        $result = $this->validatedCall(
            fn () => $workflow
                ->updateStatus(
                    $user,
                    $business,
                    $action,
                    ActionStatus::from(
                        $data['status'],
                    ),
                    $data[
                        'blocked_reason'
                    ] ?? null,
                ),
            'contribution_action',
        );

        abort_if($result === null, 404);

        return back()->with(
            'status',
            'Contribution Action updated.',
        );
    }

    /**
     * @return array{User,Business}
     */
    private function context(
        Request $request,
    ): array {
        $user = $request->user();
        $business =
            $request->attributes->get(
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
        } catch (
            StaleRevision
            |InvalidArgumentException
            |RuntimeException $exception
        ) {
            throw ValidationException::withMessages([
                $field => $exception->getMessage(),
            ]);
        }
    }
}
