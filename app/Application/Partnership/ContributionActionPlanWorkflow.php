<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Application\Governance\CreateGovernanceAction;
use App\Application\Governance\UpdateGovernanceActionStatus;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\ActionStatus;
use App\Domain\Partnership\ContributionActionPlanContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ContributionActionPlanWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly PartnershipOccurrence $occurrence,
        private readonly GetContributionActionPlanSource $source,
        private readonly ContributionActionPlanContract $contract,
        private readonly CreateGovernanceAction $createAction,
        private readonly UpdateGovernanceActionStatus $updateAction,
    ) {}

    public function createSuggested(
        User $user,
        Business $business,
        string $suggestionKey,
        string $assignedMembershipId,
    ): ?Action {
        if (! $this->canManage(
            $user,
            $business,
        )) {
            return null;
        }

        $context = $this->requireSource(
            $user,
            $business,
        );

        $suggestionKey =
            $this->contract
                ->assertSuggestionKey(
                    $suggestionKey,
                );

        $definition =
            collect(
                $this->suggestions(
                    $business,
                    $context,
                ),
            )->firstWhere(
                'key',
                $suggestionKey,
            );

        if (! is_array($definition)) {
            throw new InvalidArgumentException(
                'That Contribution Action suggestion is not currently available.',
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
            $existingId = DB::table(
                'contribution_action_links as link',
            )
                ->join(
                    'actions as action',
                    function ($join): void {
                        $join
                            ->on(
                                'action.id',
                                '=',
                                'link.action_id',
                            )
                            ->on(
                                'action.business_id',
                                '=',
                                'link.business_id',
                            );
                    },
                )
                ->where(
                    'link.business_id',
                    $business->getKey(),
                )
                ->where(
                    'link.contribution_decision_record_id',
                    $context[
                        'record'
                    ]->id,
                )
                ->where(
                    'link.suggestion_key',
                    $suggestionKey,
                )
                ->where(
                    'action.status',
                    '!=',
                    ActionStatus::Cancelled
                        ->value,
                )
                ->lockForUpdate()
                ->value('action.id');

            if (is_string($existingId)) {
                return Action::query()
                    ->where(
                        'business_id',
                        $business->getKey(),
                    )
                    ->whereKey(
                        $existingId,
                    )
                    ->first();
            }

            return $this->createAndLink(
                $user,
                $business,
                $context,
                $assignedMembershipId,
                (string)
                    $definition['title'],
                $definition[
                    'description'
                ] ?? null,
                $definition[
                    'dueDate'
                ] ?? null,
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
        if (! $this->canManage(
            $user,
            $business,
        )) {
            return null;
        }

        $context = $this->requireSource(
            $user,
            $business,
        );

        return DB::transaction(
            fn (): ?Action => $this->createAndLink(
                $user,
                $business,
                $context,
                $assignedMembershipId,
                $this->contract
                    ->normalizeTitle(
                        $title,
                    ),
                $this->contract
                    ->normalizeDescription(
                        $description,
                    ),
                $this->contract
                    ->normalizeDueDate(
                        $dueDate,
                    ),
                null,
            ),
        );
    }

    public function updateStatus(
        User $user,
        Business $business,
        string $actionId,
        ActionStatus $status,
        ?string $blockedReason = null,
    ): ?Action {
        if (! $this->canManage(
            $user,
            $business,
        )) {
            return null;
        }

        $context = $this->requireSource(
            $user,
            $business,
        );

        $linked = DB::table(
            'contribution_action_links',
        )
            ->where(
                'business_id',
                $business->getKey(),
            )
            ->where(
                'contribution_decision_record_id',
                $context['record']->id,
            )
            ->where(
                'action_id',
                $actionId,
            )
            ->exists();

        if (! $linked) {
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

    /**
     * @param array{
     *   register:array<string,mixed>,
     *   record:object,
     *   anchor:object
     * } $context
     * @return list<array{
     *   key:string,
     *   title:string,
     *   description:string,
     *   dueDate:?string
     * }>
     */
    public function suggestions(
        Business $business,
        array $context,
    ): array {
        $keys = [
            ContributionActionPlanContract::SUGGESTION_REVIEW,
        ];

        if (
            (int) $context[
                'record'
            ]->accepted_contribution_count > 0
        ) {
            $keys[] =
                ContributionActionPlanContract::SUGGESTION_OWNERSHIP;
        }

        $hasDefaulted = DB::table(
            'contributions',
        )
            ->where(
                'business_id',
                $business->getKey(),
            )
            ->where(
                'status',
                'defaulted',
            )
            ->exists();

        if ($hasDefaulted) {
            $keys[] =
                ContributionActionPlanContract::SUGGESTION_DEFAULT;
        }

        $hasOverdue = DB::table(
            'contributions',
        )
            ->where(
                'business_id',
                $business->getKey(),
            )
            ->whereIn(
                'status',
                ['approved', 'delivered'],
            )
            ->whereNotNull('due_date')
            ->where(
                'due_date',
                '<',
                now()->format('Y-m-d'),
            )
            ->exists();

        if ($hasOverdue) {
            $keys[] =
                ContributionActionPlanContract::SUGGESTION_OVERDUE;
        }

        $missingEvidence = DB::table(
            'contributions as contribution',
        )
            ->where(
                'contribution.business_id',
                $business->getKey(),
            )
            ->whereIn(
                'contribution.status',
                [
                    'proposed',
                    'reviewed',
                    'approved',
                    'delivered',
                ],
            )
            ->whereNotExists(
                function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            'evidence_links as link',
                        )
                        ->whereColumn(
                            'link.business_id',
                            'contribution.business_id',
                        )
                        ->where(
                            'link.target_type',
                            'contribution',
                        )
                        ->whereColumn(
                            'link.target_id',
                            'contribution.id',
                        );
                },
            )
            ->exists();

        if ($missingEvidence) {
            $keys[] =
                ContributionActionPlanContract::SUGGESTION_EVIDENCE;
        }

        return array_values(
            array_map(
                fn (string $key): array => $this->suggestionDefinition(
                    $context,
                    $key,
                ),
                array_values(
                    array_unique($keys),
                ),
            ),
        );
    }

    private function canManage(
        User $user,
        Business $business,
    ): bool {
        return $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
        ) && $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
        );
    }

    private function requireSource(
        User $user,
        Business $business,
    ): array {
        $context = $this->source->execute(
            $user,
            $business,
        );

        if ($context === null) {
            throw new RuntimeException(
                'Record the current Contribution Decision before creating its Action Plan.',
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
                (string) config(
                    'app.timezone',
                    'UTC',
                ),
            )?->endOfDay();

        $action = $this->createAction->execute(
            $user,
            $business,
            $assignedMembershipId,
            $title,
            (string)
                $context[
                    'anchor'
                ]->acceptance_decision_id,
            (string)
                $context[
                    'anchor'
                ]->formal_record_version_id,
            $description,
            $dueAt,
        );

        if ($action === null) {
            return null;
        }

        DB::table(
            'contribution_action_links',
        )->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'contribution_decision_record_id' => $context['record']->id,
            'action_id' => $action->getKey(),
            'suggestion_key' => $suggestionKey,
            'created_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'partnership.contribution.action_linked',
            'governance_action',
            (string) $action->getKey(),
            [
                'contribution_decision_record_id' => (string)
                        $context[
                            'record'
                        ]->id,
                'assigned_membership_id' => $assignedMembershipId,
                'status' => ActionStatus::Open
                    ->value,
            ],
        );

        return $action->fresh();
    }

    private function suggestionDefinition(
        array $context,
        string $key,
    ): array {
        return match ($key) {
            ContributionActionPlanContract::SUGGESTION_REVIEW => [
                'key' => $key,
                'title' => 'Review current Partner Contribution decision',
                'description' => 'Review the recorded Accepted Contribution decision on the agreed Review Date. This Action does not change any Accepted Contribution Value.',
                'dueDate' => (string)
                        $context[
                            'record'
                        ]->review_date,
            ],
            ContributionActionPlanContract::SUGGESTION_OWNERSHIP => [
                'key' => $key,
                'title' => 'Prepare Accepted Contribution inputs for Ownership discussion',
                'description' => 'Prepare the current Accepted Contribution Register for the later Ownership planning discussion. This Action does not create Equity, Shares or Ownership.',
                'dueDate' => null,
            ],
            ContributionActionPlanContract::SUGGESTION_DEFAULT => [
                'key' => $key,
                'title' => 'Resolve defaulted Partner Contribution follow-up',
                'description' => 'Review the defaulted Contribution history, evidence and agreed next step without changing its terminal status.',
                'dueDate' => null,
            ],
            ContributionActionPlanContract::SUGGESTION_OVERDUE => [
                'key' => $key,
                'title' => 'Follow up overdue Contribution delivery',
                'description' => 'Follow up the overdue delivery obligation and record any later Delivery through the Contribution workflow.',
                'dueDate' => null,
            ],
            ContributionActionPlanContract::SUGGESTION_EVIDENCE => [
                'key' => $key,
                'title' => 'Collect missing Contribution evidence',
                'description' => 'Collect supporting evidence in the Document Vault and link it to the relevant Contribution before governance submission.',
                'dueDate' => null,
            ],
            default => throw new InvalidArgumentException(
                'Unknown Contribution Action suggestion.',
            ),
        };
    }
}
