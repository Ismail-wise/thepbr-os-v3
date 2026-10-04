<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\PartnerDynamics;

use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PartnerDynamicsAssessment extends Model
{
    use HasUuids;

    protected $table = 'partner_dynamics_personal_assessments';

    protected $fillable = [
        'user_id',
        'assessment_version',
        'status',
        'answers',
        'dimension_scores',
        'behaviour_profile_scores',
        'scenario_scores',
        'scenario_counts',
        'profile_scores',
        'primary_profile',
        'primary_score',
        'secondary_profile',
        'secondary_score',
        'is_blended',
        'result_confidence',
        'consistency_data',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'dimension_scores' => 'array',
            'behaviour_profile_scores' => 'array',
            'scenario_scores' => 'array',
            'scenario_counts' => 'array',
            'profile_scores' => 'array',
            'primary_score' => 'decimal:2',
            'secondary_score' => 'decimal:2',
            'is_blended' => 'boolean',
            'consistency_data' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
