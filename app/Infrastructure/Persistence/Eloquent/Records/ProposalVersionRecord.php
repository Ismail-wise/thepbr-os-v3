<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Records;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ProposalVersionRecord extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'proposal_version_records';

    protected $fillable = [
        'business_id',
        'proposal_version_id',
        'formal_record_version_id',
        'captured_content_hash',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
