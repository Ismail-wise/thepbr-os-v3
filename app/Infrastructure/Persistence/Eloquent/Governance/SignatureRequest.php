<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use App\Domain\Governance\Enums\SignatureRequestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SignatureRequest extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'signature_requests';

    protected $fillable = [
        'business_id',
        'decision_id',
        'proposal_version_id',
        'authority_snapshot_id',
        'document_version_id',
        'document_content_sha256',
        'status',
        'requested_by_membership_id',
        'requested_at',
        'sent_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by_membership_id',
        'declined_at',
        'declined_by_membership_id',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SignatureRequestStatus::class,
            'requested_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
