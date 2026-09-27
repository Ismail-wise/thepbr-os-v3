<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Governance;

use App\Application\Governance\GetGovernanceRulesWorkspace;
use App\Application\Governance\GovernanceAuthorityChangeWorkflow;
use App\Application\Governance\GovernanceCharterWorkflow;
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
use Throwable;

final class GovernanceRulesController
{
    public function index(
        Request $request,
        GetGovernanceRulesWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);

        $payload = $workspace->execute($user, $business);

        abort_if($payload === null, 404);

        return Inertia::render('Governance/Rules', [
            'governanceRules' => $payload,
        ]);
    }

    public function createDraft(
        Request $request,
        GovernanceCharterWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'review_due_at' => ['nullable', 'date'],
            'governance_owner_membership_id' => ['required', 'uuid'],
            'voting_basis' => ['required', 'string', 'max:64'],
            'default_approval_rule' => ['required', 'string', 'max:160'],
            'meeting_frequency' => ['nullable', 'string', 'max:80'],
            'default_quorum_count' => ['required', 'integer', 'min:1', 'max:999'],
            'minutes_owner_membership_id' => ['required', 'uuid'],
            'conflict_of_interest_rule' => ['required', 'string', 'max:4000'],
            'deadlock_rule' => ['required', 'string', 'max:4000'],
            'remote_voting_allowed' => ['required', 'boolean'],
            'written_resolution_allowed' => ['required', 'boolean'],
            'rules' => ['required', 'array', 'min:1', 'max:100'],
            'rules.*.decision_type' => ['required', 'string', 'max:160'],
            'rules.*.category' => ['required', Rule::in([
                'daily_operating',
                'management',
                'major_business',
                'ownership_structural',
                'custom',
            ])],
            'rules.*.decision_method' => ['required', Rule::in([
                'approval',
                'vote',
                'approval_and_vote',
            ])],
            'rules.*.required_approvals' => ['required', 'integer', 'min:0', 'max:999'],
            'rules.*.required_votes' => ['required', 'integer', 'min:0', 'max:999'],
            'rules.*.quorum_count' => ['required', 'integer', 'min:1', 'max:999'],
            'rules.*.signature_required' => ['required', 'boolean'],
            'rules.*.reserved_matter' => ['required', 'boolean'],
            'rules.*.meeting_required' => ['required', 'boolean'],
            'rules.*.record_required' => ['required', 'boolean'],
            'rules.*.amount_min' => ['nullable', 'numeric', 'min:0'],
            'rules.*.amount_max' => ['nullable', 'numeric', 'min:0'],
            'rules.*.actors' => ['required', 'array', 'min:1', 'max:100'],
            'rules.*.actors.*.membership_id' => ['required', 'uuid'],
            'rules.*.actors.*.capacity' => ['required', 'string', 'max:120'],
            'rules.*.actors.*.is_decision_owner' => ['required', 'boolean'],
            'rules.*.actors.*.is_consulted' => ['required', 'boolean'],
            'rules.*.actors.*.can_approve' => ['required', 'boolean'],
            'rules.*.actors.*.can_vote' => ['required', 'boolean'],
            'rules.*.actors.*.can_sign' => ['required', 'boolean'],
        ]);

        $effectiveFrom = CarbonImmutable::parse($data['effective_from']);
        $reviewDueAt = isset($data['review_due_at'])
            ? CarbonImmutable::parse($data['review_due_at'])->endOfDay()
            : null;

        unset($data['effective_from'], $data['review_due_at']);

        $created = $this->validated(
            fn () => $workflow->createDraft(
                $user,
                $business,
                $data,
                $effectiveFrom,
                $reviewDueAt,
            ),
            'charter',
        );

        abort_if($created === null, 404);

        return back();
    }

    public function submit(
        Request $request,
        string $formalRecordVersion,
        GovernanceCharterWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        $submitted = $this->validated(
            fn () => $workflow->submitForGovernance(
                $user,
                $business,
                $formalRecordVersion,
                (int) $data['expected_revision'],
            ),
            'charter',
        );

        abort_if($submitted === null, 404);

        return back();
    }

    public function contentReview(
        Request $request,
        string $formalRecordVersion,
        GovernanceCharterWorkflow $workflow,
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
            'charter',
        );

        abort_unless($ok, 404);

        return back();
    }

    public function proposeDelegation(
        Request $request,
        GovernanceAuthorityChangeWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'delegator_membership_id' => ['required', 'uuid'],
            'delegate_membership_id' => ['required', 'uuid'],
            'decision_type' => ['required', 'string', 'max:160'],
            'scope' => ['required', 'string', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $result = $this->validated(
            fn () => $workflow->proposeDelegation(
                $user,
                $business,
                $data['delegator_membership_id'],
                $data['delegate_membership_id'],
                $data['decision_type'],
                $data['scope'],
                isset($data['effective_from'])
                    ? CarbonImmutable::parse($data['effective_from'])
                    : null,
                isset($data['expires_at'])
                    ? CarbonImmutable::parse($data['expires_at'])
                    : null,
            ),
            'delegation',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function proposeEmergency(
        Request $request,
        GovernanceAuthorityChangeWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'grantee_membership_id' => ['required', 'uuid'],
            'decision_type' => ['required', 'string', 'max:160'],
            'scope' => ['required', 'string', 'max:1000'],
            'capacity' => ['required', 'string', 'max:120'],
            'can_approve' => ['required', 'boolean'],
            'can_vote' => ['required', 'boolean'],
            'can_sign' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
            'effective_from' => ['nullable', 'date'],
            'expires_at' => ['required', 'date'],
        ]);

        $result = $this->validated(
            fn () => $workflow->proposeEmergencyAuthority(
                $user,
                $business,
                $data['grantee_membership_id'],
                $data['decision_type'],
                $data['scope'],
                $data['capacity'],
                (bool) $data['can_approve'],
                (bool) $data['can_vote'],
                (bool) $data['can_sign'],
                $data['reason'],
                CarbonImmutable::parse($data['expires_at']),
                isset($data['effective_from'])
                    ? CarbonImmutable::parse($data['effective_from'])
                    : null,
            ),
            'emergency_authority',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function proposeRevocation(
        Request $request,
        GovernanceAuthorityChangeWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'subject_type' => ['required', Rule::in([
                'delegation',
                'emergency_authority',
            ])],
            'subject_id' => ['required', 'uuid'],
        ]);

        $result = $this->validated(
            fn () => $workflow->proposeRevocation(
                $user,
                $business,
                $data['subject_type'],
                $data['subject_id'],
            ),
            'authority_change',
        );

        abort_if($result === null, 404);

        return back();
    }

    public function authorizeChange(
        Request $request,
        string $submission,
        GovernanceAuthorityChangeWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $ok = $this->validated(
            fn () => $workflow->authorize(
                $user,
                $business,
                $submission,
            ),
            'authority_change',
        );

        abort_if($ok === null, 404);

        if ($ok === false) {
            throw ValidationException::withMessages([
                'authority_change' => 'Exact authority-change Proposal has not yet received its required approved Governance Decision.',
            ]);
        }

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
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            throw $exception;
        }
    }
}
