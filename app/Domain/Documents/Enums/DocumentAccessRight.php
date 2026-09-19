<?php

namespace App\Domain\Documents\Enums;

enum DocumentAccessRight: string
{
    case View = 'view';
    case Manage = 'manage';
}
