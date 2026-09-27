<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Facades\DB;

final class ResolveFinanceControl
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
    ) {}

    /** @return array{formal_record_version_id:string,header:object}|null */
    public function currentPolicy(Business $business): ?array
    {
        $head = DB::table('record_family_effective_heads as h')
            ->join('formal_record_versions as v', function ($join): void {
                $join->on('v.id', '=', 'h.formal_record_version_id')
                    ->on('v.business_id', '=', 'h.business_id');
            })
            ->join('formal_record_families as f', function ($join): void {
                $join->on('f.id', '=', 'v.formal_record_family_id')
                    ->on('f.business_id', '=', 'v.business_id');
            })
            ->where('h.business_id', $business->getKey())
            ->where('f.record_type', 'finance_policy')
            ->first([
                'v.id',
                'v.version_number',
                'v.effective_from',
                'v.content_hash',
            ]);

        if ($head === null) {
            return null;
        }

        $header = DB::table('finance_policy_versions')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $head->id)
            ->first();

        if ($header === null) {
            return null;
        }

        return [
            'formal_record_version_id' => (string) $head->id,
            'header' => $header,
        ];
    }

    public function paymentRule(
        Business $business,
        string $transactionType,
        int $amountMinorUnits,
    ): ?object {
        $policy = $this->currentPolicy($business);

        if ($policy === null || $amountMinorUnits < 0) {
            return null;
        }

        $rules = DB::table('finance_payment_authority_rules')
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $policy['formal_record_version_id'],
            )
            ->where('transaction_type', $transactionType)
            ->where(function ($query) use ($amountMinorUnits): void {
                $query->whereNull('amount_min_minor_units')
                    ->orWhere('amount_min_minor_units', '<=', $amountMinorUnits);
            })
            ->where(function ($query) use ($amountMinorUnits): void {
                $query->whereNull('amount_max_minor_units')
                    ->orWhere('amount_max_minor_units', '>=', $amountMinorUnits);
            })
            ->orderBy('sequence')
            ->get();

        return $rules->count() === 1 ? $rules->first() : null;
    }

    public function verifierMembership(
        User $user,
        Business $business,
        string $financePolicyVersionId,
    ): ?Membership {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::FINANCE_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $header = DB::table('finance_policy_versions')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $financePolicyVersionId)
            ->first();

        if ($header === null) {
            return null;
        }

        $membershipId = (string) $membership->getKey();

        return in_array(
            $membershipId,
            [
                (string) $header->finance_owner_membership_id,
                (string) $header->control_owner_membership_id,
            ],
            true,
        ) ? $membership : null;
    }

    public function payerMembership(
        User $user,
        Business $business,
        string $financePolicyVersionId,
        string $bankAccountReferenceId,
        string $requiredAccessLevel,
        int $amountMinorUnits,
    ): ?Membership {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::FINANCE_PAY,
        );

        if ($membership === null) {
            return null;
        }

        $access = DB::table('finance_bank_access_assignments')
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $financePolicyVersionId,
            )
            ->where('bank_account_reference_id', $bankAccountReferenceId)
            ->where('membership_id', $membership->getKey())
            ->where('access_level', $requiredAccessLevel)
            ->where('status', 'active')
            ->first();

        if (
            $access === null
            || (
                $access->payment_limit_minor_units !== null
                && (int) $access->payment_limit_minor_units < $amountMinorUnits
            )
        ) {
            return null;
        }

        return $membership;
    }
}
