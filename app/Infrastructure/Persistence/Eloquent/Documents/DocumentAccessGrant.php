<?php

namespace App\Infrastructure\Persistence\Eloquent\Documents;

use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DocumentAccessGrant extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'document_access_grants';

    protected $fillable = [
        'business_id',
        'membership_id',
        'document_id',
        'right',
        'effect',
    ];

    protected function casts(): array
    {
        return [
            'right' => DocumentAccessRight::class,
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
