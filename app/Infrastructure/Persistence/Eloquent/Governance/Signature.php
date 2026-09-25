<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Signature extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'signatures';

    protected $fillable = [
        'business_id',
        'signature_request_id',
        'signature_participant_id',
        'membership_id',
        'document_version_id',
        'document_content_sha256',
        'signature_method',
        'consent_statement',
        'signing_session_hash',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
