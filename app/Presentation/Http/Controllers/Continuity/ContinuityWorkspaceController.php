<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Continuity;

use App\Application\Continuity\ContinuityPlanWorkflow;
use App\Application\Continuity\ContinuityTestWorkflow;
use App\Application\Continuity\CreateRiskContinuityAction;
use App\Application\Continuity\EmergencyAccessWorkflow;
use App\Application\Continuity\GetContinuityWorkspace;
use App\Domain\Continuity\Enums\ContinuityTestResult;
use App\Domain\Continuity\Enums\EmergencyAccessActivationStatus;
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

final class ContinuityWorkspaceController
{
    public function index(
        Request $request,
        GetContinuityWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);
        $payload = $workspace->execute($user, $business);

        abort_if($payload === null, 404);

        return Inertia::render('Continuity/Index', ['continuity' => $payload]);
    }

    public function createPlan(
        Request $request,
        ContinuityPlanWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'review_due_at' => ['nullable', 'date'],
            'continuity_owner_membership_id' => ['required', 'uuid'],
            'governance_decision_type' => ['required', 'string', 'max:96'],
            'review_frequency' => ['required', 'string', 'max:80'],
            'test_frequency' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'critical_functions' => ['required', 'array', 'min:1', 'max:100'],
            'emergency_access' => ['present', 'array', 'max:100'],
            'interim_authority_plans' => ['present', 'array', 'max:100'],
            'successors' => ['present', 'array', 'max:100'],
            'communication_steps' => ['present', 'array', 'max:100'],
            'recovery_actions' => ['present', 'array', 'max:100'],
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
            'continuity',
        );

        abort_if($created === null, 404);

        return back();
    }

    public function submitPlan(
        Request $request,
        string $formalRecordVersion,
        ContinuityPlanWorkflow $workflow,
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
            'continuity',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function reviewPlan(
        Request $request,
        string $formalRecordVersion,
        ContinuityPlanWorkflow $workflow,
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
            'continuity',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function syncPlanDecision(
        Request $request,
        string $formalRecordVersion,
        ContinuityPlanWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        abort_unless($workflow->syncApprovedDecision(
            $user,
            $business,
            $formalRecordVersion,
        ), 404);

        return back();
    }

    public function createTest(
        Request $request,
        ContinuityTestWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'scenario_name' => ['required', 'string', 'max:200'],
            'scenario' => ['required', 'string', 'max:10000'],
            'owner_membership_id' => ['required', 'uuid'],
            'next_test_date' => ['nullable', 'date'],
        ]);

        $id = $this->validated(
            fn () => $workflow->create($user, $business, $data),
            'test',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function recordTest(
        Request $request,
        string $test,
        ContinuityTestWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'result' => ['required', Rule::enum(ContinuityTestResult::class)],
            'failed_items' => ['nullable', 'string', 'max:10000'],
            'improvement_actions' => ['nullable', 'string', 'max:10000'],
        ]);

        $ok = $this->validated(
            fn () => $workflow->recordResult(
                $user,
                $business,
                $test,
                ContinuityTestResult::from($data['result']),
                $data['failed_items'] ?? null,
                $data['improvement_actions'] ?? null,
            ),
            'test',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function requestEmergencyAccess(
        Request $request,
        EmergencyAccessWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'emergency_access_record_id' => ['required', 'uuid'],
            'trigger' => ['required', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after:starts_at'],
            'required_decision_type' => ['nullable', 'string', 'max:96'],
            'emergency_authority_grant_id' => ['nullable', 'uuid'],
        ]);

        $id = $this->validated(
            fn () => $workflow->request(
                $user,
                $business,
                $data['emergency_access_record_id'],
                $data['trigger'],
                $data['reason'],
                CarbonImmutable::parse($data['starts_at']),
                CarbonImmutable::parse($data['expires_at']),
                $data['required_decision_type'] ?? null,
                $data['emergency_authority_grant_id'] ?? null,
            ),
            'emergency_access',
        );

        abort_if($id === null, 404);

        return back();
    }

    public function activateEmergencyAccess(
        Request $request,
        string $activation,
        EmergencyAccessWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        abort_unless($workflow->activate(
            $user,
            $business,
            $activation,
            (int) $data['expected_revision'],
        ), 404);

        return back();
    }

    public function endEmergencyAccess(
        Request $request,
        string $activation,
        EmergencyAccessWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'target' => ['required', Rule::in([
                EmergencyAccessActivationStatus::Expired->value,
                EmergencyAccessActivationStatus::Revoked->value,
                EmergencyAccessActivationStatus::Closed->value,
            ])],
        ]);

        abort_unless($workflow->end(
            $user,
            $business,
            $activation,
            (int) $data['expected_revision'],
            EmergencyAccessActivationStatus::from($data['target']),
        ), 404);

        return back();
    }

    public function createAction(
        Request $request,
        CreateRiskContinuityAction $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'source_type' => ['required', Rule::in([
                'continuity_critical_function',
                'continuity_test',
                'emergency_access_activation',
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
