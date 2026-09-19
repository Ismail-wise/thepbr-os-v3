<?php

namespace App\Domain\Evidence\Enums;

enum EvidenceConfidentiality: string
{
    case Standard = 'standard';
    case Restricted = 'restricted';
}
