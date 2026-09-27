<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Governance;

use App\Application\Governance\GovernanceMeetingWorkflow;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class GovernanceMeetingController
{
    public function index(
        Request $request,
        GovernanceMeetingWorkflow $workflow,
    ): Response {
        [$user, $business] = $this->context($request);

        $workspace = $workflow->workspace($user, $business);

        abort_if($workspace === null, 404);

        return Inertia::render('Governance/Meetings', [
            'meetingWorkspace' => $workspace,
        ]);
    }

    public function store(
        Request $request,
        GovernanceMeetingWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'scheduled_at' => ['required', 'date'],
            'notice_sent_at' => ['nullable', 'date'],
            'quorum_required' => ['required', 'integer', 'min:1', 'max:999'],
            'agenda_owner_membership_id' => ['required', 'uuid'],
            'minutes_owner_membership_id' => ['required', 'uuid'],
            'agenda' => ['required', 'string', 'max:10000'],
            'attendee_membership_ids' => ['required', 'array', 'min:1', 'max:200'],
            'attendee_membership_ids.*' => ['required', 'uuid'],
        ]);

        try {
            $id = $workflow->schedule(
                $user,
                $business,
                $data['title'],
                CarbonImmutable::parse($data['scheduled_at']),
                (int) $data['quorum_required'],
                $data['agenda_owner_membership_id'],
                $data['minutes_owner_membership_id'],
                $data['agenda'],
                $data['attendee_membership_ids'],
                isset($data['notice_sent_at'])
                    ? CarbonImmutable::parse($data['notice_sent_at'])
                    : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'meeting' => $exception->getMessage(),
            ]);
        }

        abort_if($id === null, 404);

        return back();
    }

    public function hold(
        Request $request,
        string $meeting,
        GovernanceMeetingWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);
        $data = $request->validate([
            'minutes' => ['required', 'string', 'max:20000'],
            'attendance' => ['required', 'array', 'min:1'],
            'attendance.*' => ['required', 'in:present,remote,absent,recused'],
        ]);

        try {
            $ok = $workflow->hold(
                $user,
                $business,
                $meeting,
                $data['minutes'],
                $data['attendance'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'meeting' => $exception->getMessage(),
            ]);
        }

        abort_if($ok === null, 404);

        return back();
    }

    public function cancel(
        Request $request,
        string $meeting,
        GovernanceMeetingWorkflow $workflow,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $ok = $workflow->cancel(
            $user,
            $business,
            $meeting,
        );

        abort_if($ok === null, 404);

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
