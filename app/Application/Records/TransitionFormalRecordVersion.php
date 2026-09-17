<?php

declare(strict_types=1);

namespace App\Application\Records;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\Exceptions\InvalidWorkflowTransition;
use App\Domain\Records\ValueObjects\RequirementResult;
use App\Domain\Records\Workflow\FormalRecordWorkflow;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionSupersession;
use Illuminate\Support\Facades\DB;

/**
 * Internal mechanical lifecycle primitive.
 *
 * This service does not establish Approval, Governance, Vote, Signature or
 * Ownership authority. F3 authority-bearing orchestration must establish any
 * required governance authority before invoking authority-bearing states.
 * No Round 2 route/controller exposes this primitive directly.
 */
final class TransitionFormalRecordVersion
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
        private readonly FormalRecordWorkflow $workflow,
    ) {}

    /**
     * @param  array<RequirementResult>  $requirements
     */
    public function execute(
        User $user,
        Business $currentBusiness,
        Capability $capability,
        string $formalRecordVersionId,
        FormalRecordState $targetState,
        array $requirements = [],
    ): ?FormalRecordVersion {
        $baseDecision = $this->authorizeBusinessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            $capability,
        );

        if (! $baseDecision->allowed) {
            return null;
        }

        if ($targetState === FormalRecordState::Superseded) {
            throw new InvalidWorkflowTransition(
                'Superseded is established only by atomic effective-head replacement.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $currentBusiness,
            $capability,
            $formalRecordVersionId,
            $targetState,
            $requirements,
        ): ?FormalRecordVersion {
            $version = FormalRecordVersion::query()
                ->where('business_id', $currentBusiness->getKey())
                ->whereKey($formalRecordVersionId)
                ->lockForUpdate()
                ->first();

            if ($version === null) {
                return null;
            }

            $resourceDecision = $this->authorizeBusinessCapability->decide(
                $user,
                $currentBusiness,
                $currentBusiness,
                $capability,
                FormalRecordVersion::class,
                (string) $version->getKey(),
            );

            if (! $resourceDecision->allowed) {
                return null;
            }

            $family = FormalRecordFamily::query()
                ->where('business_id', $currentBusiness->getKey())
                ->whereKey($version->formal_record_family_id)
                ->lockForUpdate()
                ->first();

            if ($family === null) {
                return null;
            }

            $latest = $this->latestTransition($version);

            if ($latest === null) {
                throw new InvalidWorkflowTransition(
                    'Formal-record version has no lifecycle state.',
                );
            }

            $from = $latest->to_state;

            $this->workflow->assertCanTransition($from, $targetState);
            $this->workflow->assertRequirements($requirements);

            if (
                $targetState->requiresFrozenVersion()
                && $version->frozen_at === null
            ) {
                throw new InvalidWorkflowTransition(
                    'The target lifecycle state requires a frozen version.',
                );
            }

            $head = null;
            $oldVersion = null;

            if ($targetState === FormalRecordState::Effective) {
                if ($version->effective_from === null) {
                    throw new InvalidWorkflowTransition(
                        'An Effective version requires effective-from.',
                    );
                }

                if ($version->effective_from->isFuture()) {
                    throw new InvalidWorkflowTransition(
                        'A future-effective version cannot become current early.',
                    );
                }

                if (
                    $version->effective_until !== null
                    && ! $version->effective_until->isFuture()
                ) {
                    throw new InvalidWorkflowTransition(
                        'An already-ended planned effective period cannot become current.',
                    );
                }

                $head = RecordFamilyEffectiveHead::query()
                    ->where('business_id', $currentBusiness->getKey())
                    ->where(
                        'formal_record_family_id',
                        $family->getKey(),
                    )
                    ->lockForUpdate()
                    ->first();

                if (
                    $head !== null
                    && (string) $head->formal_record_version_id
                        !== (string) $version->getKey()
                ) {
                    $oldVersion = FormalRecordVersion::query()
                        ->where('business_id', $currentBusiness->getKey())
                        ->where(
                            'formal_record_family_id',
                            $family->getKey(),
                        )
                        ->whereKey($head->formal_record_version_id)
                        ->lockForUpdate()
                        ->first();

                    if ($oldVersion === null) {
                        throw new InvalidWorkflowTransition(
                            'Current effective head is internally inconsistent.',
                        );
                    }

                    $oldDecision = $this->authorizeBusinessCapability->decide(
                        $user,
                        $currentBusiness,
                        $currentBusiness,
                        $capability,
                        FormalRecordVersion::class,
                        (string) $oldVersion->getKey(),
                    );

                    if (! $oldDecision->allowed) {
                        return null;
                    }

                    $oldState = $this->latestTransition($oldVersion)?->to_state;

                    if ($oldState !== FormalRecordState::Effective) {
                        throw new InvalidWorkflowTransition(
                            'Current effective head does not point to an Effective version.',
                        );
                    }

                    if (
                        $oldVersion->effective_from === null
                        || $version->effective_from
                            <= $oldVersion->effective_from
                    ) {
                        throw new InvalidWorkflowTransition(
                            'A superseding version must begin after the current effective version.',
                        );
                    }
                }
            }

            $this->appendTransition(
                $user,
                $currentBusiness,
                $version,
                $latest,
                $targetState,
            );

            if ($targetState === FormalRecordState::Effective) {
                if ($oldVersion !== null && $head !== null) {
                    RecordVersionSupersession::query()->create([
                        'business_id' => $currentBusiness->getKey(),
                        'formal_record_family_id' => $family->getKey(),
                        'superseded_version_id' => $oldVersion->getKey(),
                        'superseding_version_id' => $version->getKey(),
                        'superseded_at' => $version->effective_from,
                    ]);

                    $oldLatest = $this->latestTransition($oldVersion);

                    if ($oldLatest === null) {
                        throw new InvalidWorkflowTransition(
                            'Superseded version has no lifecycle state.',
                        );
                    }

                    $this->workflow->assertCanTransition(
                        $oldLatest->to_state,
                        FormalRecordState::Superseded,
                    );

                    $this->appendTransition(
                        $user,
                        $currentBusiness,
                        $oldVersion,
                        $oldLatest,
                        FormalRecordState::Superseded,
                    );

                    $head->fill([
                        'formal_record_version_id' => $version->getKey(),
                        'activated_at' => now(),
                    ]);
                    $head->save();
                } else {
                    RecordFamilyEffectiveHead::query()->create([
                        'business_id' => $currentBusiness->getKey(),
                        'formal_record_family_id' => $family->getKey(),
                        'formal_record_version_id' => $version->getKey(),
                        'activated_at' => now(),
                    ]);
                }
            }

            return $version->fresh();
        });
    }

    private function latestTransition(
        FormalRecordVersion $version,
    ): ?RecordVersionStateTransition {
        return RecordVersionStateTransition::query()
            ->where(
                'formal_record_version_id',
                $version->getKey(),
            )
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->first();
    }

    private function appendTransition(
        User $user,
        Business $currentBusiness,
        FormalRecordVersion $version,
        RecordVersionStateTransition $latest,
        FormalRecordState $targetState,
    ): void {
        RecordVersionStateTransition::query()->create([
            'business_id' => $currentBusiness->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => ((int) $latest->sequence) + 1,
            'from_state' => $latest->to_state->value,
            'to_state' => $targetState->value,
            'transitioned_by_user_id' => $user->getKey(),
            'occurred_at' => now(),
        ]);
    }
}
