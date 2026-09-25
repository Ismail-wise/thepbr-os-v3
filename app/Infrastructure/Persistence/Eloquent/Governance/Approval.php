<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use App\Domain\Governance\Enums\ApprovalOutcome;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Approval extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'approvals';

    protected $fillable = [
        'business_id',
        'decision_id',
        'proposal_version_id',
        'authority_snapshot_id',
        'approval_requirement_id',
        'decision_participant_id',
        'membership_id',
        'outcome',
        'rationale',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => ApprovalOutcome::class,
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
