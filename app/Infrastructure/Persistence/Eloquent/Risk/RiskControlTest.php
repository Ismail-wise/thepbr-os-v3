<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Risk;

use App\Domain\Risk\Enums\RiskControlTestResult;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RiskControlTest extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'risk_control_tests';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'result' => RiskControlTestResult::class,
            'tested_at' => 'immutable_datetime',
            'next_test_date' => 'immutable_date',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
