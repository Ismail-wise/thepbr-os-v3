<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class FormationAuthorityPolicyActor extends Model
{
    use HasUuids;

    protected $table = 'formation_authority_policy_actors';

    protected $fillable = [
        'business_id',
        'formation_authority_policy_rule_id',
        'membership_id',
        'capacity',
        'can_approve',
        'can_vote',
        'can_sign',
    ];

    protected function casts(): array
    {
        return [
            'can_approve' => 'boolean',
            'can_vote' => 'boolean',
            'can_sign' => 'boolean',
        ];
    }
}
