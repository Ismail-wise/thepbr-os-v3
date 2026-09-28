<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Closure;

use App\Application\Closure\ClosureWorkflow;
use App\Application\Closure\GetClosureWorkspace;
use App\Domain\Closure\Enums\ClosureCaseStatus;
use App\Domain\Closure\Enums\ClosureClaimStatus;
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

final class ClosureWorkspaceController
{
    public function index(
        Request $request,
        GetClosureWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);
        $payload = $workspace->execute($user, $business);

        abort_if($payload === null, 404);

        return Inertia::render('Changes/Closure', [
            'closureWorkspace' => $payload,
        ]);
    }

    public function createCase(
        Request $request,
        ClosureWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'trigger' => ['required', 'string', 'max:96'],
            'jurisdiction_reference' => ['required', 'string', 'max:10000'],
            'governance_decision_type' => ['required', 'string', 'max:160'],
            'trigger_detail' => ['nullable', 'string', 'max:10000'],
            'legal_entity_reference' => ['nullable', 'string', 'max:10000'],
            'intended_legal_closure_at' => ['nullable', 'date'],
        ]);

        $created = $this->validated(
            fn () => $workflow->createCase(
                $user,
                $business,
                $data['trigger'],
                $data['jurisdiction_reference'],
                $data['governance_decision_type'],
                $data['trigger_detail'] ?? null,
                $data['legal_entity_reference'] ?? null,
                isset($data['intended_legal_closure_at'])
                    ? CarbonImmutable::parse($data['intended_legal_closure_at'])
                    : null,
            ),
            'closure',
        );

        abort_if($created === null, 404);

        return back();
    }

    public function recordRequirement(
        Request $request,
        string $case,
        ClosureWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'requirement_type' => ['required', 'string', 'max:48'],
            'requirement_key' => ['required', 'string', 'max:96'],
            'status' => [
                'required',
                Rule::in(['pending', 'met', 'blocked', 'not_applicable']),
            ],
            'detail' => ['nullable', 'string', 'max:10000'],
            'source_type' => ['nullable', 'string', 'max:80'],
            'source_id' => ['nullable', 'uuid'],
            'external_source_reference' => [
                'nullable',
                'string',
                'max:10000',
            ],
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
                $data['external_source_reference'] ?? null,
            ),
            'requirement',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function createClaim(
        Request $request,
        string $case,
        ClosureWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'claim_reference' => ['required', 'string', 'max:80'],
            'claim_type' => ['required', 'string', 'max:80'],
            'claimant_reference' => ['required', 'string', 'max:240'],
            'required' => ['required', 'boolean'],
            'amount_minor_units' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'regex:/\A[A-Z]{3}\z/'],
            'description' => ['nullable', 'string', 'max:10000'],
            'legal_priority_reference' => ['nullable', 'string', 'max:10000'],
            'source_type' => ['nullable', 'string', 'max:80'],
            'source_id' => ['nullable', 'uuid'],
            'external_source_reference' => [
                'nullable',
                'string',
                'max:10000',
            ],
        ]);

        $claimId = $this->validated(
            fn () => $workflow->createClaim(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                $data['claim_reference'],
                $data['claim_type'],
                $data['claimant_reference'],
                (bool) $data['required'],
                isset($data['amount_minor_units'])
                    ? (int) $data['amount_minor_units']
                    : null,
                $data['currency'] ?? null,
                $data['description'] ?? null,
                $data['legal_priority_reference'] ?? null,
                $data['source_type'] ?? null,
                $data['source_id'] ?? null,
                $data['external_source_reference'] ?? null,
            ),
            'claim',
        );

        abort_if($claimId === null, 404);

        return back();
    }

    public function transitionClaim(
        Request $request,
        string $case,
        string $claim,
        ClosureWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'expected_claim_revision' => ['required', 'integer', 'min:1'],
            'target' => ['required', Rule::enum(ClosureClaimStatus::class)],
            'note' => ['nullable', 'string', 'max:10000'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->transitionClaim(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                $claim,
                (int) $data['expected_claim_revision'],
                ClosureClaimStatus::from($data['target']),
                $data['note'] ?? null,
            ),
            'claim',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function linkFinancePayment(
        Request $request,
        string $case,
        ClosureWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'finance_payment_id' => ['required', 'uuid'],
            'purpose' => [
                'required',
                Rule::in([
                    'claim_settlement',
                    'tax',
                    'residual_distribution',
                    'other',
                ]),
            ],
            'claim_id' => ['nullable', 'uuid'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->linkFinancePayment(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                $data['finance_payment_id'],
                $data['purpose'],
                $data['claim_id'] ?? null,
            ),
            'finance',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function submitGovernance(
        Request $request,
        string $case,
        ClosureWorkflow $workflow,
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
        ClosureWorkflow $workflow,
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
        ClosureWorkflow $workflow,
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

    public function activateWindDown(
        Request $request,
        string $case,
        ClosureWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $this->validated(
            fn () => $workflow->activateWindDown(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
            ),
            'closure',
        );

        abort_if($updated === null, 404);

        return back();
    }

    public function prepareResidual(
        Request $request,
        string $case,
        ClosureWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $this->validated(
            fn () => $workflow->prepareResidualDistribution(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
            ),
            'residual',
        );

        abort_if($updated === null, 404);

        return back();
    }

    public function recordResidual(
        Request $request,
        string $case,
        ClosureWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'status' => [
                'required',
                Rule::in(['not_applicable', 'planned', 'completed']),
            ],
            'amount_minor_units' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'regex:/\A[A-Z]{3}\z/'],
            'basis' => ['nullable', 'string', 'max:10000'],
        ]);

        $updated = $this->validated(
            fn () => $workflow->recordResidualDistribution(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                $data['status'],
                isset($data['amount_minor_units'])
                    ? (int) $data['amount_minor_units']
                    : null,
                $data['currency'] ?? null,
                $data['basis'] ?? null,
            ),
            'residual',
        );

        abort_if($updated === null, 404);

        return back();
    }

    public function prepareLegalClosure(
        Request $request,
        string $case,
        ClosureWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $this->validated(
            fn () => $workflow->prepareLegalClosure(
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

    public function effectLegalClosure(
        Request $request,
        string $case,
        ClosureWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $this->validated(
            fn () => $workflow->effectLegalClosure(
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

    public function closeWorkspace(
        Request $request,
        string $case,
        ClosureWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'confirmation' => ['required', 'string', 'max:255'],
        ]);

        $updated = $this->validated(
            fn () => $workflow->closeWorkspace(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                $data['confirmation'],
            ),
            'closure',
        );

        abort_if($updated === null, 404);

        return back();
    }

    public function transitionCase(
        Request $request,
        string $case,
        ClosureWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'target' => ['required', Rule::enum(ClosureCaseStatus::class)],
        ]);

        $updated = $this->validated(
            fn () => $workflow->transition(
                $user,
                $business,
                $case,
                (int) $data['expected_revision'],
                ClosureCaseStatus::from($data['target']),
            ),
            'closure',
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
