<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Laravel\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\MailNotifications\Support\SensitiveStorageBridge;

/**
 * Transparently round-trips one sensitive JSON array through host protection.
 *
 * @implements CastsAttributes<array<array-key, mixed>|null, mixed>
 */
final readonly class SensitiveArrayCast implements CastsAttributes
{
    /**
     * Create a cast with a stable transformer domain-separation scope.
     */
    public function __construct(
        private string $scope,
    ) {}

    /**
     * Restore a plaintext legacy array or a marked protected array.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<array-key, mixed>|null
     */
    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?array {
        return SensitiveStorageBridge::codec()->decodeArray(
            $this->scope,
            $value,
        );
    }

    /**
     * Serialize an array through the configured sensitive-storage codec.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?string {
        if ($value !== null && ! is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                'Sensitive mail notification attribute [%s] must be an array or null.',
                $key,
            ));
        }

        return SensitiveStorageBridge::codec()->encodeArray(
            $this->scope,
            $value,
        );
    }
}
