<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Application\Governance\CreateGovernanceAction;
use App\Application\Governance\UpdateGovernanceActionStatus;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\ActionStatus;
use App\Domain\Partnership\OwnershipActionPlanContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class OwnershipActionPlanWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly PartnershipOccurrence $occurrence,
        private readonly GetOwnershipActionPlanSource $source,
        private readonly OwnershipActionPlanContract $contract,
        private readonly CreateGovernanceAction $createAction,
        private readonly UpdateGovernanceActionStatus $updateAction,
    ) {}

    /** @return list<array<string,mixed>> */
    public function suggestions(Business $business, array $context): array
    {
        return [[
            'key' => OwnershipActionPlanContract::SUGGESTION_REVIEW,
            'title' => 'Review current Ownership decision',
            'description' => 'Review the current official Ownership decision on its agreed Review Date. Completing this Action never changes shares or the Share Register.',
            'dueDate' => (string) $context['record']->review_date,
        ]];
    }

    public function createSuggested(
        User $user,
        Business $business,
        string $suggestionKey,
        string $assignedMembershipId,
    ): ?Action {
        if (! $this->canManage($user, $business)) {
            return null;
        }

        $key = $this->contract->assertSuggestionKey($suggestionKey);
        $context = $this->requireSource($business);
        $definition = collect($this->suggestions($business, $context))
            ->firstWhere('key', $key);

        if (! is_array($definition)) {
            throw new InvalidArgumentException('Ownership Action suggestion is unavailable.');
        }

        $existing = DB::table('ownership_action_links as link')
            ->join('actions as action', 'action.id', '=', 'link.action_id')
            ->where('link.business_id', $business->getKey())
            ->where('link.ownership_decision_record_id', $context['record']->id)
            ->where('link.suggestion_key', $key)
            ->where('action.status', '<>', ActionStatus::Cancelled->value)
            ->first();

        if ($existing !== null) {
            return Action::query()->find((string) $existing->action_id);
        }

        return $this->createAndLink(
            $user,
            $business,
            $context,
            $assignedMembershipId,
            (string) $definition['title'],
            (string) $definition['description'],
            $definition['dueDate'],
            $key,
        );
    }

    public function createCustom(
        User $user,
        Business $business,
        string $assignedMembershipId,
        string $title,
        ?string $description,
        ?string $dueDate,
    ): ?Action {
        if (! $this->canManage($user, $business)) {
            return null;
        }

        return $this->createAndLink(
            $user,
            $business,
            $this->requireSource($business),
            $assignedMembershipId,
            $this->contract->normalizeTitle($title),
            $this->contract->normalizeDescription($description),
            $this->contract->normalizeDueDate($dueDate),
            null,
        );
    }

    public function updateStatus(
        User $user,
        Business $business,
        string $actionId,
        ActionStatus $status,
        ?string $blockedReason = null,
    ): ?Action {
        $linked = DB::table('ownership_action_links')
            ->where('business_id', $business->getKey())
            ->where('action_id', $actionId)
            ->exists();

        if (! $linked || ! $this->canManage($user, $business)) {
            return null;
        }

        return $this->updateAction->execute(
            $user,
            $business,
            $actionId,
            $status,
            $blockedReason,
        );
    }

    private function canManage(User $user, Business $business): bool
    {
        return $this->actor->allows($user, $business, CapabilityCatalog::OWNERSHIP_MANAGE)
            && $this->actor->allows($user, $business, CapabilityCatalog::GOVERNANCE_ACTION_MANAGE);
    }

    /** @return array{source:array<string,mixed>,record:object} */
    private function requireSource(Business $business): array
    {
        $context = $this->source->execute($business);
        if ($context === null) {
            throw new RuntimeException(
                'Record the current Ownership Decision before creating its Action Plan.',
            );
        }

        return $context;
    }

    private function createAndLink(
        User $user,
        Business $business,
        array $context,
        string $assignedMembershipId,
        string $title,
        ?string $description,
        ?string $dueDate,
        ?string $suggestionKey,
    ): ?Action {
        $dueAt = $dueDate === null
            ? null
            : CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $dueDate,
                (string) config('app.timezone', 'UTC'),
            )?->endOfDay();

        return DB::transaction(function () use (
            $user,
            $business,
            $context,
            $assignedMembershipId,
            $title,
            $description,
            $dueAt,
            $suggestionKey,
        ): ?Action {
            $source = $context['source'];
            $action = $this->createAction->execute(
                $user,
                $business,
                $assignedMembershipId,
                $title,
                (string) $source['decision']->id,
                (string) $source['submission']->formal_record_version_id,
                $description,
                $dueAt,
            );

            if ($action === null) {
                return null;
            }

            DB::table('ownership_action_links')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'ownership_decision_record_id' => $context['record']->id,
                'action_id' => $action->getKey(),
                'suggestion_key' => $suggestionKey,
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'partnership.ownership.action_linked',
                'governance_action',
                (string) $action->getKey(),
                [
                    'ownership_decision_record_id' => (string) $context['record']->id,
                    'status' => ActionStatus::Open->value,
                ],
            );

            return $action->fresh();
        });
    }
}
