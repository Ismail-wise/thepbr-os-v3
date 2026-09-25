<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Governance;

use App\Application\Governance\CastGovernanceVote;
use App\Application\Governance\CompleteGovernanceReview;
use App\Application\Governance\CompleteSignatureRequest;
use App\Application\Governance\CreateAmendmentRequest;
use App\Application\Governance\CreateGovernanceAction;
use App\Application\Governance\CreateSignatureRequest;
use App\Application\Governance\DeclineSignatureRequest;
use App\Application\Governance\GetGovernanceCommandCenter;
use App\Application\Governance\MakeGovernedRecordEffective;
use App\Application\Governance\MarkGovernanceNotificationRead;
use App\Application\Governance\PrepareGovernedRecordForEffect;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\RecuseGovernanceDecisionParticipant;
use App\Application\Governance\ResolveAmendmentRequest;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Application\Governance\SendSignatureRequest;
use App\Application\Governance\SignGovernanceDocument;
use App\Application\Governance\UpdateGovernanceActionStatus;
use App\Domain\Governance\Enums\ActionStatus;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\ReviewOutcome;
use App\Domain\Governance\Enums\VoteChoice;
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

final class GovernanceWorkspaceController
{
    public function index(
        Request $request,
        GetGovernanceCommandCenter $commandCenter,
    ): Response {
        [$user, $business] = $this->context($request);

        $workspace = $commandCenter->execute(
            $user,
            $business,
        );

        abort_if($workspace === null, 404);

        return Inertia::render('Governance/Index', [
            'governance' => $workspace,
        ]);
    }

    public function approval(
        Request $request,
        string $decision,
        RecordGovernanceApproval $recordApproval,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $validated = $request->validate([
            'outcome' => [
                'required',
                Rule::enum(ApprovalOutcome::class),
            ],
            'rationale' => ['nullable', 'string', 'max:1000'],
        ]);

        $recorded = $recordApproval->execute(
            $user,
            $business,
            $decision,
            ApprovalOutcome::from($validated['outcome']),
            $validated['rationale'] ?? null,
        );

        abort_if($recorded === null, 404);

        return back();
    }

    public function vote(
        Request $request,
        string $decision,
        CastGovernanceVote $castVote,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $validated = $request->validate([
            'choice' => [
                'required',
                Rule::in([
                    VoteChoice::For->value,
                    VoteChoice::Against->value,
                    VoteChoice::Abstain->value,
                ]),
            ],
            'rationale' => ['nullable', 'string', 'max:1000'],
        ]);

        $vote = $castVote->execute(
            $user,
            $business,
            $decision,
            VoteChoice::from($validated['choice']),
            $validated['rationale'] ?? null,
        );

        abort_if($vote === null, 404);

        return back();
    }

    public function recuse(
        Request $request,
        string $decision,
        RecuseGovernanceDecisionParticipant $recuse,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $participant = $recuse->execute(
            $user,
            $business,
            $decision,
            trim($validated['reason']),
        );

        abort_if($participant === null, 404);

        return back();
    }

    public function resolveDecision(
        Request $request,
        string $decision,
        ResolveGovernanceDecision $resolveDecision,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        try {
            $resolved = $resolveDecision->approve(
                $user,
                $business,
                $decision,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'decision' => $exception->getMessage(),
            ]);
        }

        abort_if($resolved === null, 404);

