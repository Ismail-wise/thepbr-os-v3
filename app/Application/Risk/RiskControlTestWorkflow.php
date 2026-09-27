<?php

declare(strict_types=1);

namespace App\Application\Risk;

use App\Application\Governance\GovernanceActorContext;
use App\Application\Governance\RecordGovernanceOccurrence;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Risk\Enums\RiskControlTestResult;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskControlTest;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskItem;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskProtectionRecord;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RiskControlTestWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RiskRecordVisibility $visibility,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    /** @param array<string,mixed> $payload */
    public function create(
        User $user,
        Business $business,
        array $payload,
    ): ?string {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::RISK_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $versionId = $this->currentRiskVersion($business);

        if ($versionId === null) {
            throw new InvalidArgumentException(
                'Risk Control Test requires a Current Effective Risk Register.',
            );
        }

        $ownerId = trim((string) ($payload['owner_membership_id'] ?? ''));
        $controlName = trim((string) ($payload['control_name'] ?? ''));
        $scenario = trim((string) ($payload['scenario'] ?? ''));

        if (
            $controlName === ''
            || $scenario === ''
            || ! DB::table('memberships')
                ->where('business_id', $business->getKey())
                ->where('id', $ownerId)
                ->where('access_status', 'active')
                ->exists()
        ) {
            throw new InvalidArgumentException('Risk Control Test is invalid.');
        }

        $riskId = $this->nullableText($payload['risk_item_id'] ?? null);
        $protectionId = $this->nullableText($payload['risk_protection_record_id'] ?? null);

        if ($riskId !== null) {
            $risk = RiskItem::query()
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->whereKey($riskId)
                ->first();

            if (
                $risk === null
                || ! $this->visibility->canView(
                    $user,
                    $business,
                    RiskItem::class,
                    $riskId,
                    (string) $risk->confidentiality,
                )
            ) {
                return null;
            }
        }

        if ($protectionId !== null) {
            $protection = RiskProtectionRecord::query()
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->whereKey($protectionId)
                ->first();

            if (
                $protection === null
                || ! $this->visibility->canView(
                    $user,
                    $business,
                    RiskProtectionRecord::class,
                    $protectionId,
                    (string) $protection->confidentiality,
                )
            ) {
                return null;
            }
        }

        $confidentiality = ($payload['confidentiality'] ?? 'standard') === 'restricted'
            ? 'restricted'
            : 'standard';

        return DB::transaction(function () use (
            $user,
            $business,
            $membership,
            $versionId,
            $riskId,
            $protectionId,
            $ownerId,
            $controlName,
            $scenario,
            $confidentiality,
            $payload,
        ): string {
            $test = RiskControlTest::query()->create([
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $versionId,
                'risk_item_id' => $riskId,
                'risk_protection_record_id' => $protectionId,
                'control_name' => $controlName,
                'scenario' => $scenario,
                'tested_at' => null,
                'result' => RiskControlTestResult::Planned->value,
                'gap_found' => null,
                'corrective_action' => null,
                'owner_membership_id' => $ownerId,
                'next_test_date' => $this->nullableText($payload['next_test_date'] ?? null),
                'completed_at' => null,
                'confidentiality' => $confidentiality,
            ]);

            if ($confidentiality === 'restricted') {
                $this->visibility->grantRestrictedAccess(
                    $business,
                    RiskControlTest::class,
                    (string) $test->getKey(),
                    [(string) $membership->getKey(), $ownerId],
                );
            }

            $this->occurrence->record(
                $user,
                $business,
                'risk.control_test.created',
                'risk_control_test',
                (string) $test->getKey(),
                ['result' => RiskControlTestResult::Planned->value],
                $versionId,
            );

            return (string) $test->getKey();
        });
    }

    public function recordResult(
        User $user,
        Business $business,
        string $testId,
        RiskControlTestResult $result,
        ?string $gapFound = null,
        ?string $correctiveAction = null,
    ): bool {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::RISK_MANAGE,
        ) === null) {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $testId,
            $result,
            $gapFound,
            $correctiveAction,
        ): bool {
            $test = RiskControlTest::query()
                ->where('business_id', $business->getKey())
                ->whereKey($testId)
                ->lockForUpdate()
                ->first();

            if (
                $test === null
                || $test->completed_at !== null
                || ! $this->visibility->canView(
                    $user,
                    $business,
                    RiskControlTest::class,
                    $testId,
                    (string) $test->confidentiality,
                )
            ) {
                return false;
            }

            $current = $test->result;
            $allowed = match ($current) {
                RiskControlTestResult::Planned => [
                    RiskControlTestResult::Running,
                    RiskControlTestResult::Passed,
                    RiskControlTestResult::Partial,
                    RiskControlTestResult::Failed,
                ],
                RiskControlTestResult::Running => [
                    RiskControlTestResult::Passed,
                    RiskControlTestResult::Partial,
                    RiskControlTestResult::Failed,
                ],
                default => [],
            };

            if (! in_array($result, $allowed, true)) {
                throw new InvalidArgumentException(
                    'Risk Control Test transition is invalid.',
                );
            }

            $test->result = $result;
            $test->tested_at ??= now();
            $test->gap_found = $this->nullableText($gapFound);
            $test->corrective_action = $this->nullableText($correctiveAction);

            if ($result->completed()) {
                $test->completed_at = now();
            }

            $test->save();

            $this->occurrence->record(
                $user,
                $business,
                'risk.control_test.result_recorded',
                'risk_control_test',
                $testId,
                ['result' => $result->value],
                (string) $test->formal_record_version_id,
            );

            return true;
        });
    }

    private function currentRiskVersion(Business $business): ?string
    {
        $id = DB::table('record_family_effective_heads as h')
            ->join('formal_record_versions as v', 'v.id', '=', 'h.formal_record_version_id')
            ->join('formal_record_families as f', 'f.id', '=', 'v.formal_record_family_id')
            ->where('h.business_id', $business->getKey())
            ->where('f.business_id', $business->getKey())
            ->where('f.record_type', 'risk_register')
            ->value('v.id');

        return $id === null ? null : (string) $id;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
