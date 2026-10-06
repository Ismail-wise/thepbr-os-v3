<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Application\Governance\CreateGovernanceAction;
use App\Application\Governance\RecordGovernanceOccurrence;
use App\Application\Governance\UpdateGovernanceActionStatus;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Capital\CapitalActionPlanContract;
use App\Domain\Governance\Enums\ActionStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class CapitalActionPlanWorkflow
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly GetCapitalActionPlanSource $source,
        private readonly CapitalActionPlanContract $contract,
        private readonly CreateGovernanceAction $createGovernanceAction,
        private readonly UpdateGovernanceActionStatus $updateGovernanceActionStatus,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function createSuggested(
        User $user,
        Business $business,
        string $suggestionKey,
        string $assignedMembershipId,
    ): ?Action {
        if (! $this->canManage($user, $business)) {
            return null;
        }

        $context = $this->requireSource($business);
        $suggestionKey = $this->contract->assertSuggestionKey(
            $suggestionKey,
        );

        $definition = $this->suggestionDefinition(
            $context,
            $suggestionKey,
        );

        if ($definition === null) {
            throw new InvalidArgumentException(
                'That Capital Action suggestion is not available for this approved decision.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $context,
            $suggestionKey,
            $assignedMembershipId,
            $definition,
        ): ?Action {
            $existingActionId = DB::table('capital_action_links as link')
                ->join('actions as action', function ($join): void {
                    $join
                        ->on('action.id', '=', 'link.action_id')
                        ->on(
                            'action.business_id',
                            '=',
                            'link.business_id',
                        );
                })
                ->where('link.business_id', $business->getKey())
                ->where(
                    'link.capital_decision_record_id',
                    $context['record']->id,
                )
                ->where('link.suggestion_key', $suggestionKey)
                ->where('action.status', '!=', ActionStatus::Cancelled->value)
                ->lockForUpdate()
                ->value('action.id');

            if (is_string($existingActionId)) {
                return Action::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($existingActionId)
                    ->first();
            }

            return $this->createAndLink(
                $user,
                $business,
                $context,
                $assignedMembershipId,
                $definition['title'],
                $definition['description'],
                $definition['dueDate'],
                $suggestionKey,
            );
        });
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

        $context = $this->requireSource($business);

        $title = $this->contract->normalizeTitle($title);
        $description = $this->contract->normalizeDescription($description);
        $dueDate = $this->contract->normalizeDueDate($dueDate);

        return DB::transaction(fn (): ?Action => $this->createAndLink(
            $user,
            $business,
            $context,
            $assignedMembershipId,
            $title,
            $description,
            $dueDate,
            null,
        ));
    }

    public function updateStatus(
        User $user,
        Business $business,
        string $actionId,
        ActionStatus $status,
        ?string $blockedReason = null,
    ): ?Action {
        if (! $this->canManage($user, $business)) {
            return null;
        }

        $context = $this->requireSource($business);

        $linked = DB::table('capital_action_links')
            ->where('business_id', $business->getKey())
            ->where(
                'capital_decision_record_id',
                $context['record']->id,
            )
            ->where('action_id', $actionId)
            ->exists();

        if (! $linked) {
            return null;
        }

        return $this->updateGovernanceActionStatus->execute(
            $user,
            $business,
            $actionId,
            $status,
            $blockedReason,
        );
    }

    /**
     * @param  array{record:object,approved:array<string,mixed>}  $context
     * @return list<array{key:string,title:string,description:string,dueDate:?string}>
     */
    public function suggestions(array $context): array
    {
        $keys = [
            CapitalActionPlanContract::SUGGESTION_REVIEW,
        ];

        $responses = $context['approved']['payload']['capitalRule'][
            'shortfallResponses'
        ] ?? [];

        if (is_array($responses)) {
            $map = [
                'reduce_scope' => CapitalActionPlanContract::SUGGESTION_REDUCE_SCOPE,
                'delay' => CapitalActionPlanContract::SUGGESTION_DELAY,
                'borrow' => CapitalActionPlanContract::SUGGESTION_BORROW,
                'capital_call' => CapitalActionPlanContract::SUGGESTION_CAPITAL_CALL,
            ];

            foreach ($responses as $response) {
                if (
                    is_string($response)
                    && isset($map[$response])
                    && ! in_array($map[$response], $keys, true)
                ) {
                    $keys[] = $map[$response];
                }
            }
        }

        if ($context['approved']['signatureRequired'] === true) {
            $keys[] = CapitalActionPlanContract::SUGGESTION_SIGNATURE;
        }

        return array_values(array_filter(array_map(
            fn (string $key): ?array => $this->suggestionDefinition(
                $context,
                $key,
            ),
            $keys,
        )));
    }

    private function canManage(
        User $user,
        Business $business,
    ): bool {
        return $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::CAPITAL_MANAGE,
        ) && $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
        );
    }

    /**
     * @return array{record:object,approved:array<string,mixed>}
     */
    private function requireSource(Business $business): array
    {
        $context = $this->source->execute($business);

        if ($context === null) {
            throw new RuntimeException(
                'Record the approved Capital Decision before creating its Action Plan.',
            );
        }

        return $context;
    }

    /**
     * @param  array{record:object,approved:array<string,mixed>}  $context
     */
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

        $action = $this->createGovernanceAction->execute(
            $user,
            $business,
            $assignedMembershipId,
            $title,
            $context['approved']['governanceDecisionId'],
            $context['approved']['formalRecordVersionId'],
            $description,
            $dueAt,
        );

        if ($action === null) {
            return null;
        }

        DB::table('capital_action_links')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'capital_decision_record_id' => $context['record']->id,
            'action_id' => $action->getKey(),
            'suggestion_key' => $suggestionKey,
            'created_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'capital.action.linked',
            'governance_action',
            (string) $action->getKey(),
            [
                'capital_decision_record_id' => (string) $context['record']->id,
                'formal_record_version_id' => $context['approved']['formalRecordVersionId'],
                'governance_decision_id' => $context['approved']['governanceDecisionId'],
                'assigned_membership_id' => $assignedMembershipId,
                'status' => ActionStatus::Open->value,
            ],
            $context['approved']['formalRecordVersionId'],
        );

        return $action->fresh();
    }

    /**
     * @param  array{record:object,approved:array<string,mixed>}  $context
     * @return array{key:string,title:string,description:string,dueDate:?string}|null
     */
    private function suggestionDefinition(
        array $context,
        string $key,
    ): ?array {
        return match ($key) {
            CapitalActionPlanContract::SUGGESTION_REVIEW => [
                'key' => $key,
                'title' => 'Review approved Capital decision',
                'description' => 'Review the recorded approved Capital decision on the agreed Review Date. This review does not change the approved Capital truth.',
                'dueDate' => (string) $context['record']->review_date,
            ],
            CapitalActionPlanContract::SUGGESTION_REDUCE_SCOPE => [
                'key' => $key,
                'title' => 'Prepare scope-reduction changes for review',
                'description' => 'Prepare proposed scope-reduction changes for review. Any Capital Plan change requires a new revision and approval.',
                'dueDate' => null,
            ],
            CapitalActionPlanContract::SUGGESTION_DELAY => [
                'key' => $key,
                'title' => 'Plan delayed Capital items and revised timing',
                'description' => 'Prepare revised timing for delayed Capital items without changing the approved Capital Plan automatically.',
                'dueDate' => null,
            ],
            CapitalActionPlanContract::SUGGESTION_BORROW => [
                'key' => $key,
                'title' => 'Prepare borrowing / financing option for review',
                'description' => 'Prepare financing options for review. This Action does not create debt or record funding received.',
                'dueDate' => null,
            ],
            CapitalActionPlanContract::SUGGESTION_CAPITAL_CALL => [
                'key' => $key,
                'title' => 'Prepare Capital Call / Contribution process',
                'description' => 'Prepare the later governed Capital Call / Contribution process. This Action does not execute a Capital Call or create Contribution truth.',
                'dueDate' => null,
            ],
            CapitalActionPlanContract::SUGGESTION_SIGNATURE => [
                'key' => $key,
                'title' => 'Complete required Capital approval signature before effectivity',
                'description' => 'Prepare for the required signature step. This Action does not create a Signature Request, mark Signed, or make the record Effective.',
                'dueDate' => (string) $context['record']->effective_date,
            ],
            default => null,
        };
    }
}
