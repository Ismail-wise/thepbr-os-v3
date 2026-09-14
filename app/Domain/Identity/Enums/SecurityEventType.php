<?php

namespace App\Domain\Identity\Enums;

enum SecurityEventType: string
{
    case AccountProvisioned = 'account.provisioned';
    case AccountStatusChanged = 'account.status_changed';
    case AccountPasswordChanged = 'account.password_changed';
}
