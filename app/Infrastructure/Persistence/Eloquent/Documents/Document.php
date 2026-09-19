<?php

namespace App\Infrastructure\Persistence\Eloquent\Documents;

use App\Domain\Documents\Enums\DocumentCategory;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Document extends Model
{
    use HasUuids;

    protected $table = 'documents';

    protected $fillable = [
        'business_id',
        'title',
        'category',
        'created_by_membership_id',
    ];

    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function createdByMembership(): BelongsTo
    {
        return $this->belongsTo(
            Membership::class,
            'created_by_membership_id',
        );
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(DocumentAccessGrant::class);
    }
}
