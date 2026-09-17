<?php

declare(strict_types=1);

namespace App\Application\Records;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Records\Enums\FormalRecordState;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;

final class ResolveCurrentEffectiveRecordVersion
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
    ) {}

    public function execute(
        User $user,
        Business $currentBusiness,
        Capability $capability,
        string $formalRecordFamilyId,
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

        $family = FormalRecordFamily::query()
            ->where('business_id', $currentBusiness->getKey())
            ->whereKey($formalRecordFamilyId)
            ->first();

        if ($family === null) {
            return null;
        }

        $familyDecision = $this->authorizeBusinessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            $capability,
            FormalRecordFamily::class,
            (string) $family->getKey(),
        );

        if (! $familyDecision->allowed) {
            return null;
        }

        $head = RecordFamilyEffectiveHead::query()
            ->where('business_id', $currentBusiness->getKey())
            ->where('formal_record_family_id', $family->getKey())
            ->first();

        if ($head === null) {
            return null;
        }

        $version = FormalRecordVersion::query()
            ->where('business_id', $currentBusiness->getKey())
            ->where('formal_record_family_id', $family->getKey())
            ->whereKey($head->formal_record_version_id)
            ->first();

        if (
            $version === null
            || $version->effective_from === null
            || $version->effective_from->isFuture()
            || (
                $version->effective_until !== null
                && ! $version->effective_until->isFuture()
            )
        ) {
            return null;
        }

        $latestTransition = RecordVersionStateTransition::query()
            ->where(
                'formal_record_version_id',
                $version->getKey(),
            )
            ->orderByDesc('sequence')
            ->first(['to_state']);

        if ($latestTransition?->to_state !== FormalRecordState::Effective) {
            return null;
        }

        $versionDecision = $this->authorizeBusinessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            $capability,
            FormalRecordVersion::class,
            (string) $version->getKey(),
        );

        return $versionDecision->allowed
            ? $version
            : null;
    }
}
