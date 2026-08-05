<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Laravel\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\MailNotifications\Support\DatabaseTimestamp;
use Throwable;

/**
 * Round-trips package timestamps in UTC without losing microseconds.
 *
 * @implements CastsAttributes<CarbonImmutable|null, DateTimeInterface|string|null>
 */
final readonly class UtcImmutableDateTimeCast implements CastsAttributes
{
    /**
     * Restore one database timestamp as an immutable UTC value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?CarbonImmutable {
        return $this->normalize($key, $value);
    }

    /**
     * Serialize one timestamp as an immutable UTC database value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?string {
        $timestamp = $this->normalize($key, $value);

        return $timestamp instanceof CarbonImmutable
            ? DatabaseTimestamp::format($timestamp)
            : null;
    }

    /**
     * Normalize supported model or database values.
     */
    private function normalize(string $key, mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (! is_string($value) || trim($value) === '') {
            throw $this->invalidValue($key);
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            throw $this->invalidValue($key);
        }
    }

    /**
     * Build one stable invalid-value exception.
     */
    private function invalidValue(string $key): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Mail notification timestamp attribute [%s] must be a date-time or null.',
            $key,
        ));
    }
}
