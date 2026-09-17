<?php

declare(strict_types=1);

namespace App\Application\Records;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;

final class ListRecordVersionHistory
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
    ) {}

    /**
     * @return array<FormalRecordVersion>
     */
    public function execute(
        User $user,
        Business $currentBusiness,
        Capability $capability,
        string $formalRecordFamilyId,
    ): array {
        $baseDecision = $this->authorizeBusinessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            $capability,
        );

        if (! $baseDecision->allowed) {
            return [];
        }

        $family = FormalRecordFamily::query()
            ->where('business_id', $currentBusiness->getKey())
            ->whereKey($formalRecordFamilyId)
            ->first();

        if ($family === null) {
            return [];
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
            return [];
        }

        $versions = FormalRecordVersion::query()
            ->where('business_id', $currentBusiness->getKey())
            ->where('formal_record_family_id', $family->getKey())
            ->orderBy('version_number')
            ->get();

        $authorized = [];

        foreach ($versions as $version) {
            $decision = $this->authorizeBusinessCapability->decide(
                $user,
                $currentBusiness,
                $currentBusiness,
                $capability,
                FormalRecordVersion::class,
                (string) $version->getKey(),
            );

            if ($decision->allowed) {
                $authorized[] = $version;
            }
        }

        return $authorized;
    }
}
