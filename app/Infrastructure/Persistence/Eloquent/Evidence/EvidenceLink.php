<?php

namespace App\Infrastructure\Persistence\Eloquent\Evidence;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EvidenceLink extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'evidence_links';

    protected $fillable = [
        'business_id',
        'evidence_id',
        'target_type',
        'target_id',
        'created_by_membership_id',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
    }

    public function createdByMembership(): BelongsTo
    {
        return $this->belongsTo(
            Membership::class,
            'created_by_membership_id',
        );
    }
}
