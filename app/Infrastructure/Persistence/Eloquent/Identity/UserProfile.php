<?php

namespace App\Infrastructure\Persistence\Eloquent\Identity;

use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Identity\ValueObjects\TimezoneIdentifier;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserProfile extends Model
{
    protected $table = 'user_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'display_name',
        'language_mode',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'language_mode' => LanguageMode::class,
        ];
    }

    protected function timezone(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => TimezoneIdentifier::from($value)->value(),
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
