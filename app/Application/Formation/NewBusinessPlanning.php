<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Application\Documents\AuthorizeDocumentAccess;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Evidence\Evidence;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class NewBusinessPlanning
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly FormationOccurrence $occurrence,
        private readonly AuthorizeDocumentAccess $documentAccess,
    ) {}

    /**
     * @param array{summary:?string,problem:?string,target_customer:?string,proposed_solution:?string} $fields
     */
    public function saveIdea(
        User $user,
        Business $business,
        int $expectedRevision,
        array $fields,
    ): ?array {
        if (! $this->canManage($user, $business)) {
            return null;
        }

        $this->assertNewBusiness($business);

        $row = $this->saveSingleton(
            'business_ideas',
            $business,
            $expectedRevision,
            $fields,
        );

        $this->occurrence->record(
            $user,
            $business,
            'formation.business_idea.saved',
            'business_idea',
            $row['id'],
            ['revision' => $row['revision']],
        );

        return $row;
    }

    public function addAssumption(
        User $user,
        Business $business,
        string $category,
        string $statement,
        string $status,
    ): ?string {
        if (! $this->canManage($user, $business)) {
            return null;
        }

        $this->assertNewBusiness($business);

        if (! in_array(
            $status,
            ['planned', 'testing', 'validated', 'invalidated'],
            true,
        )) {
            throw new InvalidArgumentException(
                'Invalid assumption status.',
            );
        }

        $id = (string) Str::uuid7();

        DB::table('formation_assumptions')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'category' => trim($category),
            'statement' => trim($statement),
            'status' => $status,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'formation.assumption.created',
            'formation_assumption',
            $id,
            ['status' => $status],
        );

        return $id;
    }

    public function addValidationActivity(
        User $user,
        Business $business,
        ?string $assumptionId,
        string $method,
        string $status,
        ?string $resultSummary,
        ?string $occurredOn,
    ): ?string {
        if (! $this->canManage($user, $business)) {
            return null;
        }

        $this->assertNewBusiness($business);

        if (! in_array(
            $status,
            ['planned', 'in_progress', 'completed'],
            true,
        )) {
            throw new InvalidArgumentException(
                'Invalid validation activity status.',
            );
        }

        if ($assumptionId !== null) {
            $exists = DB::table('formation_assumptions')
                ->where('business_id', $business->getKey())
                ->where('id', $assumptionId)
                ->exists();

            if (! $exists) {
                return null;
            }
        }

        $id = (string) Str::uuid7();

        DB::table('validation_activities')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'assumption_id' => $assumptionId,
            'method' => trim($method),
            'result_summary' => $resultSummary,
            'status' => $status,
            'occurred_on' => $occurredOn,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'formation.validation_activity.created',
            'validation_activity',
            $id,
            ['status' => $status],
        );

        return $id;
    }

    public function linkValidationEvidence(
        User $user,
        Business $business,
        string $validationActivityId,
        string $evidenceId,
    ): ?string {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::FORMATION_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $activityExists = DB::table('validation_activities')
            ->where('business_id', $business->getKey())
            ->where('id', $validationActivityId)
            ->exists();

        if (! $activityExists) {
            return null;
        }

        $evidence = Evidence::query()
            ->where('business_id', $business->getKey())
            ->whereKey($evidenceId)
            ->first();

        if ($evidence === null) {
            return null;
        }

        $documentVersion = DocumentVersion::query()
            ->where('business_id', $business->getKey())
            ->whereKey($evidence->document_version_id)
            ->first();

        if ($documentVersion === null) {
            return null;
        }

        $document = Document::query()
            ->where('business_id', $business->getKey())
            ->whereKey($documentVersion->document_id)
            ->first();

        if (
            $document === null
            || $this->documentAccess->allows(
                $user,
                $business,
                $document,
                DocumentAccessRight::Manage,
                CapabilityCatalog::RECORDS_MANAGE,
            ) === null
        ) {
            return null;
        }

        $id = (string) Str::uuid7();

        DB::table('validation_evidence_links')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'validation_activity_id' => $validationActivityId,
            'evidence_id' => $evidenceId,
            'created_by_membership_id' => $membership->getKey(),
            'created_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'formation.validation_evidence.linked',
            'validation_activity',
            $validationActivityId,
            [],
        );

        return $id;
    }

    public function addFeasibilityScenario(
        User $user,
        Business $business,
        string $name,
        string $projectedMonthlyRevenue,
        string $projectedMonthlyCost,
        ?string $notes,
    ): ?string {
        if (! $this->canManage($user, $business)) {
            return null;
        }

        $this->assertNewBusiness($business);

        $id = (string) Str::uuid7();

        DB::table('feasibility_scenarios')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'name' => trim($name),
            'projected_monthly_revenue' => $projectedMonthlyRevenue,
            'projected_monthly_cost' => $projectedMonthlyCost,
            'notes' => $notes,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'formation.feasibility_scenario.created',
            'feasibility_scenario',
            $id,
            [],
        );

        return $id;
    }

    /**
     * @param array<string, ?string> $fields
     */
    public function savePartnershipFit(
        User $user,
        Business $business,
        int $expectedRevision,
        array $fields,
    ): ?array {
        if (! $this->canManage($user, $business)) {
            return null;
        }

        $this->assertNewBusiness($business);

        $row = $this->saveSingleton(
            'partnership_fit_assessments',
            $business,
            $expectedRevision,
            $fields,
        );

        $this->occurrence->record(
            $user,
            $business,
            'formation.partnership_fit.saved',
            'partnership_fit_assessment',
            $row['id'],
            ['revision' => $row['revision']],
        );

        return $row;
    }

    public function recordDirection(
        User $user,
        Business $business,
        string $direction,
        string $rationale,
    ): ?string {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::FORMATION_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $this->assertNewBusiness($business);

        if (! in_array(
            $direction,
            ['go', 'revise', 'hold', 'no_go'],
            true,
        )) {
            throw new InvalidArgumentException(
                'Invalid formation direction.',
            );
        }

        $id = (string) Str::uuid7();

        DB::table('formation_direction_decisions')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'direction' => $direction,
            'rationale' => trim($rationale),
            'decided_by_membership_id' => $membership->getKey(),
            'decided_at' => now(),
            'created_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'formation.direction.recorded',
            'formation_direction_decision',
            $id,
            ['direction' => $direction],
        );

        return $id;
    }

    private function canManage(User $user, Business $business): bool
    {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::FORMATION_MANAGE,
        )) {
            return false;
        }

        $this->touchFormationPhase($business);

        return true;
    }

    private function assertNewBusiness(Business $business): void
    {
        if (
            $business->origin_type
            !== BusinessOriginType::StartedThroughPbr
        ) {
            throw new InvalidArgumentException(
                'This planning action belongs to the New Business journey.',
            );
        }
    }

    private function touchFormationPhase(Business $business): void
    {
        DB::table('businesses')
            ->where('id', $business->getKey())
            ->whereNull('setup_phase')
            ->update([
                'setup_phase' => 'formation',
                'updated_at' => now(),
            ]);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function saveSingleton(
        string $table,
        Business $business,
        int $expectedRevision,
        array $fields,
    ): array {
        return DB::transaction(function () use (
            $table,
            $business,
            $expectedRevision,
            $fields,
        ): array {
            $existing = DB::table($table)
                ->where('business_id', $business->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                if ($expectedRevision !== 0) {
                    throw new StaleRevision(
                        $expectedRevision,
                        0,
                    );
                }

                $id = (string) Str::uuid7();

                DB::table($table)->insert([
                    'id' => $id,
                    'business_id' => $business->getKey(),
                    ...$fields,
                    'revision' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return [
                    'id' => $id,
                    'revision' => 1,
                ];
            }

            $actual = (int) $existing->revision;

            if ($actual !== $expectedRevision) {
                throw new StaleRevision(
                    $expectedRevision,
                    $actual,
                );
            }

            DB::table($table)
                ->where('id', $existing->id)
                ->where('business_id', $business->getKey())
                ->update([
                    ...$fields,
                    'revision' => $actual + 1,
                    'updated_at' => now(),
                ]);

            return [
                'id' => (string) $existing->id,
                'revision' => $actual + 1,
            ];
        });
    }
}
