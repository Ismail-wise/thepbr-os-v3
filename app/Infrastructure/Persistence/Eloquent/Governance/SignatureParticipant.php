<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SignatureParticipant extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'signature_participants';

    protected $fillable = [
        'business_id',
        'signature_request_id',
        'decision_participant_id',
        'membership_id',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
