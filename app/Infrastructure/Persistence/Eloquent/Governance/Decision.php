<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use App\Domain\Governance\Enums\DecisionOutcome;
use App\Domain\Governance\Enums\DecisionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Decision extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'decisions';

    protected $fillable = [
        'business_id',
        'proposal_version_id',
        'authority_snapshot_id',
        'decision_type',
        'decision_amount',
        'status',
        'outcome',
        'opened_by_membership_id',
        'opened_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'decision_amount' => 'decimal:2',
            'status' => DecisionStatus::class,
            'outcome' => DecisionOutcome::class,
            'opened_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
