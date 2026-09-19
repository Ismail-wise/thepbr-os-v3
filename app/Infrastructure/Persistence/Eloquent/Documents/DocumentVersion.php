<?php

namespace App\Infrastructure\Persistence\Eloquent\Documents;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DocumentVersion extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'document_versions';

    protected $fillable = [
        'business_id',
        'document_id',
        'version_number',
        'original_filename',
        'storage_key',
        'size_bytes',
        'mime_type',
        'content_sha256',
        'uploaded_by_membership_id',
        'effective_from',
        'supersedes_document_version_id',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'size_bytes' => 'integer',
            'effective_from' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploadedByMembership(): BelongsTo
    {
        return $this->belongsTo(
            Membership::class,
            'uploaded_by_membership_id',
        );
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'supersedes_document_version_id',
        );
    }
}
