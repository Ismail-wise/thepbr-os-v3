<?php

declare(strict_types=1);

namespace App\Application\Conflict;

use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ConflictReferralWorkflow
{
    /** @var list<string> */
    private const array TYPES = [
        'exit_buyout',
        'share_transfer',
        'external_mediation',
        'arbitration',
        'court',
        'legal_counsel',
    ];

    public function __construct(
        private readonly ConflictRecordVisibility $visibility,
        private readonly ConflictCaseWorkflow $cases,
        private readonly RecordConflictOccurrence $occurrence,
    ) {}

    public function refer(
        User $user,
        Business $business,
        string $caseId,
        int $expectedCaseRevision,
        string $referralType,
        string $triggerReason,
        ?string $externalReference = null,
    ): ?string {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        $referralType = trim($referralType);
        $triggerReason = trim($triggerReason);

        if (
            ! in_array($referralType, self::TYPES, true)
            || $triggerReason === ''
        ) {
            throw new InvalidArgumentException(
                'Conflict referral type and reason are required.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedCaseRevision,
            $referralType,
            $triggerReason,
            $externalReference,
        ): ?string {
            $case = ConflictCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->first();

            if ($case === null) {
                return null;
            }

            if ((int) $case->revision !== $expectedCaseRevision) {
                throw new StaleRevision(
                    $expectedCaseRevision,
                    (int) $case->revision,
                );
            }

            if ($case->stage !== ConflictCaseStage::ExitLegal) {
                if ($this->cases->transition(
                    $user,
                    $business,
                    $caseId,
                    $expectedCaseRevision,
                    ConflictCaseStage::ExitLegal,
                    'exit_legal_referral',
                ) === null) {
                    return null;
                }
            }

            $actor = DB::table('memberships')
                ->where('business_id', $business->getKey())
                ->where('user_id', $user->getKey())
                ->where('access_status', 'active')
                ->value('id');

            if ($actor === null) {
                return null;
            }

            $id = (string) Str::uuid7();

            DB::table('conflict_exit_legal_referrals')->insert([
                'id' => $id,
                'business_id' => $business->getKey(),
                'conflict_case_id' => $caseId,
                'referral_type' => $referralType,
                'trigger_reason' => $triggerReason,
                'external_reference' => $this->nullableText(
                    $externalReference,
                ),
                'status' => 'referred',
                'referred_by_membership_id' => $actor,
                'referred_at' => now(),
                'completed_at' => null,
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'conflict.referral.created',
                $caseId,
                [
                    'referral_type' => $referralType,
                    'status' => 'referred',
                ],
            );

            return $id;
        });
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
