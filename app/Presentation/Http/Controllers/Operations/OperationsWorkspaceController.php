<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Operations;

use App\Application\Governance\UpdateGovernanceActionStatus;
use App\Application\Operations\CreateOperationsAction;
use App\Application\Operations\GetOperationsWorkspace;
use App\Application\Operations\OperationsRegisterWorkflow;
use App\Domain\Governance\Enums\ActionStatus;
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

final class OperationsWorkspaceController
{
    public function index(
        Request $request,
        GetOperationsWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);

        $payload = $workspace->execute($user, $business);

        abort_if($payload === null, 404);

        return Inertia::render('Operations/Index', [
            'operations' => $payload,
        ]);
    }

    public function createDraft(
        Request $request,
        OperationsRegisterWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'review_due_at' => ['nullable', 'date'],
            'organization_name' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'roles' => ['required', 'array', 'min:1', 'max:100'],
            'roles.*.role_key' => ['required', 'string', 'max:96'],
            'roles.*.name' => ['required', 'string', 'max:160'],
            'roles.*.function_name' => ['required', 'string', 'max:160'],
            'roles.*.purpose' => ['required', 'string', 'max:2000'],
            'roles.*.responsibilities' => ['required', 'string', 'max:5000'],
            'roles.*.operational_authority' => ['nullable', 'string', 'max:3000'],
            'roles.*.reports_to_role_key' => ['nullable', 'string', 'max:96'],
            'roles.*.report_type' => ['nullable', 'string', 'max:120'],
            'roles.*.reporting_frequency' => ['nullable', 'string', 'max:80'],
            'roles.*.meeting_frequency' => ['nullable', 'string', 'max:80'],
            'roles.*.review_frequency' => ['required', 'string', 'max:80'],
            'roles.*.assignments' => ['required', 'array', 'min:1', 'max:2'],
            'roles.*.assignments.*.membership_id' => ['required', 'uuid'],
            'roles.*.assignments.*.assignment_type' => [
                'required',
                Rule::in(['primary', 'backup']),
            ],
            'raci' => ['present', 'array', 'max:200'],
            'raci.*.activity' => ['required', 'string', 'max:200'],
            'raci.*.result' => ['nullable', 'string', 'max:200'],
            'raci.*.assignments' => ['required', 'array', 'min:1', 'max:100'],
            'raci.*.assignments.*.role_key' => ['required', 'string', 'max:96'],
            'raci.*.assignments.*.responsibility' => [
                'required',
                Rule::in(['A', 'R', 'AR', 'C', 'I']),
            ],
            'kpis' => ['present', 'array', 'max:300'],
            'kpis.*.role_key' => ['required', 'string', 'max:96'],
            'kpis.*.name' => ['required', 'string', 'max:160'],
            'kpis.*.target' => ['required', 'string', 'max:200'],
            'kpis.*.measurement_method' => ['required', 'string', 'max:240'],
            'kpis.*.frequency' => ['required', 'string', 'max:80'],
            'kpis.*.current_status' => [
                'required',
                Rule::in([
                    'not_started',
                    'on_track',
                    'at_risk',
                    'off_track',
                    'achieved',
                ]),
            ],
        ]);

        $effectiveFrom = CarbonImmutable::parse($data['effective_from']);
        $reviewDueAt = isset($data['review_due_at'])
            ? CarbonImmutable::parse($data['review_due_at'])->endOfDay()
            : null;

        unset($data['effective_from'], $data['review_due_at']);

        try {
            $created = $workflow->createDraft(
                $user,
                $business,
                $data,
                $effectiveFrom,
                $reviewDueAt,
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'operations' => $exception->getMessage(),
            ]);
        }

        abort_if($created === null, 404);

        return back();
    }

    public function submit(
        Request $request,
        string $formalRecordVersion,
        OperationsRegisterWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $submitted = $workflow->submitForGovernance(
                $user,
                $business,
                $formalRecordVersion,
                (int) $data['expected_revision'],
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'operations' => $exception->getMessage(),
            ]);
        }

        abort_if($submitted === null, 404);

        return back();
    }

    public function contentReview(
        Request $request,
        string $formalRecordVersion,
        OperationsRegisterWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'target' => ['required', Rule::in([
                FormalRecordState::UnderReview->value,
                FormalRecordState::Approved->value,
                FormalRecordState::ChangesRequested->value,
            ])],
        ]);

        try {
            $ok = $workflow->advanceContentReview(
                $user,
                $business,
                $formalRecordVersion,
                FormalRecordState::from($data['target']),
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'operations' => $exception->getMessage(),
            ]);
        }

        abort_unless($ok, 404);

        return back();
    }

    public function createAction(
        Request $request,
        CreateOperationsAction $createAction,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'operations_role_id' => ['required', 'uuid'],
            'assigned_membership_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
        ]);

        try {
            $action = $createAction->execute(
                $user,
                $business,
                $data['operations_role_id'],
                $data['assigned_membership_id'],
                $data['title'],
                $data['description'] ?? null,
                isset($data['due_at'])
                    ? CarbonImmutable::parse($data['due_at'])
                    : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'action' => $exception->getMessage(),
            ]);
        }

        abort_if($action === null, 404);

        return back();
    }

    public function updateAction(
        Request $request,
        string $action,
        UpdateGovernanceActionStatus $updateAction,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'status' => ['required', Rule::enum(ActionStatus::class)],
            'blocked_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $updated = $updateAction->execute(
                $user,
                $business,
                $action,
                ActionStatus::from($data['status']),
                $data['blocked_reason'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'action' => $exception->getMessage(),
            ]);
        }

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
}
