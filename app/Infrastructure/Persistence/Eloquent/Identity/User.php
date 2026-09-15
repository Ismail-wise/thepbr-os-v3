<?php

namespace App\Infrastructure\Persistence\Eloquent\Identity;

use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\ValueObjects\EmailAddress;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class User extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use HasUuids;

    protected $table = 'users';

    protected $fillable = [
        'email',
        'password',
        'status',
        'password_changed_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'password_changed_at' => 'immutable_datetime',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => EmailAddress::from($value)->value(),
        );
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
