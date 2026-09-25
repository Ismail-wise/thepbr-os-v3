<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use App\Domain\Governance\Enums\ParticipantStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class DecisionParticipant extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'decision_participants';

    protected $fillable = [
        'business_id',
        'decision_id',
        'proposal_version_id',
        'authority_snapshot_id',
        'membership_id',
        'capacity',
        'can_approve',
        'can_vote',
        'can_sign',
        'status',
        'recusal_reason',
        'recused_by_membership_id',
        'recused_at',
    ];

    protected function casts(): array
    {
        return [
            'can_approve' => 'boolean',
            'can_vote' => 'boolean',
            'can_sign' => 'boolean',
            'status' => ParticipantStatus::class,
            'recused_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
