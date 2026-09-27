<?php

declare(strict_types=1);

namespace App\Application\Continuity;

use App\Application\Governance\GovernanceActorContext;
use App\Application\Governance\RecordGovernanceOccurrence;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Continuity\Enums\ContinuityTestResult;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Continuity\ContinuityTest;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ContinuityTestWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    /** @param array<string,mixed> $payload */
    public function create(
        User $user,
        Business $business,
        array $payload,
    ): ?string {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONTINUITY_MANAGE,
        ) === null) {
            return null;
        }

        $versionId = $this->currentPlanVersion($business);

        if ($versionId === null) {
            throw new InvalidArgumentException(
                'Continuity Test requires a Current Effective Continuity Plan.',
            );
        }

        $ownerId = trim((string) ($payload['owner_membership_id'] ?? ''));
        $name = trim((string) ($payload['scenario_name'] ?? ''));
        $scenario = trim((string) ($payload['scenario'] ?? ''));

        if (
            $name === ''
            || $scenario === ''
            || ! DB::table('memberships')
                ->where('business_id', $business->getKey())
                ->where('id', $ownerId)
                ->where('access_status', 'active')
                ->exists()
        ) {
            throw new InvalidArgumentException('Continuity Test is invalid.');
        }

        $test = ContinuityTest::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $versionId,
            'scenario_name' => $name,
            'scenario' => $scenario,
            'tested_at' => null,
            'result' => ContinuityTestResult::Planned->value,
            'failed_items' => null,
            'improvement_actions' => null,
            'owner_membership_id' => $ownerId,
            'next_test_date' => $this->nullableText($payload['next_test_date'] ?? null),
            'completed_at' => null,
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'continuity.test.created',
            'continuity_test',
            (string) $test->getKey(),
            ['result' => ContinuityTestResult::Planned->value],
            $versionId,
        );

        return (string) $test->getKey();
    }

    public function recordResult(
        User $user,
        Business $business,
        string $testId,
        ContinuityTestResult $result,
        ?string $failedItems = null,
        ?string $improvements = null,
    ): bool {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONTINUITY_MANAGE,
        ) === null) {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $testId,
            $result,
            $failedItems,
            $improvements,
        ): bool {
            $test = ContinuityTest::query()
                ->where('business_id', $business->getKey())
                ->whereKey($testId)
                ->lockForUpdate()
                ->first();

            if ($test === null || $test->completed_at !== null) {
                return false;
            }

            $allowed = match ($test->result) {
                ContinuityTestResult::Planned => [
                    ContinuityTestResult::Running,
                    ContinuityTestResult::Passed,
                    ContinuityTestResult::Partial,
                    ContinuityTestResult::Failed,
                ],
                ContinuityTestResult::Running => [
                    ContinuityTestResult::Passed,
                    ContinuityTestResult::Partial,
                    ContinuityTestResult::Failed,
                ],
                default => [],
            };

            if (! in_array($result, $allowed, true)) {
                throw new InvalidArgumentException(
                    'Continuity Test transition is invalid.',
                );
            }

            $test->result = $result;
            $test->tested_at ??= now();
            $test->failed_items = $this->nullableText($failedItems);
            $test->improvement_actions = $this->nullableText($improvements);

            if ($result->completed()) {
                $test->completed_at = now();
            }

            $test->save();

            $this->occurrence->record(
                $user,
                $business,
                'continuity.test.result_recorded',
                'continuity_test',
                $testId,
                ['result' => $result->value],
                (string) $test->formal_record_version_id,
            );

            return true;
        });
    }

    private function currentPlanVersion(Business $business): ?string
    {
        $id = DB::table('record_family_effective_heads as h')
            ->join('formal_record_versions as v', 'v.id', '=', 'h.formal_record_version_id')
            ->join('formal_record_families as f', 'f.id', '=', 'v.formal_record_family_id')
            ->where('h.business_id', $business->getKey())
            ->where('f.business_id', $business->getKey())
            ->where('f.record_type', 'continuity_plan')
            ->value('v.id');

        return $id === null ? null : (string) $id;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
