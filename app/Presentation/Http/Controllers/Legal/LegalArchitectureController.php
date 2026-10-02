<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Legal;

use App\Application\Legal\GetLegalWorkspace;
use App\Application\Legal\LegalArchitectureWorkflow;
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

final class LegalArchitectureController
{
    public function index(
        Request $request,
        GetLegalWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);
        $payload = $workspace->execute($user, $business);

        abort_if($payload === null, 404);

        return Inertia::render('Business/LegalStructure', [
            'legal' => $payload,
        ]);
    }

    public function create(
        Request $request,
        LegalArchitectureWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate($this->draftRules());

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
        );

        abort_if($created === null, 404);

        return back();
    }

    public function submit(
        Request $request,
        string $formalRecordVersion,
        LegalArchitectureWorkflow $workflow,
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
        );

        abort_if($result === null, 404);

        return back();
    }

    public function review(
        Request $request,
        string $formalRecordVersion,
        LegalArchitectureWorkflow $workflow,
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
        );

        abort_unless($ok, 404);

        return back();
    }

    public function syncDecision(
        Request $request,
        string $formalRecordVersion,
        LegalArchitectureWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $ok = $this->validated(
            fn () => $workflow->syncApprovedDecision(
                $user,
                $business,
                $formalRecordVersion,
            ),
        );

        abort_unless($ok, 404);

        return back();
    }

    /** @return array<string,array<int,string|object>> */
    private function draftRules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'review_due_at' => ['nullable', 'date'],
            'legal_form' => ['required', 'string', 'max:120'],
            'entity_name' => ['nullable', 'string', 'max:240'],
            'primary_jurisdiction_code' => [
                'required',
                'string',
                'max:24',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/',
            ],
            'governing_law_reference' => ['nullable', 'string', 'max:240'],
            'registered_address' => ['nullable', 'string', 'max:4000'],
            'confidentiality' => [
                'required',
                Rule::in(['standard', 'restricted']),
            ],
            'notes' => ['nullable', 'string', 'max:10000'],
            'jurisdictions' => ['present', 'array', 'max:100'],
            'jurisdictions.*.jurisdiction_code' => [
                'required',
                'string',
                'max:24',
            ],
            'jurisdictions.*.scope_type' => ['required', Rule::in([
                'entity', 'registration', 'license_permit', 'tax',
                'employment', 'contract', 'data', 'other',
            ])],
            'jurisdictions.*.scope_reference' => [
                'nullable',
                'string',
                'max:240',
            ],
            'jurisdictions.*.applicability' => ['required', Rule::in([
                'applicable', 'not_applicable', 'needs_review',
            ])],
            'jurisdictions.*.rationale' => ['nullable', 'string', 'max:4000'],
            'jurisdictions.*.legal_review_required' => ['boolean'],
            'registrations' => ['present', 'array', 'max:100'],
            'registrations.*.registration_type' => [
                'required',
                'string',
                'max:120',
            ],
            'registrations.*.authority' => ['required', 'string', 'max:240'],
            'registrations.*.reference_number' => [
                'nullable',
                'string',
                'max:240',
            ],
            'registrations.*.jurisdiction_code' => [
                'required',
                'string',
                'max:24',
            ],
            'registrations.*.registration_date' => ['nullable', 'date'],
            'registrations.*.status' => ['required', Rule::in([
                'planned', 'pending', 'active', 'suspended',
                'expired', 'cancelled', 'closed',
            ])],
            'registrations.*.evidence_reference' => [
                'nullable',
                'string',
                'max:4000',
            ],
            'licenses' => ['present', 'array', 'max:100'],
            'licenses.*.name' => ['required', 'string', 'max:240'],
            'licenses.*.authority' => ['required', 'string', 'max:240'],
            'licenses.*.reference_number' => [
                'nullable',
                'string',
                'max:240',
            ],
            'licenses.*.jurisdiction_code' => [
                'required',
                'string',
                'max:24',
            ],
            'licenses.*.start_date' => ['nullable', 'date'],
            'licenses.*.expiry_date' => ['nullable', 'date'],
            'licenses.*.review_date' => ['nullable', 'date'],
            'licenses.*.status' => ['required', Rule::in([
                'planned', 'pending', 'active', 'suspended',
                'expired', 'cancelled', 'closed',
            ])],
            'licenses.*.evidence_reference' => [
                'nullable',
                'string',
                'max:4000',
            ],
            'requirements' => ['present', 'array', 'max:200'],
            'requirements.*.requirement_key' => [
                'required',
                'string',
                'max:120',
            ],
            'requirements.*.title' => ['required', 'string', 'max:240'],
            'requirements.*.category' => ['required', 'string', 'max:80'],
            'requirements.*.description' => [
                'required',
                'string',
                'max:10000',
            ],
            'requirements.*.jurisdiction_code' => [
                'required',
                'string',
                'max:24',
            ],
            'requirements.*.source_authority' => [
                'nullable',
                'string',
                'max:240',
            ],
            'requirements.*.applicable_from' => ['nullable', 'date'],
            'requirements.*.applicable_until' => ['nullable', 'date'],
            'requirements.*.status' => ['required', Rule::in([
                'identified', 'met', 'warning', 'blocked', 'not_applicable',
            ])],
            'requirements.*.legal_review_required' => ['boolean'],
            'requirements.*.evidence_reference' => [
                'nullable',
                'string',
                'max:4000',
            ],
            'reviews' => ['present', 'array', 'max:200'],
            'reviews.*.requirement_index' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'reviews.*.review_type' => ['required', 'string', 'max:80'],
            'reviews.*.reviewer_name' => ['required', 'string', 'max:240'],
            'reviews.*.reviewer_capacity' => [
                'required',
                'string',
                'max:160',
            ],
            'reviews.*.reviewer_organization' => [
                'nullable',
                'string',
                'max:240',
            ],
            'reviews.*.review_date' => ['required', 'date'],
            'reviews.*.outcome' => ['required', Rule::in([
                'pending', 'passed', 'qualified', 'issues_found', 'rejected',
            ])],
            'reviews.*.notes' => ['nullable', 'string', 'max:10000'],
            'reviews.*.evidence_reference' => [
                'nullable',
                'string',
                'max:4000',
            ],
        ];
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

    private function validated(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'legal' => $exception->getMessage(),
            ]);
        }
    }
}