<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Partnership\OwnershipDecisionRecordContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class OwnershipDecisionRecordWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly PartnershipOccurrence $occurrence,
        private readonly GetOwnershipDecisionRecordSource $source,
        private readonly OwnershipDecisionRecordContract $contract,
    ) {}

    /** @param array<string,mixed> $input
     * @return array{id:string,created:bool,registerVersionId:string}|null
     */
    public function create(User $user, Business $business, array $input): ?array
    {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::OWNERSHIP_MANAGE,
        );

        if ($membership === null
            || ! $this->actor->allows($user, $business, CapabilityCatalog::RECORDS_MANAGE)) {
            return null;
        }

        $normalized = $this->contract->normalize($input);
        $source = $this->source->execute($business);

        if ($source === null) {
            throw new RuntimeException(
                'A current Effective Ownership Register with its exact approved Governance source is required.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $membership,
            $normalized,
            $source,
        ): array {
            $businessId = (string) $business->getKey();
            $register = $source['register'];

            $current = DB::table('ownership_register_versions')
                ->where('id', $register->id)
                ->where('business_id', $businessId)
                ->where('status', 'effective')
                ->where('effective_from', '<=', now())
                ->where(function ($query): void {
                    $query->whereNull('effective_until')
                        ->orWhere('effective_until', '>', now());
                })
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                throw new RuntimeException('Official Ownership changed while the Decision Record was being prepared.');
            }

            $existing = DB::table('ownership_decision_records')
                ->where('business_id', $businessId)
                ->where('ownership_register_version_id', $current->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [
                    'id' => (string) $existing->id,
                    'created' => false,
                    'registerVersionId' => (string) $current->id,
                ];
            }

            $owner = Membership::query()
                ->where('business_id', $businessId)
                ->whereKey($normalized['decisionOwnerMembershipId'])
                ->where('access_status', 'active')
                ->lockForUpdate()
                ->first();

            if ($owner === null) {
                throw new InvalidArgumentException(
                    'Decision Owner must be an active member of this Business.',
                );
            }

            $id = (string) Str::uuid7();

            DB::table('ownership_decision_records')->insert([
                'id' => $id,
                'business_id' => $businessId,
                'contract_version' => OwnershipDecisionRecordContract::CONTRACT_VERSION,
                'ownership_register_version_id' => $current->id,
                'source_ownership_scenario_id' => $current->source_ownership_scenario_id,
                'proposal_version_id' => $current->proposal_version_id,
                'governance_decision_id' => $current->governance_decision_id,
                'authority_snapshot_id' => $current->authority_snapshot_id,
                'formal_record_version_id' => $source['submission']->formal_record_version_id,
                'content_hash' => $source['submission']->content_hash,
                'source_accepted_register_hash' => $current->source_accepted_register_hash,
                'decision_owner_membership_id' => $owner->getKey(),
                'review_date' => $normalized['reviewDate'],
                'decision_summary' => $normalized['decisionSummary'],
                'evidence_references' => json_encode(
                    $normalized['evidenceReferences'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
                'created_by_membership_id' => $membership->getKey(),
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'partnership.ownership.decision_record_created',
                'ownership_decision_record',
                $id,
                [
                    'ownership_register_version_id' => (string) $current->id,
                    'decision_owner_membership_id' => (string) $owner->getKey(),
                ],
            );

            return [
                'id' => $id,
                'created' => true,
                'registerVersionId' => (string) $current->id,
            ];
        });
    }
}
