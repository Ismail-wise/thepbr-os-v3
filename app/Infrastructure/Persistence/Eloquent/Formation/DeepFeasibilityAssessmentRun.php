<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Formation;

use Illuminate\Database\Eloquent\Model;

final class DeepFeasibilityAssessmentRun extends Model
{
    protected $table = 'deep_feasibility_assessment_runs';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'source_provenance' => 'array',
            'input_snapshot' => 'array',
            'result_snapshot' => 'array',
            'overall_score' => 'decimal:2',
            'created_at' => 'immutable_datetime',
        ];
    }
}
