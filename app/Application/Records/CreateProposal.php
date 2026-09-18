<?php

declare(strict_types=1);

namespace App\Application\Records;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Events\RecordBusinessOccurrence;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateProposal
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
        private readonly RecordBusinessOccurrence $recordBusinessOccurrence,
    ) {}

    public function execute(
        User $user,
        Business $currentBusiness,
        Capability $capability,
        string $contentHash,
    ): ?Proposal {
        $decision = $this->authorizeBusinessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            $capability,
        );
        if (! $decision->allowed) {
            return null;
        }
        $this->assertContentHash($contentHash);

        return DB::transaction(function () use (
            $user,
            $currentBusiness,
            $contentHash,
        ): Proposal {
            $proposal = Proposal::query()->create([
                'business_id' => $currentBusiness->getKey(),
                'revision' => 1,
                'content_hash' => strtolower($contentHash),
                'created_by_user_id' => $user->getKey(),
                'last_changed_by_user_id' => $user->getKey(),
            ]);

            $this->recordBusinessOccurrence->audit(
                $currentBusiness,
                AuditActor::user((string) $user->getKey()),
                'records.proposal.created',
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'proposal',
                    (string) $proposal->getKey(),
                ),
                SafeAuditMetadata::from([
                    'revision' => (int) $proposal->revision,
                ]),
                now(),
            );

            return $proposal->fresh();
        });
    }

    private function assertContentHash(string $contentHash): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/i', $contentHash) !== 1) {
            throw new InvalidArgumentException(
                'Proposal content hash must be an exact SHA-256 hexadecimal identity.',
            );
        }
    }
}
