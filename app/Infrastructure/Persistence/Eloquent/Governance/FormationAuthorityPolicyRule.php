<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use App\Domain\Governance\Enums\DecisionMethod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class FormationAuthorityPolicyRule extends Model
{
    use HasUuids;

    protected $table = 'formation_authority_policy_rules';

    protected $fillable = [
        'business_id',
        'formal_record_version_id',
        'sequence',
        'decision_type',
        'decision_method',
        'required_approvals',
        'required_votes',
        'quorum_count',
        'signature_required',
        'reserved_matter',
        'amount_min',
        'amount_max',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'decision_method' => DecisionMethod::class,
            'required_approvals' => 'integer',
            'required_votes' => 'integer',
            'quorum_count' => 'integer',
            'signature_required' => 'boolean',
            'reserved_matter' => 'boolean',
            'amount_min' => 'decimal:2',
            'amount_max' => 'decimal:2',
        ];
    }
}