        return back();
    }

    public function createSignatureRequest(
        Request $request,
        string $decision,
        CreateSignatureRequest $createSignatureRequest,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $validated = $request->validate([
            'document_version_id' => ['required', 'uuid'],
        ]);

        $created = $createSignatureRequest->execute(
            $user,
            $business,
            $decision,
            $validated['document_version_id'],
        );

        abort_if($created === null, 404);

        return back();
    }

    public function sendSignatureRequest(
        Request $request,
        string $signatureRequest,
        SendSignatureRequest $sendSignatureRequest,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $sent = $sendSignatureRequest->execute(
            $user,
            $business,
            $signatureRequest,
        );

        abort_if($sent === null, 404);

        return back();
    }

    public function sign(
        Request $request,
        string $signatureRequest,
        SignGovernanceDocument $signDocument,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $validated = $request->validate([
            'consent' => ['required', 'accepted'],
        ]);

        unset($validated);

        $sessionIdentity = implode('|', [
            $request->session()->getId(),
            (string) $user->getKey(),
            $signatureRequest,
            now()->format(DATE_ATOM),
        ]);

        try {
            $signature = $signDocument->execute(
                $user,
                $business,
                $signatureRequest,
                'in_app_authenticated',
                'I confirm I am signing this exact document version.',
                hash('sha256', $sessionIdentity),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'consent' => $exception->getMessage(),
            ]);
        }

        abort_if($signature === null, 404);

        return back();
    }

    public function declineSignature(
        Request $request,
        string $signatureRequest,
        DeclineSignatureRequest $decline,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $declined = $decline->execute(
            $user,
            $business,
            $signatureRequest,
        );

        abort_if($declined === null, 404);

        return back();
    }

    public function completeSignatureRequest(
        Request $request,
        string $signatureRequest,
        CompleteSignatureRequest $complete,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $completed = $complete->execute(
            $user,
            $business,
            $signatureRequest,
        );

        abort_if($completed === null, 404);

        return back();
    }

    public function prepareEffect(
        Request $request,
        string $decision,
        PrepareGovernedRecordForEffect $prepare,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $validated = $request->validate([
            'formal_record_version_id' => ['required', 'uuid'],
        ]);

        try {
            $ok = $prepare->execute(
                $user,
                $business,
                $decision,
                $validated['formal_record_version_id'],
            );
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'formal_record_version_id' => $exception->getMessage(),
            ]);
        }

        abort_unless($ok, 404);

        return back();
    }

    public function makeEffective(
        Request $request,
        string $decision,
        MakeGovernedRecordEffective $makeEffective,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $validated = $request->validate([
            'formal_record_version_id' => ['required', 'uuid'],
        ]);

        try {
            $ok = $makeEffective->execute(
                $user,
                $business,
                $decision,
                $validated['formal_record_version_id'],
            );
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'formal_record_version_id' => $exception->getMessage(),
            ]);
        }

        abort_unless($ok, 404);

        return back();
    }

    public function createAction(
        Request $request,
        string $decision,
        CreateGovernanceAction $createAction,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $validated = $request->validate([
            'assigned_membership_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'due_at' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $action = $createAction->execute(
            $user,
            $business,
            $validated['assigned_membership_id'],
            trim($validated['title']),
            $decision,
            null,
            $validated['description'] ?? null,
            isset($validated['due_at'])
                ? CarbonImmutable::parse($validated['due_at'])->endOfDay()
                : null,
        );

        abort_if($action === null, 404);

        return back();
    }

    public function updateAction(
        Request $request,
        string $action,
        UpdateGovernanceActionStatus $updateAction,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $validated = $request->validate([
            'status' => [
                'required',
                Rule::enum(ActionStatus::class),
            ],
            'blocked_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $updateAction->execute(
                $user,
                $business,
                $action,
                ActionStatus::from($validated['status']),
                $validated['blocked_reason'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'blocked_reason' => $exception->getMessage(),
            ]);
        }

        abort_if($updated === null, 404);

        return back();
    }

    public function completeReview(
        Request $request,
        string $review,
        CompleteGovernanceReview $completeReview,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $validated = $request->validate([
            'outcome' => [
                'required',
                Rule::enum(ReviewOutcome::class),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $completed = $completeReview->execute(
            $user,
            $business,
            $review,
            ReviewOutcome::from($validated['outcome']),
            $validated['notes'] ?? null,
        );

        abort_if($completed === null, 404);

        return back();
    }

    public function createAmendment(
        Request $request,
        string $formalRecordVersion,
        CreateAmendmentRequest $createAmendment,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'review_id' => ['nullable', 'uuid'],
        ]);

        $created = $createAmendment->execute(
            $user,
            $business,
            $formalRecordVersion,
            trim($validated['reason']),
            $validated['review_id'] ?? null,
        );

        abort_if($created === null, 404);

        return back();
    }

    public function resolveAmendment(
        Request $request,
        string $amendment,
        ResolveAmendmentRequest $resolveAmendment,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $validated = $request->validate([
            'outcome' => [
                'required',
                Rule::in(['accepted', 'rejected']),
            ],
        ]);

        $resolved = $resolveAmendment->execute(
            $user,
            $business,
            $amendment,
            $validated['outcome'],
        );

        abort_if($resolved === null, 404);

        return back();
    }

    public function markNotificationRead(
        Request $request,
        string $notification,
        MarkGovernanceNotificationRead $markRead,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $updated = $markRead->execute(
            $user,
            $business,
            $notification,
        );

        abort_if($updated === null, 404);

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

        if (! $user instanceof User || ! $business instanceof Business) {
            abort(403);
        }

        return [$user, $business];
    }
}
