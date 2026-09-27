<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Continuity;

use App\Domain\Continuity\Enums\ContinuityTestResult;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ContinuityTest extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'continuity_tests';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'result' => ContinuityTestResult::class,
            'tested_at' => 'immutable_datetime',
            'next_test_date' => 'immutable_date',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
