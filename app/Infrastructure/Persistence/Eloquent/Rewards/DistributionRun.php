<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Rewards;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class DistributionRun extends Model
{
    use HasUuids;

    protected $table = 'distribution_runs';

    protected $guarded = [];
}
