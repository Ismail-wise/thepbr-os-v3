<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use App\Domain\Governance\Enums\VoteChoice;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Vote extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'votes';

    protected $fillable = [
        'business_id',
        'decision_id',
        'proposal_version_id',
        'authority_snapshot_id',
        'approval_requirement_id',
        'decision_participant_id',
        'membership_id',
        'choice',
        'rationale',
        'cast_at',
    ];

    protected function casts(): array
    {
        return [
            'choice' => VoteChoice::class,
            'cast_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
