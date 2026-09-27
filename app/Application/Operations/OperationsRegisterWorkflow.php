<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Application\Governance\GovernanceActorContext;
use App\Application\Governance\RecordGovernanceOccurrence;
use App\Application\Records\CreateAmendedDraftVersion;
use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class OperationsRegisterWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly CreateFormalRecordFamily $createFamily,
        private readonly CreateDraftRecordVersion $createDraftVersion,
        private readonly CreateAmendedDraftVersion $createAmendedDraft,
        private readonly SubmitRecordVersionForReview $submitForReview,
        private readonly TransitionFormalRecordVersion $transitionRecord,
        private readonly CreateProposal $createProposal,
        private readonly FreezeProposalVersion $freezeProposal,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array{formal_record_version_id:string,version_number:int}|null
     */
    public function createDraft(
        User $user,
        Business $business,
        array $payload,
        DateTimeInterface $effectiveFrom,
        ?DateTimeInterface $reviewDueAt = null,
    ): ?array {
        if (
            $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::OPERATIONS_MANAGE,
            ) === null
            || $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            ) === null
        ) {
            return null;
        }

        $normalized = $this->normalizePayload($business, $payload);
        $contentHash = $this->contentHash($normalized);

        return DB::transaction(function () use (
            $user,
            $business,
            $normalized,
            $contentHash,
            $effectiveFrom,
            $reviewDueAt,
        ): ?array {
            $capability = new Capability(
                CapabilityCatalog::RECORDS_MANAGE,
            );

            $family = FormalRecordFamily::query()
                ->where('business_id', $business->getKey())
                ->where('record_type', 'operations_register')
                ->where('subject_type', 'business')
                ->where('subject_id', (string) $business->getKey())
                ->lockForUpdate()
                ->first();

            if ($family === null) {
                $family = $this->createFamily->execute(
                    $user,
                    $business,
                    $capability,
                    new RecordScope(
                        'operations_register',
                        'business',
                        (string) $business->getKey(),
                    ),
                );

                if ($family === null) {
                    return null;
                }

                $version = $this->createDraftVersion->execute(
                    $user,
                    $business,
                    $capability,
                    (string) $family->getKey(),
                    $contentHash,
                    'Initial Operations Register.',
                    $effectiveFrom,
                    null,
                    $reviewDueAt,
                );
            } else {
                $head = RecordFamilyEffectiveHead::query()
                    ->where('business_id', $business->getKey())
                    ->where(
                        'formal_record_family_id',
                        $family->getKey(),
                    )
                    ->lockForUpdate()
                    ->first();

                if ($head === null) {
                    throw new RuntimeException(
                        'An existing Operations Register draft/review must finish before another version can begin.',
                    );
                }

                $version = $this->createAmendedDraft->execute(
                    $user,
                    $business,
                    $capability,
                    (string) $head->formal_record_version_id,
                    $contentHash,
                    'Operations Register amendment.',
                    $effectiveFrom,
                    null,
                    $reviewDueAt,
                );
            }

            if ($version === null) {
                return null;
            }

            $this->insertSnapshot(
                $business,
                $version,
                $normalized,
            );

            $this->occurrence->record(
                $user,
                $business,
                'operations.register.draft_created',
                'operations_register',
                (string) $version->formal_record_family_id,
                [
                    'formal_record_version_id' => (string) $version->getKey(),
                    'version_number' => (int) $version->version_number,
                ],
                (string) $version->getKey(),
            );

            return [
                'formal_record_version_id' => (string) $version->getKey(),
                'version_number' => (int) $version->version_number,
            ];
        });
    }

    /**
     * @return array{proposal_id:string,proposal_version_id:string}|null
     */
    public function submitForGovernance(
        User $user,
        Business $business,
        string $formalRecordVersionId,
        int $expectedRevision,
    ): ?array {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::OPERATIONS_MANAGE,
        ) === null) {
            return null;
        }

        $version = FormalRecordVersion::query()
            ->where('business_id', $business->getKey())
            ->whereKey($formalRecordVersionId)
            ->first();

        if ($version === null) {
            return null;
        }

        $family = FormalRecordFamily::query()
            ->where('business_id', $business->getKey())
            ->whereKey($version->formal_record_family_id)
            ->where('record_type', 'operations_register')
            ->first();

        if ($family === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $version,
            $expectedRevision,
        ): ?array {
            $capability = new Capability(
                CapabilityCatalog::RECORDS_MANAGE,
            );

            $frozen = $this->submitForReview->execute(
                $user,
                $business,
                $capability,
                (string) $version->getKey(),
                $expectedRevision,
            );

            if ($frozen === null) {
                return null;
            }

            $proposal = $this->createProposal->execute(
                $user,
                $business,
                $capability,
                (string) $frozen->content_hash,
            );

            if ($proposal === null) {
                return null;
            }

            $proposalVersion = $this->freezeProposal->execute(
                $user,
                $business,
                $capability,
                (string) $proposal->getKey(),
                1,
                [(string) $frozen->getKey()],
            );

            if ($proposalVersion === null) {
                return null;
            }

            $this->occurrence->record(
                $user,
                $business,
                'operations.register.submitted',
                'operations_register',
                (string) $frozen->formal_record_family_id,
                [
                    'formal_record_version_id' => (string) $frozen->getKey(),
                    'proposal_version_id' => (string) $proposalVersion->getKey(),
                ],
                (string) $frozen->getKey(),
            );

            return [
                'proposal_id' => (string) $proposal->getKey(),
                'proposal_version_id' => (string) $proposalVersion->getKey(),
            ];
        });
    }

    public function advanceContentReview(
        User $user,
        Business $business,
        string $formalRecordVersionId,
        FormalRecordState $target,
    ): bool {
        if (! in_array(
            $target,
            [
                FormalRecordState::UnderReview,
                FormalRecordState::Approved,
                FormalRecordState::ChangesRequested,
            ],
            true,
        )) {
            throw new InvalidArgumentException(
                'Operations Register content review target is invalid.',
            );
        }

        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::OPERATIONS_MANAGE,
        ) === null) {
            return false;
        }

        return $this->transitionRecord->execute(
            $user,
            $business,
            new Capability(CapabilityCatalog::RECORDS_MANAGE),
            $formalRecordVersionId,
            $target,
        ) !== null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizePayload(
        Business $business,
        array $payload,
    ): array {
        $organizationName = trim(
            (string) ($payload['organization_name'] ?? ''),
        );

        if ($organizationName === '') {
            throw new InvalidArgumentException(
                'Operations Register organization name is required.',
            );
        }

        $rolesInput = $payload['roles'] ?? null;

        if (! is_array($rolesInput) || $rolesInput === []) {
            throw new InvalidArgumentException(
                'Operations Register requires at least one Role.',
            );
        }

        $roles = [];
        $roleKeys = [];
        $membershipIds = [];

        foreach ($rolesInput as $rawRole) {
            if (! is_array($rawRole)) {
                throw new InvalidArgumentException(
                    'Operations Role must be structured.',
                );
            }

            $roleKey = strtolower(trim(
                (string) ($rawRole['role_key'] ?? ''),
            ));
            $name = trim((string) ($rawRole['name'] ?? ''));
            $functionName = trim(
                (string) ($rawRole['function_name'] ?? ''),
            );
            $purpose = trim((string) ($rawRole['purpose'] ?? ''));
            $responsibilities = trim(
                (string) ($rawRole['responsibilities'] ?? ''),
            );
            $reviewFrequency = trim(
                (string) ($rawRole['review_frequency'] ?? ''),
            );

            if (
                preg_match(
                    '/^[a-z0-9][a-z0-9_-]{0,95}$/',
                    $roleKey,
                ) !== 1
                || $name === ''
                || $functionName === ''
                || $purpose === ''
                || $responsibilities === ''
                || $reviewFrequency === ''
                || isset($roleKeys[$roleKey])
            ) {
                throw new InvalidArgumentException(
                    'Operations Role identity/core fields are invalid or duplicated.',
                );
            }
            $roleKeys[$roleKey] = true;

            $assignmentsInput = $rawRole['assignments'] ?? null;

            if (! is_array($assignmentsInput)) {
                throw new InvalidArgumentException(
                    'Operations Role assignments are required.',
                );
            }

            $assignments = [];
            $primaryCount = 0;
            $backupCount = 0;
            $assignedMemberships = [];

            foreach ($assignmentsInput as $rawAssignment) {
                if (! is_array($rawAssignment)) {
                    throw new InvalidArgumentException(
                        'Role Assignment must be structured.',
                    );
                }

                $membershipId = trim(
                    (string) ($rawAssignment['membership_id'] ?? ''),
                );
                $type = trim(
                    (string) ($rawAssignment['assignment_type'] ?? ''),
                );

                if (
                    $membershipId === ''
                    || ! in_array($type, ['primary', 'backup'], true)
                    || isset($assignedMemberships[$membershipId])
                ) {
                    throw new InvalidArgumentException(
                        'Role Assignment Membership/type is invalid or duplicated.',
                    );
                }

                $assignedMemberships[$membershipId] = true;
                $membershipIds[] = $membershipId;
                $primaryCount += $type === 'primary' ? 1 : 0;
                $backupCount += $type === 'backup' ? 1 : 0;

                $assignments[] = [
                    'membership_id' => $membershipId,
                    'assignment_type' => $type,
                ];
            }

            if ($primaryCount !== 1 || $backupCount > 1) {
                throw new InvalidArgumentException(
                    'Every Role requires exactly one Primary Owner and at most one distinct Backup Owner.',
                );
            }

            usort(
                $assignments,
                static fn (array $left, array $right): int => strcmp(
                    $left['assignment_type'].$left['membership_id'],
                    $right['assignment_type'].$right['membership_id'],
                ),
            );

            $roles[] = [
                'role_key' => $roleKey,
                'name' => $name,
                'function_name' => $functionName,
                'purpose' => $purpose,
                'responsibilities' => $responsibilities,
                'operational_authority' => $this->nullableText(
                    $rawRole['operational_authority'] ?? null,
                ),
                'reports_to_role_key' => $this->nullableText(
                    $rawRole['reports_to_role_key'] ?? null,
                ),
                'report_type' => $this->nullableText(
                    $rawRole['report_type'] ?? null,
                ),
                'reporting_frequency' => $this->nullableText(
                    $rawRole['reporting_frequency'] ?? null,
                ),
                'meeting_frequency' => $this->nullableText(
                    $rawRole['meeting_frequency'] ?? null,
                ),
                'review_frequency' => $reviewFrequency,
                'status' => 'active',
                'assignments' => $assignments,
            ];
        }

        foreach ($roles as $role) {
            $reportsTo = $role['reports_to_role_key'];

            if (
                $reportsTo !== null
                && (
                    ! isset($roleKeys[$reportsTo])
                    || $reportsTo === $role['role_key']
                )
            ) {
                throw new InvalidArgumentException(
                    'Role reporting line must reference a different Role in the same Register.',
                );
            }
        }

        $membershipIds = array_values(array_unique($membershipIds));
        $activeCount = Membership::query()
            ->where('business_id', $business->getKey())
            ->whereIn('id', $membershipIds)
            ->where('access_status', 'active')
            ->count();

        if ($activeCount !== count($membershipIds)) {
            throw new InvalidArgumentException(
                'Operations Role Assignment may reference only active Memberships in the current Business.',
            );
        }

        usort(
            $roles,
            static fn (array $left, array $right): int => strcmp($left['role_key'], $right['role_key']),
        );

        $raci = [];
        $raciInput = $payload['raci'] ?? [];

        if (! is_array($raciInput)) {
            throw new InvalidArgumentException(
                'Operations RACI must be structured.',
            );
        }

        foreach (array_values($raciInput) as $index => $rawItem) {
            if (! is_array($rawItem)) {
                throw new InvalidArgumentException(
                    'RACI item must be structured.',
                );
            }

            $activity = trim(
                (string) ($rawItem['activity'] ?? ''),
            );

            if ($activity === '') {
                throw new InvalidArgumentException(
                    'RACI activity is required.',
                );
            }

            $assignmentsInput = $rawItem['assignments'] ?? null;

            if (! is_array($assignmentsInput) || $assignmentsInput === []) {
                throw new InvalidArgumentException(
                    'RACI item requires assignments.',
                );
            }

            $assignments = [];
            $seen = [];
            $hasAccountable = false;
            $hasResponsible = false;

            foreach ($assignmentsInput as $rawAssignment) {
                if (! is_array($rawAssignment)) {
                    throw new InvalidArgumentException(
                        'RACI assignment must be structured.',
                    );
                }

                $roleKey = trim(
                    (string) ($rawAssignment['role_key'] ?? ''),
                );
                $responsibility = strtoupper(trim(
                    (string) ($rawAssignment['responsibility'] ?? ''),
                ));

                if (
                    ! isset($roleKeys[$roleKey])
                    || ! in_array(
                        $responsibility,
                        ['A', 'R', 'AR', 'C', 'I'],
                        true,
                    )
                    || isset($seen[$roleKey])
                ) {
                    throw new InvalidArgumentException(
                        'RACI assignment Role/responsibility is invalid or duplicated.',
                    );
                }

                $seen[$roleKey] = true;
                $hasAccountable = $hasAccountable
                    || in_array($responsibility, ['A', 'AR'], true);
                $hasResponsible = $hasResponsible
                    || in_array($responsibility, ['R', 'AR'], true);

                $assignments[] = [
                    'role_key' => $roleKey,
                    'responsibility' => $responsibility,
                ];
            }

            if (! $hasAccountable || ! $hasResponsible) {
                throw new InvalidArgumentException(
                    'Every RACI item requires Accountable and Responsible coverage.',
                );
            }

            usort(
                $assignments,
                static fn (array $left, array $right): int => strcmp($left['role_key'], $right['role_key']),
            );

            $raci[] = [
                'sequence' => $index + 1,
                'activity' => $activity,
                'result' => $this->nullableText(
                    $rawItem['result'] ?? null,
                ),
                'assignments' => $assignments,
            ];
        }

        $kpis = [];
        $kpiInput = $payload['kpis'] ?? [];

        if (! is_array($kpiInput)) {
            throw new InvalidArgumentException(
                'Operations KPI list must be structured.',
            );
        }

        foreach ($kpiInput as $rawKpi) {
            if (! is_array($rawKpi)) {
                throw new InvalidArgumentException(
                    'KPI must be structured.',
                );
            }

            $roleKey = trim(
                (string) ($rawKpi['role_key'] ?? ''),
            );
            $name = trim((string) ($rawKpi['name'] ?? ''));
            $target = trim((string) ($rawKpi['target'] ?? ''));
            $measurementMethod = trim(
                (string) ($rawKpi['measurement_method'] ?? ''),
            );
            $frequency = trim(
                (string) ($rawKpi['frequency'] ?? ''),
            );
            $status = trim(
                (string) ($rawKpi['current_status'] ?? 'not_started'),
            );

            if (
                ! isset($roleKeys[$roleKey])
                || $name === ''
                || $target === ''
                || $measurementMethod === ''
                || $frequency === ''
                || ! in_array(
                    $status,
                    [
                        'not_started',
                        'on_track',
                        'at_risk',
                        'off_track',
                        'achieved',
                    ],
                    true,
                )
            ) {
                throw new InvalidArgumentException(
                    'KPI Role/core fields/status are invalid.',
                );
            }

            $kpis[] = [
                'role_key' => $roleKey,
                'name' => $name,
                'target' => $target,
                'measurement_method' => $measurementMethod,
                'frequency' => $frequency,
                'current_status' => $status,
            ];
        }

        usort(
            $kpis,
            static fn (array $left, array $right): int => strcmp(
                $left['role_key'].'|'.$left['name'],
                $right['role_key'].'|'.$right['name'],
            ),
        );

        return [
            'organization_name' => $organizationName,
            'notes' => $this->nullableText($payload['notes'] ?? null),
            'roles' => $roles,
            'raci' => $raci,
            'kpis' => $kpis,
        ];
    }

    /**
     * @param  array<string,mixed>  $normalized
     */
    private function insertSnapshot(
        Business $business,
        FormalRecordVersion $version,
        array $normalized,
    ): void {
        $businessId = (string) $business->getKey();
        $versionId = (string) $version->getKey();

        DB::table('operations_register_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $businessId,
            'formal_record_version_id' => $versionId,
            'organization_name' => $normalized['organization_name'],
            'notes' => $normalized['notes'],
            'created_at' => now(),
        ]);

        $roleIds = [];

        foreach ($normalized['roles'] as $role) {
            $roleId = (string) Str::uuid7();
            $roleIds[$role['role_key']] = $roleId;

            DB::table('operations_roles')->insert([
                'id' => $roleId,
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                'role_key' => $role['role_key'],
                'name' => $role['name'],
                'function_name' => $role['function_name'],
                'purpose' => $role['purpose'],
                'responsibilities' => $role['responsibilities'],
                'operational_authority' => $role['operational_authority'],
                'reports_to_role_key' => $role['reports_to_role_key'],
                'report_type' => $role['report_type'],
                'reporting_frequency' => $role['reporting_frequency'],
                'meeting_frequency' => $role['meeting_frequency'],
                'review_frequency' => $role['review_frequency'],
                'status' => $role['status'],
                'created_at' => now(),
            ]);

            foreach ($role['assignments'] as $assignment) {
                DB::table('operations_role_assignments')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $businessId,
                    'formal_record_version_id' => $versionId,
                    'operations_role_id' => $roleId,
                    'membership_id' => $assignment['membership_id'],
                    'assignment_type' => $assignment['assignment_type'],
                    'created_at' => now(),
                ]);
            }
        }

        foreach ($normalized['raci'] as $item) {
            $itemId = (string) Str::uuid7();

            DB::table('operations_raci_items')->insert([
                'id' => $itemId,
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                'sequence' => $item['sequence'],
                'activity' => $item['activity'],
                'result' => $item['result'],
                'created_at' => now(),
            ]);

            foreach ($item['assignments'] as $assignment) {
                DB::table('operations_raci_assignments')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $businessId,
                    'operations_raci_item_id' => $itemId,
                    'operations_role_id' => $roleIds[$assignment['role_key']],
                    'responsibility' => $assignment['responsibility'],
                    'created_at' => now(),
                ]);
            }
        }

        foreach ($normalized['kpis'] as $kpi) {
            DB::table('operations_kpis')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                'operations_role_id' => $roleIds[$kpi['role_key']],
                'name' => $kpi['name'],
                'target' => $kpi['target'],
                'measurement_method' => $kpi['measurement_method'],
                'frequency' => $kpi['frequency'],
                'current_status' => $kpi['current_status'],
                'created_at' => now(),
            ]);
        }
    }

    /** @param array<string,mixed> $payload */
    private function contentHash(array $payload): string
    {
        return hash(
            'sha256',
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
