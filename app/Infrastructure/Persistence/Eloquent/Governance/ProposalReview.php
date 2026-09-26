<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use App\Domain\Governance\Enums\ProposalReviewOutcome;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ProposalReview extends Model
{
    use HasUuids;

    protected $table = 'proposal_reviews';

    protected $fillable = [
        'business_id',
        'proposal_version_id',
        'reviewer_membership_id',
        'created_by_membership_id',
        'status',
        'outcome',
        'notes',
        'due_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => ProposalReviewOutcome::class,
            'due_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
