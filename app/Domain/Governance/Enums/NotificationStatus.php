<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

enum NotificationStatus: string
{
    case Unread = 'unread';
    case Read = 'read';
}
