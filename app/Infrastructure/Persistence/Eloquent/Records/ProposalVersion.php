<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Records;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ProposalVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'proposal_versions';

    protected $fillable = [
        'business_id',
        'proposal_id',
        'version_number',
        'proposal_revision',
        'proposal_content_hash',
        'snapshot_hash',
        'frozen_by_user_id',
        'frozen_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'proposal_revision' => 'integer',
            'frozen_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
