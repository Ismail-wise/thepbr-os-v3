<?php

namespace App\Providers;

use App\Application\AI\PbrAiProvider;
use App\Domain\Identity\ValueObjects\EmailAddress;
use App\Infrastructure\AI\DisabledPbrAiProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            PbrAiProvider::class,
            DisabledPbrAiProvider::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $email = $request->input('email');
            $rawEmail = is_string($email) ? $email : '';

            try {
                $normalizedEmail = EmailAddress::from($rawEmail)->value();
            } catch (InvalidArgumentException) {
                $normalizedEmail = strtolower(trim($rawEmail));
            }

            $clientAddress = (string) ($request->ip() ?? '');

            return Limit::perMinute(5)->by(
                hash('sha256', $normalizedEmail.'|'.$clientAddress),
            );
        });
    }
}
