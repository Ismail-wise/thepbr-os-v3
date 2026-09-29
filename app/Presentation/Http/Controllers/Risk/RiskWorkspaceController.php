<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Risk;

use App\Application\Continuity\CreateRiskContinuityAction;
use App\Application\Risk\GetRiskWorkspace;
use App\Application\Risk\RiskControlTestWorkflow;
use App\Application\Risk\RiskIncidentWorkflow;
use App\Application\Risk\RiskRegisterWorkflow;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Risk\Enums\IncidentStatus;
use App\Domain\Risk\Enums\RiskControlTestResult;
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

final class RiskWorkspaceController
{
    public function index(
        Request $request,
        GetRiskWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);
        $payload = $workspace->execute($user, $business);

        abort_if($payload === null, 404);

        return Inertia::render('Risk/Index', ['risk' => $payload]);
    }

    public function createRegister(
        Request $request,
        RiskRegisterWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'review_due_at' => ['nullable', 'date'],
            'risk_owner_membership_id' => ['required', 'uuid'],
            'low_max_score' => ['required', 'integer', 'min:1', 'max:24'],
            'medium_max_score' => ['required', 'integer', 'min:2', 'max:24'],
            'high_max_score' => ['required', 'integer', 'min:3', 'max:24'],
            'review_frequency' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'risks' => ['required', 'array', 'min:1', 'max:100'],
            'protections' => ['present', 'array', 'max:100'],
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
            'risk',
        );

        abort_if($created === null, 404);

        return back();
    }

    public function submitRegister(
        Request $request,
        string $formalRecordVersion,
        RiskRegisterWorkflow $workflow,
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
            'risk',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function reviewRegister(
        Request $request,
        string $formalRecordVersion,
        RiskRegisterWorkflow $workflow,
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
            'risk',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function syncRegisterDecision(
        Request $request,
        string $formalRecordVersion,
        RiskRegisterWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $ok = $this->validated(
            fn () => $workflow->syncApprovedDecision(
                $user,
                $business,
                $formalRecordVersion,
            ),
            'risk',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function openIncident(
        Request $request,
        RiskIncidentWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'risk_item_id' => ['nullable', 'uuid'],
            'incident_at' => ['required', 'date'],
            'incident_type' => ['required', 'string', 'max:80'],
            'description' => ['required', 'string', 'max:10000'],
            'business_impact' => ['required', 'string', 'max:10000'],
            'immediate_action' => ['required', 'string', 'max:10000'],
            'loss_amount_minor_units' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'confidentiality' => ['required', Rule::in(['standard', 'restricted'])],
        ]);

        $id = $this->validated(
            fn () => $workflow->open($user, $business, $data),
            'incident',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function transitionIncident(
        Request $request,
        string $incident,
        RiskIncidentWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'target' => ['required', Rule::enum(IncidentStatus::class)],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'root_cause' => ['nullable', 'string', 'max:10000'],
            'corrective_action' => ['nullable', 'string', 'max:10000'],
            'note' => ['nullable', 'string', 'max:10000'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->transition(
                $user,
                $business,
                $incident,
                IncidentStatus::from($data['target']),
                (int) $data['expected_revision'],
                $data['root_cause'] ?? null,
                $data['corrective_action'] ?? null,
                $data['note'] ?? null,
            ),
            'incident',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function createControlTest(
        Request $request,
        RiskControlTestWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'risk_item_id' => ['nullable', 'uuid'],
            'risk_protection_record_id' => ['nullable', 'uuid'],
            'control_name' => ['required', 'string', 'max:200'],
            'scenario' => ['required', 'string', 'max:10000'],
            'owner_membership_id' => ['required', 'uuid'],
            'next_test_date' => ['nullable', 'date'],
            'confidentiality' => ['required', Rule::in(['standard', 'restricted'])],
        ]);

        $id = $this->validated(
            fn () => $workflow->create($user, $business, $data),
            'test',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function recordControlTest(
        Request $request,
        string $test,
        RiskControlTestWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'result' => ['required', Rule::enum(RiskControlTestResult::class)],
            'gap_found' => ['nullable', 'string', 'max:10000'],
            'corrective_action' => ['nullable', 'string', 'max:10000'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->recordResult(
                $user,
                $business,
                $test,
                RiskControlTestResult::from($data['result']),
                $data['gap_found'] ?? null,
                $data['corrective_action'] ?? null,
            ),
            'test',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function createAction(
        Request $request,
        CreateRiskContinuityAction $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'source_type' => ['required', Rule::in([
                'risk_item',
                'risk_incident',
                'risk_control_test',
            ])],
            'source_id' => ['required', 'uuid'],
            'operations_role_id' => ['required', 'uuid'],
            'assigned_membership_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'due_at' => ['nullable', 'date'],
        ]);

        $action = $this->validated(
            fn () => $workflow->execute(
                $user,
                $business,
                $data['source_type'],
                $data['source_id'],
                $data['operations_role_id'],
                $data['assigned_membership_id'],
                $data['title'],
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
