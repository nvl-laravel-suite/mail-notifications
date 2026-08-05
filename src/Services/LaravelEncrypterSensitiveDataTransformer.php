<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Encryption\Encrypter;
use Nvl\MailNotifications\Contracts\SensitiveDataTransformer;
use Nvl\MailNotifications\Exceptions\SensitiveStorageException;

/**
 * Protects sensitive storage with Laravel's current and previous encryption keys.
 */
final readonly class LaravelEncrypterSensitiveDataTransformer implements SensitiveDataTransformer
{
    /**
     * Create the Laravel encrypter-backed transformer.
     */
    public function __construct(
        private Encrypter $encrypter,
    ) {}

    /**
     * Encrypt a scope-bound serialized value with Laravel's current key.
     */
    public function transform(string $scope, string $plaintext): string
    {
        return $this->encrypter->encrypt(
            $this->scoped($scope, $plaintext),
            false,
        );
    }

    /**
     * Decrypt with Laravel's current or configured previous keys.
     */
    public function restore(string $scope, string $transformed): string
    {
        $decrypted = $this->encrypter->decrypt($transformed, false);

        if (! is_string($decrypted)) {
            throw new SensitiveStorageException(
                'Laravel restored a non-string sensitive storage payload.',
            );
        }

        $prefix = $this->scopePrefix($scope);

        if (! str_starts_with($decrypted, $prefix)) {
            throw new SensitiveStorageException(
                'Laravel restored sensitive storage for a different attribute scope.',
            );
        }

        return substr($decrypted, strlen($prefix));
    }

    /**
     * Bind plaintext to one semantic attribute scope before encryption.
     */
    private function scoped(string $scope, string $plaintext): string
    {
        return $this->scopePrefix($scope).$plaintext;
    }

    /**
     * Build the stable scope separator included inside authenticated ciphertext.
     */
    private function scopePrefix(string $scope): string
    {
        return $scope."\0";
    }
}
