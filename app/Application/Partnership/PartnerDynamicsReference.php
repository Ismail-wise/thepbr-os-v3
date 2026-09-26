<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PartnerDynamicsReference
{
    private const array PROFILE_KEYS = [
        'visionary',
        'builder',
        'connector',
        'analyst',
        'operator',
        'guardian',
        'negotiator',
        'optimizer',
    ];

    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly PartnershipOccurrence $occurrence,
    ) {}

    public function record(
        User $user,
        Business $business,
        string $partnerId,
        string $sourceAssessmentId,
        ?string $sourceUrl,
        string $assessmentVersion,
        string $primaryProfile,
        ?string $secondaryProfile,
        DateTimeInterface $completedAt,
    ): ?string {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::PARTNERS_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        if (
            ! DB::table('partners')
                ->where('business_id', $business->getKey())
                ->where('id', $partnerId)
                ->exists()
        ) {
            return null;
        }

        $sourceAssessmentId = trim($sourceAssessmentId);
        $assessmentVersion = trim($assessmentVersion);
        $primaryProfile = trim($primaryProfile);
        $secondaryProfile = $secondaryProfile === null
            ? null
            : trim($secondaryProfile);

        if (
            $sourceAssessmentId === ''
            || mb_strlen($sourceAssessmentId) > 120
            || $assessmentVersion === ''
            || mb_strlen($assessmentVersion) > 80
        ) {
            throw new InvalidArgumentException(
                'PartnerDynamics source identity is invalid.',
            );
        }

        if (! in_array($primaryProfile, self::PROFILE_KEYS, true)) {
            throw new InvalidArgumentException(
                'Unknown PartnerDynamics primary profile.',
            );
        }

        if (
            $secondaryProfile !== null
            && $secondaryProfile !== ''
            && ! in_array(
                $secondaryProfile,
                self::PROFILE_KEYS,
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Unknown PartnerDynamics secondary profile.',
            );
        }

        if ($secondaryProfile === '') {
            $secondaryProfile = null;
        }

        if ($primaryProfile === $secondaryProfile) {
            throw new InvalidArgumentException(
                'Primary and secondary PartnerDynamics profiles must differ.',
            );
        }

        $sourceUrl = $sourceUrl === null
            ? null
            : trim($sourceUrl);

        if ($sourceUrl === '') {
            $sourceUrl = null;
        }

        if (
            $sourceUrl !== null
            && (
                mb_strlen($sourceUrl) > 1000
                || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false
            )
        ) {
            throw new InvalidArgumentException(
                'PartnerDynamics source URL is invalid.',
            );
        }

        $id = (string) Str::uuid7();

        DB::table(
            'partner_dynamics_assessment_references',
        )->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'partner_id' => $partnerId,
            'source_system' => 'partner_dynamics',
            'source_assessment_id' => $sourceAssessmentId,
            'source_url' => $sourceUrl,
            'assessment_version' => $assessmentVersion,
            'primary_profile' => $primaryProfile,
            'secondary_profile' => $secondaryProfile,
            'completed_at' => $completedAt,
            'referenced_by_membership_id' => $membership->getKey(),
            'created_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'partnership.partner_dynamics.referenced',
            'partner_dynamics_reference',
            $id,
            [
                'partner_id' => $partnerId,
                'primary_profile' => $primaryProfile,
                'assessment_version' => $assessmentVersion,
            ],
        );

        return $id;
    }
}
