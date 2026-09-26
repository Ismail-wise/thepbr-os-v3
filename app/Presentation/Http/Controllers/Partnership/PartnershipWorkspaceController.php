<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Partnership;

use App\Application\Partnership\DueDiligenceWorkflow;
use App\Application\Partnership\GetPartnershipWorkspace;
use App\Application\Partnership\PartnerDirectory;
use App\Application\Partnership\PartnerDynamicsReference;
use App\Domain\Partnership\Enums\DueDiligenceStatus;
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

final class PartnershipWorkspaceController
{
    public function index(
        Request $request,
        GetPartnershipWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);

        $payload = $workspace->execute(
            $user,
            $business,
        );

        abort_if($payload === null, 404);

        return Inertia::render('Partnership/Index', [
            'partnership' => $payload,
        ]);
    }

    public function createPartner(
        Request $request,
        PartnerDirectory $directory,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:254'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $created = $this->validatedCall(
            fn () => $directory->create(
                $user,
                $business,
                $data['display_name'],
                $data['legal_name'] ?? null,
                $data['email'] ?? null,
                $data['notes'] ?? null,
            ),
            'partner',
        );

        abort_if($created === null, 404);

        return back();
    }

    public function invitePartner(
        Request $request,
        string $partner,
        PartnerDirectory $directory,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'expires_in_hours' => [
                'required',
                'integer',
                'min:1',
                'max:168',
            ],
        ]);

        $invitation = $this->validatedCall(
            fn () => $directory->invite(
                $user,
                $business,
                $partner,
                $data['email'],
                (int) $data['expires_in_hours'],
            ),
            'invitation',
        );

        abort_if($invitation === null, 404);

        return back()->with(
            'partner_invitation_token',
            $invitation['token'],
        );
    }

    public function saveDueDiligence(
        Request $request,
        string $partner,
        DueDiligenceWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'case_id' => ['nullable', 'uuid'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'status' => [
                'required',
                Rule::in([
                    'draft',
                    'in_review',
                    'completed',
                    'blocked',
                ]),
            ],
            'risk_rating' => [
                'nullable',
                Rule::in([
                    'low',
                    'moderate',
                    'high',
                    'critical',
                ]),
            ],
            'identity_legal_info' => [
                'nullable',
                'string',
                'max:4000',
            ],
            'background_summary' => [
                'nullable',
                'string',
                'max:4000',
            ],
            'business_experience' => [
                'nullable',
                'string',
                'max:4000',
            ],
            'financial_capacity' => [
                'nullable',
                'string',
                'max:4000',
            ],
            'reputation' => ['nullable', 'string', 'max:4000'],
            'existing_business_interests' => [
                'nullable',
                'string',
                'max:4000',
            ],
            'conflict_of_interest' => [
                'nullable',
                'string',
                'max:4000',
            ],
            'time_commitment' => [
                'nullable',
                'string',
                'max:4000',
            ],
            'legal_regulatory_check' => [
                'nullable',
                'string',
                'max:4000',
            ],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $fields = [
            'identity_legal_info' => $data['identity_legal_info'] ?? null,
            'background_summary' => $data['background_summary'] ?? null,
            'business_experience' => $data['business_experience'] ?? null,
            'financial_capacity' => $data['financial_capacity'] ?? null,
            'reputation' => $data['reputation'] ?? null,
            'existing_business_interests' => $data['existing_business_interests'] ?? null,
            'conflict_of_interest' => $data['conflict_of_interest'] ?? null,
            'time_commitment' => $data['time_commitment'] ?? null,
            'legal_regulatory_check' => $data['legal_regulatory_check'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        $saved = $this->validatedCall(
            fn () => $workflow->save(
                $user,
                $business,
                $partner,
                $data['case_id'] ?? null,
                (int) $data['expected_revision'],
                DueDiligenceStatus::from($data['status']),
                $data['risk_rating'] ?? null,
                $fields,
            ),
            'due_diligence',
        );

        abort_if($saved === null, 404);

        return back();
    }

    public function recordPartnerDynamics(
        Request $request,
        string $partner,
        PartnerDynamicsReference $reference,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'source_assessment_id' => [
                'required',
                'string',
                'max:120',
            ],
            'source_url' => [
                'nullable',
                'url',
                'max:1000',
            ],
            'assessment_version' => [
                'required',
                'string',
                'max:80',
            ],
            'primary_profile' => [
                'required',
                Rule::in([
                    'visionary',
                    'builder',
                    'connector',
                    'analyst',
                    'operator',
                    'guardian',
                    'negotiator',
                    'optimizer',
                ]),
            ],
            'secondary_profile' => [
                'nullable',
                Rule::in([
                    'visionary',
                    'builder',
                    'connector',
                    'analyst',
                    'operator',
                    'guardian',
                    'negotiator',
                    'optimizer',
                ]),
            ],
            'completed_at' => ['required', 'date'],
        ]);

        $id = $this->validatedCall(
            fn () => $reference->record(
                $user,
                $business,
                $partner,
                $data['source_assessment_id'],
                $data['source_url'] ?? null,
                $data['assessment_version'],
                $data['primary_profile'],
                $data['secondary_profile'] ?? null,
                CarbonImmutable::parse($data['completed_at']),
            ),
            'partner_dynamics',
        );

        abort_if($id === null, 404);

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
            InvalidArgumentException
            |StaleRevision $exception
        ) {
            throw ValidationException::withMessages([
                $field => $exception->getMessage(),
            ]);
        }
    }
}
