<?php

namespace App\Application\Identity;

use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\ValueObjects\EmailAddress;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use InvalidArgumentException;
use RuntimeException;

final class AuthenticateAccount
{
    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    public function handle(string $email, string $password): bool
    {
        try {
            $canonicalEmail = EmailAddress::from($email)->value();
        } catch (InvalidArgumentException) {
            return false;
        }

        $guard = $this->auth->guard('web');

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('The web authentication guard must be stateful.');
        }

        return $guard->attempt([
            'email' => $canonicalEmail,
            'password' => $password,
            'status' => AccountStatus::Active->value,
        ], false);
    }
}
