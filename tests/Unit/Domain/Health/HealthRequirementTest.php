<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Health;

use App\Domain\Health\Enums\HealthRequirementState;
use App\Domain\Health\ValueObjects\HealthRequirement;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HealthRequirementTest extends TestCase
{
    public function test_state_contract_is_exact_and_stable(): void
    {
        self::assertSame(
            ['met', 'warning', 'blocked', 'unknown'],
            array_map(
                static fn (HealthRequirementState $state): string => $state->value,
                HealthRequirementState::cases(),
            ),
        );
    }

    public function test_requirement_serializes_only_explicit_explainability_fields(): void
    {
        $requirement = new HealthRequirement(
            key: 'governance',
            state: HealthRequirementState::Met,
            reasonCode: 'current_effective_source',
            nextActionCode: 'open_governance',
            sourceType: 'formal_record_version',
            sourceId: '01999c54-b67f-7a02-99b1-4c7fd9274282',
            sourceVersion: 3,
            sourceHash: str_repeat('a', 64),
            lastVerifiedAt: '2026-09-28T10:00:00+00:00',
            route: '/governance',
        );

        self::assertSame([
            'key' => 'governance',
            'state' => 'met',
            'reason_code' => 'current_effective_source',
            'next_action_code' => 'open_governance',
            'source' => [
                'type' => 'formal_record_version',
                'id' => '01999c54-b67f-7a02-99b1-4c7fd9274282',
                'version' => 3,
                'hash' => str_repeat('a', 64),
            ],
            'last_verified_at' => '2026-09-28T10:00:00+00:00',
            'route' => '/governance',
        ], $requirement->toArray());
    }

    public function test_source_identity_must_be_complete(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HealthRequirement(
            key: 'governance',
            state: HealthRequirementState::Unknown,
            reasonCode: 'authorized_source_unavailable',
            nextActionCode: 'open_governance',
            sourceType: 'formal_record_version',
        );
    }

    public function test_hash_must_be_sha256_when_present(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HealthRequirement(
            key: 'risk',
            state: HealthRequirementState::Met,
            reasonCode: 'current_effective_source',
            nextActionCode: 'open_risk',
            sourceType: 'formal_record_version',
            sourceId: '01999c54-b67f-7a02-99b1-4c7fd9274282',
            sourceHash: 'not-a-sha256',
        );
    }
}
