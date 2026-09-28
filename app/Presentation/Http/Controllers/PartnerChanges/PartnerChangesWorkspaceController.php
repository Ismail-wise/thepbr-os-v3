<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\PartnerChanges;

use App\Application\PartnerChanges\GetPartnerChangesWorkspace;
use App\Application\PartnerChanges\PartnerChangeWorkflow;
use App\Domain\PartnerChanges\Enums\PartnerChangeEligibilityStatus;
use App\Domain\PartnerChanges\Enums\PartnerChangeStatus;
use App\Domain\PartnerChanges\Enums\PartnerChangeTransactionType;
use App\Domain\PartnerChanges\Enums\RofrResponseStatus;
use App\Domain\Partnership\ValueObjects\ShareQuantity;
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

final class PartnerChangesWorkspaceController
{
    public function index(
        Request $request,
        GetPartnerChangesWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);
        $payload = $workspace->execute($user, $business);

        abort_if($payload === null, 404);

        return Inertia::render('Changes/PartnerChanges', [
            'partnerChanges' => $payload,
        ]);
    }

    public function createCase(
        Request $request,
        PartnerChangeWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'transaction_type' => [
                'required',
                Rule::enum(PartnerChangeTransactionType::class),
            ],
            'buyer_partner_id' => ['required', 'uuid'],
            'seller_partner_id' => ['nullable', 'uuid'],
            'source_share_class_id' => ['nullable', 'uuid'],
            'shares' => [
                'nullable',
                'regex:/\A\d+(?:\.\d{1,8})?\z/',
            ],
            'currency' => ['nullable', 'regex:/\A[A-Z]{3}\z/'],
            'consideration_minor_units' => ['nullable', 'integer', 'min:0'],
            'valuation_method' => ['nullable', 'string', 'max:4000'],
            'rights_impact_summary' => ['nullable', 'string', 'max:10000'],
            'governance_decision_type' => [
                'required', 'string', 'max:160',
            ],
            'rofr_required' => ['required', 'boolean'],
            'effective_from' => ['nullable', 'date'],
        ]);

        $created = $this->validated(
            fn () => $workflow->createCase(
                $user,
                $business,
                PartnerChangeTransactionType::from(
                    $data['transaction_type'],
                ),
                $data['buyer_partner_id'],
                $data['seller_partner_id'] ?? null,
                $data['source_share_class_id'] ?? null,
                isset($data['shares']) && $data['shares'] !== null
                    ? new ShareQuantity((string) $data['shares'])
                    : null,
                $data['currency'] ?? null,
                isset($data['consideration_minor_units'])
                    ? (int) $data['consideration_minor_units']
                    : null,
                $data['valuation_method'] ?? null,
                $data['rights_impact_summary'] ?? null,
                $data['governance_decision_type'],
                (bool) $data['rofr_required'],
                isset($data['effective_from'])
                    ? CarbonImmutable::parse($data['effective_from'])
                    : null,
            ),
            'partner_change',
        );

        abort_if($created === null, 404);

        return back();
    }

    public function transitionCase(
        Request $request,
        string $case,
        PartnerChangeWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'target' => ['required', Rule::enum(PartnerChangeStatus::class)],
        ]);

        $updated = $this->validated(
            fn () => $workflow->transition(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                PartnerChangeStatus::from($data['target']),
            ),
            'partner_change',
        );

        abort_if($updated === null, 404);

        return back();
    }

    public function recordEligibility(
        Request $request,
        string $case,
        PartnerChangeWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'check_key' => ['required', 'string', 'max:80'],
            'result' => [
                'required',
                Rule::enum(PartnerChangeEligibilityStatus::class),
            ],
            'detail' => ['nullable', 'string', 'max:10000'],
            'source_type' => ['nullable', 'string', 'max:80'],
            'source_id' => ['nullable', 'uuid'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->recordEligibility(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                $data['check_key'],
                PartnerChangeEligibilityStatus::from($data['result']),
                $data['detail'] ?? null,
                $data['source_type'] ?? null,
                $data['source_id'] ?? null,
            ),
            'eligibility',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function recordRequirement(
        Request $request,
        string $case,
        PartnerChangeWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'requirement_type' => [
                'required',
                Rule::in([
                    'due_diligence',
                    'contribution',
                    'legal_document',
                    'onboarding',
                    'governance',
                    'ownership',
                    'other',
                ]),
            ],
            'requirement_key' => ['required', 'string', 'max:80'],
            'status' => [
                'required',
                Rule::enum(PartnerChangeEligibilityStatus::class),
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
                PartnerChangeEligibilityStatus::from($data['status']),
                $data['detail'] ?? null,
                $data['source_type'] ?? null,
                $data['source_id'] ?? null,
            ),
            'requirement',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function openRofr(
        Request $request,
        string $case,
        PartnerChangeWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'terms_summary' => ['required', 'string', 'max:10000'],
            'deadline_at' => ['required', 'date', 'after:now'],
        ]);

        $id = $this->validated(
            fn () => $workflow->openRofrRound(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                $data['terms_summary'],
                CarbonImmutable::parse($data['deadline_at']),
            ),
            'rofr',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function respondRofr(
        Request $request,
        string $case,
        string $round,
        PartnerChangeWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'eligible_partner_id' => ['required', 'uuid'],
            'response' => [
                'required',
                Rule::enum(RofrResponseStatus::class),
            ],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->recordRofrResponse(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                $round,
                $data['eligible_partner_id'],
                RofrResponseStatus::from($data['response']),
                $data['note'] ?? null,
            ),
            'rofr',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function completeRofr(
        Request $request,
        string $case,
        string $round,
        PartnerChangeWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'waived' => ['sometimes', 'boolean'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->completeRofrRound(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                $round,
                (bool) ($data['waived'] ?? false),
            ),
            'rofr',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function submitGovernance(
        Request $request,
        string $case,
        PartnerChangeWorkflow $workflow,
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
        PartnerChangeWorkflow $workflow,
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
        PartnerChangeWorkflow $workflow,
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
        PartnerChangeWorkflow $workflow,
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
        PartnerChangeWorkflow $workflow,
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
