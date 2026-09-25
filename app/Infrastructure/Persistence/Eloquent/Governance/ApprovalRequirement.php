<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ApprovalRequirement extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'approval_requirements';

    protected $fillable = [
        'business_id',
        'decision_id',
        'proposal_version_id',
        'authority_snapshot_id',
        'sequence',
        'requirement_kind',
        'required_count',
        'quorum_count',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'required_count' => 'integer',
            'quorum_count' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
