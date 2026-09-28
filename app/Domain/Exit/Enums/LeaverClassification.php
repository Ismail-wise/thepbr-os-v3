<?php

declare(strict_types=1);

namespace App\Domain\Exit\Enums;

enum LeaverClassification: string
{
    case Good = 'good';
    case Bad = 'bad';
    case Neutral = 'neutral';
    case NotApplicable = 'not_applicable';
    case Other = 'other';
}
