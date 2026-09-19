<?php

namespace App\Infrastructure\Persistence\Eloquent\Evidence;

use App\Domain\Evidence\Enums\EvidenceConfidentiality;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Evidence extends Model
{
    use HasUuids;

    protected $table = 'evidence';

    protected $fillable = [
        'business_id',
        'document_version_id',
        'confidentiality',
        'source_date',
        'submitted_by_membership_id',
        'verified_at',
        'verified_by_membership_id',
        'verification_method',
        'verification_note',
    ];

    protected function casts(): array
    {
        return [
            'confidentiality' => EvidenceConfidentiality::class,
            'source_date' => 'immutable_date',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    public function submittedByMembership(): BelongsTo
    {
        return $this->belongsTo(
            Membership::class,
            'submitted_by_membership_id',
        );
    }

    public function verifiedByMembership(): BelongsTo
    {
        return $this->belongsTo(
            Membership::class,
            'verified_by_membership_id',
        );
    }

    public function links(): HasMany
    {
        return $this->hasMany(EvidenceLink::class);
    }
}
