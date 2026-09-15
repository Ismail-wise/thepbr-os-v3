<?php

namespace App\Infrastructure\Persistence\Eloquent\Members;

use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Membership extends Model
{
    use HasUuids;

    protected $table = 'memberships';

    protected $fillable = [
        'user_id',
        'business_id',
        'access_status',
    ];

    protected function casts(): array
    {
        return [
            'access_status' => MembershipAccessStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
