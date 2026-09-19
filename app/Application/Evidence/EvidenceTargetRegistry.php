<?php

declare(strict_types=1);

namespace App\Application\Evidence;

use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class EvidenceTargetRegistry
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const array TARGETS = [
        'formal_record_version' => FormalRecordVersion::class,
        'proposal_version' => ProposalVersion::class,
    ];

    /**
     * @return list<string>
     */
    public function supportedTypes(): array
    {
        return array_keys(self::TARGETS);
    }

    public function resolve(
        string $targetType,
        string $targetId,
        string $businessId,
    ): ?Model {
        $modelClass = self::TARGETS[$targetType] ?? null;

        if ($modelClass === null) {
            throw new InvalidArgumentException(
                'Unsupported evidence target type.',
            );
        }

        return $modelClass::query()
            ->where('business_id', $businessId)
            ->whereKey($targetId)
            ->first();
    }
}
