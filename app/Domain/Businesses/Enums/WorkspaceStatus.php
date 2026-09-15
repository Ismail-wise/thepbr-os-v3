<?php

namespace App\Domain\Businesses\Enums;

enum WorkspaceStatus: string
{
    case Active = 'active';
    case Restricted = 'restricted';
    case Archived = 'archived';
    case Closed = 'closed';
}
