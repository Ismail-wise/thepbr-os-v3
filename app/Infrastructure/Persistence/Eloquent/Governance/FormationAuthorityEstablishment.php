<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class FormationAuthorityEstablishment extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'formation_authority_establishments';

    protected $fillable = [
        'business_id',
        'formal_record_version_id',
        'established_by_membership_id',
        'establishment_hash',
        'established_at',
    ];

    protected function casts(): array
    {
        return [
            'established_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
