<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use App\Domain\Governance\Enums\DecisionMethod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AuthoritySnapshot extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'authority_snapshots';

    protected $fillable = [
        'business_id',
        'proposal_version_id',
        'source_formal_record_version_id',
        'source_rule_sequence',
        'source_content_hash',
        'decision_type',
        'decision_method',
        'required_approvals',
        'required_votes',
        'quorum_count',
        'signature_required',
        'reserved_matter',
        'amount_min',
        'amount_max',
        'snapshot_hash',
        'captured_by_membership_id',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'source_rule_sequence' => 'integer',
            'decision_method' => DecisionMethod::class,
            'required_approvals' => 'integer',
            'required_votes' => 'integer',
            'quorum_count' => 'integer',
            'signature_required' => 'boolean',
            'reserved_matter' => 'boolean',
            'amount_min' => 'decimal:2',
            'amount_max' => 'decimal:2',
            'captured_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
