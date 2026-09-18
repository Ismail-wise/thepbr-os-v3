<?php

declare(strict_types=1);

namespace App\Domain\Audit\ValueObjects;

use App\Domain\Audit\Enums\AuditActorType;
use InvalidArgumentException;

final readonly class AuditActor
{
    private function __construct(
        public AuditActorType $type,
        public string $identifier,
    ) {
        if (
            $identifier === ''
            || strlen($identifier) > 191
            || preg_match('/\A[a-zA-Z0-9._:@-]+\z/', $identifier) !== 1
        ) {
            throw new InvalidArgumentException(
                'Audit actor identifier must be a stable internal identifier.',
            );
        }
    }

    public static function user(string $userId): self
    {
        return new self(AuditActorType::User, $userId);
    }

    public static function system(string $identifier = 'thepbr-os'): self
    {
        return new self(AuditActorType::System, $identifier);
    }

    public static function serviceIntegration(string $identifier): self
    {
        return new self(AuditActorType::ServiceIntegration, $identifier);
    }
}
