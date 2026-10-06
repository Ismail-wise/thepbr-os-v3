<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Partnership\ContributionSetupContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Facades\DB;

final class GetContributionSetupReadModel
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::CONTRIBUTIONS_VIEW,
        )) {
            return null;
        }

        $businessId =
            (string) $business->getKey();

        $row = DB::table(
            'contribution_setups',
        )
            ->where(
                'business_id',
                $businessId,
            )
            ->first();

        $canManage = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
        );

        $memberOptions = $canManage
            ? $this->memberOptions($business)
            : [];

        if ($row === null) {
            return [
                'contractVersion' => ContributionSetupContract::READ_MODEL_VERSION,
                'configured' => false,
                'canManage' => $canManage,
                'revision' => 0,
                'setup' => null,
                'memberOptions' => $memberOptions,
                'defaults' => [
                    'valuationDate' => now()->format('Y-m-d'),
                    'currency' => (string) $business->base_currency,
                    'periodStart' => now()->startOfYear()
                        ->format('Y-m-d'),
                    'periodEnd' => now()->endOfYear()
                        ->format('Y-m-d'),
                ],
                'semantics' => [
                    'designationCreatesAuthority' => false,
                    'currencyCreatesFxConversion' => false,
                ],
            ];
        }

        $approverIds = DB::table(
            'contribution_setup_approvers',
        )
            ->where(
                'business_id',
                $businessId,
            )
            ->where(
                'contribution_setup_id',
                $row->id,
            )
            ->orderBy('created_at')
            ->pluck('membership_id')
            ->map(
                static fn (mixed $id): string => (string) $id,
            )
            ->all();

        return [
            'contractVersion' => ContributionSetupContract::READ_MODEL_VERSION,
            'configured' => true,
            'canManage' => $canManage,
            'revision' => (int) $row->revision,
            'setup' => [
                'id' => (string) $row->id,
                'valuationDate' => (string) $row->valuation_date,
                'currency' => (string) $row->currency,
                'periodStart' => (string) $row->period_start,
                'periodEnd' => (string) $row->period_end,
                'valuationOwnerMembershipId' => (string) $row
                    ->valuation_owner_membership_id,
                'valuationOwner' => $this->membershipLabel(
                    $business,
                    (string) $row
                        ->valuation_owner_membership_id,
                ),
                'approverMembershipIds' => $approverIds,
                'approvers' => array_map(
                    fn (string $id): string => $this->membershipLabel(
                        $business,
                        $id,
                    ),
                    $approverIds,
                ),
                'updatedAt' => (string) $row->updated_at,
            ],
            'memberOptions' => $memberOptions,
            'defaults' => null,
            'semantics' => [
                'designationCreatesAuthority' => false,
                'currencyCreatesFxConversion' => false,
            ],
        ];
    }

    /**
     * @return list<array{id:string,name:string}>
     */
    private function memberOptions(
        Business $business,
    ): array {
        return Membership::query()
            ->with(['user.profile'])
            ->where(
                'business_id',
                $business->getKey(),
            )
            ->where(
                'access_status',
                'active',
            )
            ->get()
            ->map(
                fn (Membership $membership): array => [
                    'id' => (string)
                        $membership->getKey(),
                    'name' => $this->label($membership),
                ],
            )
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function membershipLabel(
        Business $business,
        string $membershipId,
    ): string {
        $membership = Membership::query()
            ->with(['user.profile'])
            ->where(
                'business_id',
                $business->getKey(),
            )
            ->whereKey($membershipId)
            ->first();

        return $membership === null
            ? 'Unavailable member'
            : $this->label($membership);
    }

    private function label(
        Membership $membership,
    ): string {
        $displayName = trim((string) (
            $membership->user?->profile
                ?->display_name ?? ''
        ));

        return $displayName !== ''
            ? $displayName
            : (string) (
                $membership->user?->email
                ?? 'Business member'
            );
    }
}
