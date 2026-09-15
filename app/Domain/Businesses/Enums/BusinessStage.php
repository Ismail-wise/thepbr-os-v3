<?php

namespace App\Domain\Businesses\Enums;

enum BusinessStage: string
{
    case Idea = 'idea';
    case Validation = 'validation';
    case Planning = 'planning';
    case PreLaunch = 'pre_launch';
    case Operating = 'operating';
    case Growth = 'growth';
    case Restructuring = 'restructuring';
    case Exit = 'exit';
}
