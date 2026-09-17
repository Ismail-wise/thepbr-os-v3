<?php

declare(strict_types=1);

namespace App\Application\Records;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\Exceptions\InvalidWorkflowTransition;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Domain\Records\ValueObjects\Revision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Support\Facades\DB;

final class SubmitRecordVersionForReview
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
    ) {}

    public function execute(
        User $user,
        Business $currentBusiness,
        Capability $capability,
        string $formalRecordVersionId,
        int $expectedRevision,
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

        return DB::transaction(function () use (
            $user,
            $currentBusiness,
            $capability,
            $formalRecordVersionId,
            $expectedRevision,
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

            if ($version->frozen_at !== null) {
                throw new InvalidWorkflowTransition(
                    'This formal-record version is already frozen.',
                );
            }

            $latest = RecordVersionStateTransition::query()
                ->where(
                    'formal_record_version_id',
                    $version->getKey(),
                )
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();

            if ($latest?->to_state !== FormalRecordState::Draft) {
                throw new InvalidWorkflowTransition(
                    'Only Draft versions may be submitted for review.',
                );
            }

            $revision = new Revision((int) $version->revision);

            if (! $revision->matches($expectedRevision)) {
                throw new StaleRevision(
                    $expectedRevision,
                    $revision->value,
                );
            }

            $version->frozen_at = now();
            $version->save();

            RecordVersionStateTransition::query()->create([
                'business_id' => $currentBusiness->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => ((int) $latest->sequence) + 1,
                'from_state' => FormalRecordState::Draft->value,
                'to_state' => FormalRecordState::ReadyForReview->value,
                'transitioned_by_user_id' => $user->getKey(),
                'occurred_at' => now(),
            ]);

            return $version->fresh();
        });
    }
}
