<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

enum AuditActorType: string
{
    case User = 'user';
    case System = 'system';
    case ServiceIntegration = 'service_integration';
}
