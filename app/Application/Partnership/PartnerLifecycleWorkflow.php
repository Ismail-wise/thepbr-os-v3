<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Application\PartnerChanges\RecordPartnerChangeOccurrence;
use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PartnerLifecycleWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly RecordPartnerChangeOccurrence $occurrence,
    ) {}

    public function transition(
        User $user,
        Business $business,
        string $partnerId,
        string $expectedStatus,
        string $targetStatus,
        string $reasonCode,
        ?string $sourceType = null,
        ?string $sourceId = null,
        string $requiredCapability = CapabilityCatalog::PARTNER_CHANGES_MANAGE,
    ): bool {
        $membership = $this->actor->membership(
            $user,
            $business,
            $requiredCapability,
        );

        if ($membership === null) {
            return false;
        }

        $this->assertAllowed($expectedStatus, $targetStatus);

        return DB::transaction(function () use (
            $user,
            $business,
            $partnerId,
            $expectedStatus,
            $targetStatus,
            $reasonCode,
            $sourceType,
            $sourceId,
            $membership,
        ): bool {
            $partner = DB::table('partners')
                ->where('business_id', $business->getKey())
                ->where('id', $partnerId)
                ->lockForUpdate()
                ->first(['id', 'status']);
            if ($partner === null) {
                return false;
            }

            if ((string) $partner->status !== $expectedStatus) {
                throw new InvalidArgumentException(
                    'Partner lifecycle changed before this action was applied.',
                );
            }

            DB::table('partners')
                ->where('id', $partnerId)
                ->where('business_id', $business->getKey())
                ->update([
                    'status' => $targetStatus,
                    'revision' => DB::raw('revision + 1'),
                    'updated_at' => now(),
                ]);

            DB::table('partner_lifecycle_transitions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'partner_id' => $partnerId,
                'from_status' => $expectedStatus,
                'to_status' => $targetStatus,
                'reason_code' => $reasonCode,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'actor_membership_id' => $membership->getKey(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'partner.lifecycle.transitioned',
                'partner',
                $partnerId,
                [
                    'from_status' => $expectedStatus,
                    'to_status' => $targetStatus,
                    'reason_code' => $reasonCode,
                ],
            );

            return true;
        });
    }

    private function assertAllowed(
        string $from,
        string $to,
    ): void {
        $allowed = match ($from) {
            'prospective' => ['due_diligence', 'admission_pending', 'inactive'],
            'due_diligence' => ['prospective', 'admission_pending', 'inactive'],
            'admission_pending' => ['prospective', 'active', 'inactive'],
            'active' => ['exiting', 'inactive'],
            'exiting' => ['active', 'former'],
            'inactive' => ['prospective', 'active'],
            'former' => [],
            default => [],
        };

        if (! in_array($to, $allowed, true)) {
            throw new InvalidArgumentException(
                "Partner lifecycle transition {$from} -> {$to} is not allowed.",
            );
        }
    }
}
