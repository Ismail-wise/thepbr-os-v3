<?php

declare(strict_types=1);

namespace App\Domain\Events\ValueObjects;

use InvalidArgumentException;

final readonly class SafeBusinessEventPayload
{
    private const int MAX_KEYS = 20;

    private const int MAX_STRING_LENGTH = 256;

    /**
     * @var array<string, bool|float|int|string|null>
     */
    private array $values;

    /**
     * @param  array<string, mixed>  $values
     */
    private function __construct(array $values)
    {
        if (count($values) > self::MAX_KEYS) {
            throw new InvalidArgumentException(
                'Business-event payload exceeds the bounded key limit.',
            );
        }

        $safe = [];

        foreach ($values as $key => $value) {
            if (
                ! is_string($key)
                || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $key) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Business-event payload keys must use the safe identifier format.',
                );
            }

            if (preg_match(
                '/(?:password|token|secret|credential|authorization|cookie|session|otp|pin)/i',
                $key,
            ) === 1) {
                throw new InvalidArgumentException(
                    'Sensitive business-event payload keys are forbidden.',
                );
            }

            if (
                ! is_null($value)
                && ! is_bool($value)
                && ! is_int($value)
                && ! is_float($value)
                && ! is_string($value)
            ) {
                throw new InvalidArgumentException(
                    'Business-event payload values must be scalar or null.',
                );
            }

            if (
                is_string($value)
                && strlen($value) > self::MAX_STRING_LENGTH
            ) {
                throw new InvalidArgumentException(
                    'Business-event payload string value exceeds the safe limit.',
                );
            }

            $safe[$key] = $value;
        }

        $this->values = $safe;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function from(array $values = []): self
    {
        return new self($values);
    }

    /**
     * @return array<string, bool|float|int|string|null>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
