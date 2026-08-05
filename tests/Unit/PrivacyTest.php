<?php

declare(strict_types=1);

use Nvl\MailNotifications\Contracts\SensitiveDataRedactor;
use Nvl\MailNotifications\Exceptions\MailTrackingException;

it('normalizes sensitive-key configuration without redacting unrelated metadata', function () {
    config()->set('mail-notifications.privacy.redacted_keys', [
        ' token ',
        '',
        'PASSWORD',
        null,
        'token',
    ]);

    $redacted = app(SensitiveDataRedactor::class)->redact([
        'request_id' => 'request-123',
        'api_token' => 'secret-token',
        'nested' => [
            'PasswordHash' => 'secret-password',
            'status' => 'ready',
        ],
    ]);

    expect($redacted)->toBe([
        'request_id' => 'request-123',
        'api_token' => '[REDACTED]',
        'nested' => [
            'PasswordHash' => '[REDACTED]',
            'status' => 'ready',
        ],
    ]);
});

it('canonicalizes nested sensitive keys across common naming styles', function () {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        'apiKey' => 'camel-api-secret',
        'api-key' => 'kebab-api-secret',
        'nested' => [
            'twoFactorCode' => 'camel-two-factor-secret',
            'two-factor-code' => 'kebab-two-factor-secret',
            'verificationCode' => 'camel-verification-secret',
            'verification-code' => 'kebab-verification-secret',
            'magicLink' => 'camel-magic-link-secret',
            'magic-link' => 'kebab-magic-link-secret',
            'otp' => 'one-time-secret',
            'deliveryStatus' => 'ready',
            'request-id' => 'request-123',
        ],
    ]);

    expect($redacted)->toBe([
        'apiKey' => '[REDACTED]',
        'api-key' => '[REDACTED]',
        'nested' => [
            'twoFactorCode' => '[REDACTED]',
            'two-factor-code' => '[REDACTED]',
            'verificationCode' => '[REDACTED]',
            'verification-code' => '[REDACTED]',
            'magicLink' => '[REDACTED]',
            'magic-link' => '[REDACTED]',
            'otp' => '[REDACTED]',
            'deliveryStatus' => 'ready',
            'request-id' => 'request-123',
        ],
    ]);
});

it('fails closed when sensitive-key configuration is invalid', function () {
    config()->set('mail-notifications.privacy.redacted_keys', 'token');

    expect(fn () => app(SensitiveDataRedactor::class)->redact(['token' => 'secret']))
        ->toThrow(MailTrackingException::class, 'must be an array');
});

it('redacts non-scalar metadata values by default', function () {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        'context' => (object) [
            'token' => 'secret-token',
        ],
        'safe' => 'value',
    ]);

    expect($redacted)->toBe([
        'context' => '[REDACTED]',
        'safe' => 'value',
    ]);
});

it('bounds recursive metadata redaction depth', function () {
    config()->set('mail-notifications.privacy.max_depth', 2);

    $redacted = app(SensitiveDataRedactor::class)->redact([
        'level_one' => [
            'level_two' => [
                'level_three' => [
                    'safe' => 'value',
                ],
            ],
        ],
    ]);

    expect($redacted)->toBe([
        'level_one' => [
            'level_two' => '[REDACTED]',
        ],
    ]);
});

it('handles cyclic metadata without exhausting the worker', function () {
    config()->set('mail-notifications.privacy.max_depth', 3);
    $metadata = [];
    $metadata['self'] = &$metadata;

    $redacted = app(SensitiveDataRedactor::class)->redact($metadata);

    expect(json_encode($redacted, JSON_THROW_ON_ERROR))
        ->toBe('{"self":{"self":{"self":"[REDACTED]"}}}');
});

it('fails closed when metadata depth configuration is invalid', function (
    mixed $maximumDepth,
) {
    config()->set('mail-notifications.privacy.max_depth', $maximumDepth);

    expect(fn () => app(SensitiveDataRedactor::class)->redact([]))
        ->toThrow(MailTrackingException::class, 'depth must be an integer');
})->with([
    'zero' => 0,
    'negative' => -1,
    'numeric string' => '16',
    'unsafe maximum' => 65,
]);

it('bounds flat metadata item counts before persistence', function () {
    config()->set('mail-notifications.privacy.max_items', 2);

    expect(fn () => app(SensitiveDataRedactor::class)->redact([
        'one' => 1,
        'two' => 2,
        'three' => 3,
    ]))->toThrow(MailTrackingException::class, 'configured item limit');
});

it('bounds individual strings and aggregate metadata bytes', function () {
    config()->set('mail-notifications.privacy.max_string_bytes', 4);

    expect(fn () => app(SensitiveDataRedactor::class)->redact([
        'value' => '12345',
    ]))->toThrow(MailTrackingException::class, 'configured byte limit');

    config()->set('mail-notifications.privacy.max_string_bytes', 10);
    config()->set('mail-notifications.privacy.max_total_bytes', 10);

    expect(fn () => app(SensitiveDataRedactor::class)->redact([
        'first' => '1234',
        'second' => '5',
    ]))->toThrow(MailTrackingException::class, 'configured total byte limit');
});

it('fails closed when metadata budget configuration is invalid', function (
    string $key,
    mixed $value,
    string $expectedMessage,
) {
    config()->set("mail-notifications.privacy.{$key}", $value);

    expect(fn () => app(SensitiveDataRedactor::class)->redact([]))
        ->toThrow(MailTrackingException::class, $expectedMessage);
})->with([
    'zero items' => ['max_items', 0, 'item limit'],
    'non-integer items' => ['max_items', '100', 'item limit'],
    'oversized string limit' => [
        'max_string_bytes',
        1_048_577,
        'string byte limit',
    ],
    'oversized total limit' => [
        'max_total_bytes',
        10_485_761,
        'total byte limit',
    ],
]);
