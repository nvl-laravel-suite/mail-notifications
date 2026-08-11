<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\MailNotifications\Contracts\SensitiveDataTransformer;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Exceptions\SensitiveStorageException;
use Nvl\MailNotifications\Exceptions\UnreadableSensitiveDataException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Services\LaravelEncrypterSensitiveDataTransformer;
use Nvl\MailNotifications\Services\MailNotificationsDoctor;
use Nvl\MailNotifications\Services\ScheduledMailProcessor;
use Nvl\MailNotifications\Services\ScheduledMailScheduler;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\MailNotifications\Services\SensitiveStorageCodec;
use Nvl\MailNotifications\Services\SensitiveStorageConfiguration;
use Nvl\MailNotifications\Tests\Fixtures\ScheduledTestFactory;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;
use Nvl\MailNotifications\ValueObjects\ScheduleMailData;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;

/**
 * Build one protected scheduled-mail request with unique business data.
 */
function protectedScheduledRequest(
    string $body,
    string $email,
): ScheduleMailData {
    return new ScheduleMailData(
        factoryAlias: 'test.scheduled',
        payloadVersion: 1,
        payload: ['body' => $body],
        recipients: new ScheduledRecipients([
            new Recipient($email, 'Sensitive Recipient'),
        ]),
        scheduledFor: CarbonImmutable::now('UTC'),
        metadata: ['business_reference' => $body],
    );
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-30 12:00:00 UTC');
    config()->set('mail-notifications.scheduling.enabled', true);
    app()->singleton(ScheduledTestFactory::class);
    app()->tag(
        ScheduledTestFactory::class,
        ScheduledMessageFactory::TAG,
    );
    app()->forgetInstance(ScheduledMessageFactoryRegistry::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('round trips protected tracking and replaced scheduled arrays without plaintext storage', function () {
    $lifecycle = app(TrackingLifecycle::class);
    $correlationId = (string) Str::uuid();
    $attempt = $lifecycle->begin(new PreparedMessage(
        correlationId: $correlationId,
        mailer: 'array',
        context: new TrackingContext(
            category: 'test.sensitive-storage',
            metadata: ['business_reference' => 'tracking-secret'],
        ),
        from: new Recipient('sender@example.test', 'Sender'),
        to: [new Recipient('tracked-recipient@example.test', 'Tracked')],
        subject: 'Scalar subject remains governed by anonymization',
    ));
    $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-sensitive-storage',
        status: MailDeliveryStatus::Delivered,
        occurredAt: CarbonImmutable::now('UTC'),
        correlationId: $correlationId,
        metadata: ['provider_context' => 'provider-secret'],
    ));

    $notification = MailNotification::query()->findOrFail($attempt->id);
    $notificationRaw = DB::table($notification->getTable())
        ->where('id', $notification->id)
        ->firstOrFail();
    $eventRaw = DB::table(MailNotificationsTables::Events)
        ->where('mail_notification_id', $notification->id)
        ->firstOrFail();

    expect($notification)
        ->to_recipients->toBe([[
            'email' => 'tracked-recipient@example.test',
            'name' => 'Tracked',
        ]])
        ->metadata->toHaveKey(
            'business_reference',
            'tracking-secret',
        )
        ->and((string) $notificationRaw->to_recipients)
        ->toContain('__nvl_mail_notifications_sensitive_01hprivacy')
        ->not->toContain('tracked-recipient@example.test')
        ->and((string) $notificationRaw->metadata)
        ->not->toContain('tracking-secret')
        ->and((string) $eventRaw->metadata)
        ->toContain('__nvl_mail_notifications_sensitive_01hprivacy')
        ->not->toContain('provider-secret');

    $scheduler = app(ScheduledMailScheduler::class);
    $message = $scheduler->schedule(protectedScheduledRequest(
        body: 'initial-sensitive-body',
        email: 'initial-recipient@example.test',
    ));
    $message = $scheduler->replacePending(
        $message->id,
        protectedScheduledRequest(
            body: 'replacement-sensitive-body',
            email: 'replacement-recipient@example.test',
        ),
    );
    $scheduledRaw = DB::table($message->getTable())
        ->where('id', $message->id)
        ->firstOrFail();

    expect($message)
        ->payload->toBe(['body' => 'replacement-sensitive-body'])
        ->to_recipients->toBe([[
            'email' => 'replacement-recipient@example.test',
            'name' => 'Sensitive Recipient',
        ]])
        ->and((string) $scheduledRaw->payload)
        ->toContain('__nvl_mail_notifications_sensitive_01hprivacy')
        ->not->toContain('replacement-sensitive-body')
        ->and((string) $scheduledRaw->to_recipients)
        ->not->toContain('replacement-recipient@example.test')
        ->and((string) $scheduledRaw->metadata)
        ->not->toContain('replacement-sensitive-body');

    expect(app(ScheduledMailProcessor::class)->process(1))->toBe(1)
        ->and($message->fresh()?->status)->toBe(ScheduledMailStatus::Sent)
        ->and($message->fresh()?->payload)
        ->toBe(['body' => 'replacement-sensitive-body']);
});

it('reads plaintext legacy arrays after protection is enabled', function () {
    $id = (string) Str::uuid();
    $now = CarbonImmutable::now('UTC');
    DB::table((new MailNotification)->getTable())->insert([
        'id' => $id,
        'correlation_id' => $id,
        'mailer' => 'array',
        'status' => MailDeliveryStatus::Failed->value,
        'message_category' => 'test.legacy-plaintext',
        'to_recipients' => json_encode([[
            'email' => 'legacy@example.test',
            'name' => null,
        ]], JSON_THROW_ON_ERROR),
        'metadata' => json_encode([
            'legacy' => true,
        ], JSON_THROW_ON_ERROR),
        'status_changed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $notification = MailNotification::query()->findOrFail($id);

    expect($notification)
        ->to_recipients->toEqual([[
            'email' => 'legacy@example.test',
            'name' => null,
        ]])
        ->metadata->toBe(['legacy' => true]);
});

it('probes a configured transformer before sensitive writes are enabled', function () {
    config()->set(
        'mail-notifications.privacy.sensitive_storage.enabled',
        false,
    );

    $check = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'privacy.sensitive_storage');

    expect($check)
        ->not->toBeNull()
        ->passed->toBeTrue()
        ->message->toContain('writes are disabled')
        ->message->toContain('ready for a later enablement');

    config()->set(
        'mail-notifications-tests.sensitive_storage.current_key',
        '',
    );
    $failedCheck = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'privacy.sensitive_storage');

    expect($failedCheck)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain('failed its readiness probe');
});

it('stores opaque binary transformer output in a JSON-safe envelope', function () {
    $transformer = new class implements SensitiveDataTransformer
    {
        public function transform(string $scope, string $plaintext): string
        {
            return "\xFF\x00".$scope."\0".$plaintext;
        }

        public function restore(string $scope, string $transformed): string
        {
            $prefix = "\xFF\x00".$scope."\0";

            if (! str_starts_with($transformed, $prefix)) {
                throw new RuntimeException(
                    'The binary transformer scope is invalid.',
                );
            }

            return substr($transformed, strlen($prefix));
        }
    };
    config()->set(
        'mail-notifications.services.sensitive_storage_transformer',
        $transformer::class,
    );
    $codec = new SensitiveStorageCodec(
        app(SensitiveStorageConfiguration::class),
        $transformer,
    );

    $codec->assertReady();
    $stored = $codec->encodeArray(
        'notification.metadata',
        ['binary' => 'round-trip'],
    );

    expect($stored)
        ->toBeString()
        ->and(json_decode($stored, true, 512, JSON_THROW_ON_ERROR))
        ->toHaveKey(
            '__nvl_mail_notifications_sensitive_01hprivacy.version',
            2,
        )
        ->toHaveKey(
            '__nvl_mail_notifications_sensitive_01hprivacy.encoding',
            'base64',
        )
        ->and($codec->decodeArray('notification.metadata', $stored))
        ->toBe(['binary' => 'round-trip']);

    app()->instance(SensitiveStorageCodec::class, $codec);
    $check = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'privacy.sensitive_storage');

    expect($check)
        ->not->toBeNull()
        ->passed->toBeTrue()
        ->message->toContain('round-trip readiness probe');
});

it('continues to read version one protected envelopes', function () {
    $scope = 'notification.metadata';
    $value = ['legacy_protected' => true];
    $plaintext = json_encode($value, JSON_THROW_ON_ERROR);
    $transformer = app(SensitiveDataTransformer::class);
    $transformed = $transformer->transform($scope, $plaintext);
    $stored = json_encode([
        '__nvl_mail_notifications_sensitive_01hprivacy' => [
            'version' => 1,
            'payload' => $transformed,
        ],
    ], JSON_THROW_ON_ERROR);

    expect(app(SensitiveStorageCodec::class)->decodeArray(
        $scope,
        $stored,
    ))->toBe($value);
});

it('requires previous transformer keys and never returns an unreadable envelope', function () {
    $notification = MailNotification::query()->create([
        'correlation_id' => (string) Str::uuid(),
        'mailer' => 'array',
        'status' => MailDeliveryStatus::Failed,
        'message_category' => 'test.key-rotation',
        'to_recipients' => [[
            'email' => 'rotation@example.test',
            'name' => null,
        ]],
        'metadata' => ['protected_with' => 'key-v1'],
        'status_changed_at' => CarbonImmutable::now('UTC'),
    ]);

    config()->set(
        'mail-notifications-tests.sensitive_storage.current_key',
        'key-v2',
    );
    config()->set(
        'mail-notifications-tests.sensitive_storage.previous_keys',
        ['key-v1'],
    );

    expect($notification->fresh()?->metadata)
        ->toBe(['protected_with' => 'key-v1']);

    config()->set(
        'mail-notifications-tests.sensitive_storage.previous_keys',
        [],
    );

    expect(
        fn (): mixed => $notification->fresh()?->metadata,
    )->toThrow(
        UnreadableSensitiveDataException::class,
        'cannot be restored',
    );

    config()->set(
        'mail-notifications.privacy.sensitive_storage.enabled',
        false,
    );

    expect(
        fn (): mixed => $notification->fresh()?->metadata,
    )->toThrow(
        UnreadableSensitiveDataException::class,
        'protected but sensitive storage is disabled',
    );
});

it('rejects malformed protected storage without exposing it as plaintext', function () {
    $notification = MailNotification::query()->create([
        'correlation_id' => (string) Str::uuid(),
        'mailer' => 'array',
        'status' => MailDeliveryStatus::Failed,
        'message_category' => 'test.malformed-envelope',
        'to_recipients' => [],
        'metadata' => ['initially' => 'valid'],
        'status_changed_at' => CarbonImmutable::now('UTC'),
    ]);
    DB::table($notification->getTable())
        ->where('id', $notification->id)
        ->update([
            'metadata' => json_encode([
                '__nvl_mail_notifications_sensitive_01hprivacy' => [
                    'version' => 1,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

    expect(
        fn (): mixed => $notification->fresh()?->metadata,
    )->toThrow(
        UnreadableSensitiveDataException::class,
        'malformed protection envelope',
    );
});

it('rejects protected storage above the configured byte boundary', function () {
    $notification = MailNotification::query()->create([
        'correlation_id' => (string) Str::uuid(),
        'mailer' => 'array',
        'status' => MailDeliveryStatus::Failed,
        'message_category' => 'test.oversized-envelope',
        'to_recipients' => [],
        'metadata' => ['initially' => 'valid'],
        'status_changed_at' => CarbonImmutable::now('UTC'),
    ]);
    DB::table($notification->getTable())
        ->where('id', $notification->id)
        ->update([
            'metadata' => json_encode([
                '__nvl_mail_notifications_sensitive_01hprivacy' => [
                    'version' => 1,
                    'payload' => '12345',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
    config()->set(
        'mail-notifications.privacy.sensitive_storage.max_transformed_bytes',
        4,
    );

    expect(
        fn (): mixed => $notification->fresh()?->metadata,
    )->toThrow(
        UnreadableSensitiveDataException::class,
        'exceeds the configured transformed byte limit',
    );
});

it('fails closed for invalid plaintext values and semantic scopes', function () {
    config()->set(
        'mail-notifications.privacy.sensitive_storage.enabled',
        false,
    );
    $codec = new SensitiveStorageCodec(
        app(SensitiveStorageConfiguration::class),
        null,
    );
    $recursive = [];
    $recursive['self'] = &$recursive;

    expect($codec->encodeArray('notification.metadata', null))->toBeNull()
        ->and($codec->decodeArray('notification.metadata', null))->toBeNull()
        ->and($codec->decodeArray(
            'notification.metadata',
            ['plain' => true],
        ))->toBe(['plain' => true])
        ->and(fn (): ?string => $codec->encodeArray('', []))
        ->toThrow(SensitiveStorageException::class, 'scope cannot be empty')
        ->and(fn (): ?array => $codec->decodeArray('', []))
        ->toThrow(SensitiveStorageException::class, 'scope cannot be empty')
        ->and(fn (): ?string => $codec->encodeArray(
            'notification.metadata',
            [
                '__nvl_mail_notifications_sensitive_01hprivacy' => [],
            ],
        ))->toThrow(
            SensitiveStorageException::class,
            'reserved storage envelope key',
        )
        ->and(fn (): ?string => $codec->encodeArray(
            'notification.metadata',
            $recursive,
        ))->toThrow(
            SensitiveStorageException::class,
            'must be JSON serializable',
        )
        ->and(fn (): ?array => $codec->decodeArray(
            'notification.metadata',
            123,
        ))->toThrow(
            UnreadableSensitiveDataException::class,
            'is not valid JSON',
        )
        ->and(fn (): ?array => $codec->decodeArray(
            'notification.metadata',
            '{',
        ))->toThrow(
            UnreadableSensitiveDataException::class,
            'is not valid JSON',
        )
        ->and(fn (): ?array => $codec->decodeArray(
            'notification.metadata',
            '"scalar"',
        ))->toThrow(
            UnreadableSensitiveDataException::class,
            'is not a JSON array or object',
        );
});

it('requires an instantiated transformer for enabled writes and readiness', function () {
    $codec = new SensitiveStorageCodec(
        app(SensitiveStorageConfiguration::class),
        null,
    );

    expect(fn (): ?string => $codec->encodeArray(
        'notification.metadata',
        ['private' => true],
    ))->toThrow(
        SensitiveStorageException::class,
        'has no transformer instance',
    )->and(fn (): mixed => $codec->assertReady())
        ->toThrow(
            SensitiveStorageException::class,
            'has no transformer instance',
        );
});

it('wraps transformer failures without exposing protected values', function () {
    $transformer = new class implements SensitiveDataTransformer
    {
        public function transform(string $scope, string $plaintext): string
        {
            throw new RuntimeException('transform failed');
        }

        public function restore(string $scope, string $transformed): string
        {
            throw new RuntimeException('restore failed');
        }
    };
    config()->set(
        'mail-notifications.services.sensitive_storage_transformer',
        $transformer::class,
    );
    $codec = new SensitiveStorageCodec(
        app(SensitiveStorageConfiguration::class),
        $transformer,
    );
    $stored = [
        '__nvl_mail_notifications_sensitive_01hprivacy' => [
            'version' => 2,
            'encoding' => 'base64',
            'payload' => base64_encode('opaque'),
        ],
    ];

    expect(fn (): ?string => $codec->encodeArray(
        'notification.metadata',
        ['private' => true],
    ))->toThrow(
        SensitiveStorageException::class,
        'could not protect a value',
    )->and(fn (): mixed => $codec->assertReady())
        ->toThrow(
            SensitiveStorageException::class,
            'failed its readiness probe',
        )
        ->and(fn (): ?array => $codec->decodeArray(
            'notification.metadata',
            $stored,
        ))->toThrow(
            UnreadableSensitiveDataException::class,
            'cannot be restored',
        );
});

it('rejects non-array restores and readiness round-trip drift', function () {
    $transformer = new class implements SensitiveDataTransformer
    {
        public function transform(string $scope, string $plaintext): string
        {
            return 'opaque';
        }

        public function restore(string $scope, string $transformed): string
        {
            return '"scalar"';
        }
    };
    config()->set(
        'mail-notifications.services.sensitive_storage_transformer',
        $transformer::class,
    );
    $codec = new SensitiveStorageCodec(
        app(SensitiveStorageConfiguration::class),
        $transformer,
    );
    $stored = [
        '__nvl_mail_notifications_sensitive_01hprivacy' => [
            'version' => 2,
            'encoding' => 'base64',
            'payload' => base64_encode('opaque'),
        ],
    ];

    expect(fn (): mixed => $codec->assertReady())->toThrow(
        SensitiveStorageException::class,
        'failed its round-trip readiness probe',
    )->and(fn (): ?array => $codec->decodeArray(
        'notification.metadata',
        $stored,
    ))->toThrow(
        UnreadableSensitiveDataException::class,
        'restored a non-array value',
    );
});

it('rejects empty and oversized transformer output before persistence', function () {
    config()->set(
        'mail-notifications.privacy.sensitive_storage.max_transformed_bytes',
        4,
    );
    $emptyTransformer = new class implements SensitiveDataTransformer
    {
        public function transform(string $scope, string $plaintext): string
        {
            return '';
        }

        public function restore(string $scope, string $transformed): string
        {
            return $transformed;
        }
    };
    $oversizedTransformer = new class implements SensitiveDataTransformer
    {
        public function transform(string $scope, string $plaintext): string
        {
            return '12345';
        }

        public function restore(string $scope, string $transformed): string
        {
            return $transformed;
        }
    };

    foreach ([$emptyTransformer, $oversizedTransformer] as $transformer) {
        config()->set(
            'mail-notifications.services.sensitive_storage_transformer',
            $transformer::class,
        );
        $codec = new SensitiveStorageCodec(
            app(SensitiveStorageConfiguration::class),
            $transformer,
        );

        expect(fn (): ?string => $codec->encodeArray(
            'notification.metadata',
            ['private' => true],
        ))->toThrow(
            SensitiveStorageException::class,
            'empty or oversized payload',
        );
    }
});

it('rejects every malformed protected envelope shape', function (
    array $stored,
    int $maximumBytes,
    string $message,
) {
    config()->set(
        'mail-notifications.privacy.sensitive_storage.max_transformed_bytes',
        $maximumBytes,
    );

    expect(fn (): ?array => app(SensitiveStorageCodec::class)->decodeArray(
        'notification.metadata',
        $stored,
    ))->toThrow(UnreadableSensitiveDataException::class, $message);
})->with([
    'additional root data' => [[
        '__nvl_mail_notifications_sensitive_01hprivacy' => [
            'version' => 1,
            'payload' => 'opaque',
        ],
        'plaintext' => true,
    ], 100, 'malformed protection envelope'],
    'non-array envelope' => [[
        '__nvl_mail_notifications_sensitive_01hprivacy' => 'opaque',
    ], 100, 'malformed protection envelope'],
    'non-integer version' => [[
        '__nvl_mail_notifications_sensitive_01hprivacy' => [
            'version' => '2',
            'payload' => 'opaque',
        ],
    ], 100, 'malformed protection envelope'],
    'legacy extra field' => [[
        '__nvl_mail_notifications_sensitive_01hprivacy' => [
            'version' => 1,
            'payload' => 'opaque',
            'encoding' => 'base64',
        ],
    ], 100, 'malformed protection envelope'],
    'legacy oversized payload' => [[
        '__nvl_mail_notifications_sensitive_01hprivacy' => [
            'version' => 1,
            'payload' => '12345',
        ],
    ], 4, 'exceeds the configured transformed byte limit'],
    'version two missing encoding' => [[
        '__nvl_mail_notifications_sensitive_01hprivacy' => [
            'version' => 2,
            'payload' => base64_encode('opaque'),
        ],
    ], 100, 'malformed protection envelope'],
    'version two wrong encoding' => [[
        '__nvl_mail_notifications_sensitive_01hprivacy' => [
            'version' => 2,
            'encoding' => 'hex',
            'payload' => base64_encode('opaque'),
        ],
    ], 100, 'malformed protection envelope'],
    'version two oversized encoded payload' => [[
        '__nvl_mail_notifications_sensitive_01hprivacy' => [
            'version' => 2,
            'encoding' => 'base64',
            'payload' => 'AAAAAAAAAAAA',
        ],
    ], 4, 'exceeds the configured transformed byte limit'],
    'version two invalid base64' => [[
        '__nvl_mail_notifications_sensitive_01hprivacy' => [
            'version' => 2,
            'encoding' => 'base64',
            'payload' => '!!!!',
        ],
    ], 100, 'malformed protection envelope'],
    'version two non-canonical base64' => [[
        '__nvl_mail_notifications_sensitive_01hprivacy' => [
            'version' => 2,
            'encoding' => 'base64',
            'payload' => 'YQ',
        ],
    ], 100, 'malformed protection envelope'],
    'unknown version' => [[
        '__nvl_mail_notifications_sensitive_01hprivacy' => [
            'version' => 3,
            'payload' => 'opaque',
        ],
    ], 100, 'malformed protection envelope'],
]);

it('inherits Laravel current and previous key rotation semantics', function () {
    $oldKey = str_repeat('o', 32);
    $newKey = str_repeat('n', 32);
    $oldTransformer = new LaravelEncrypterSensitiveDataTransformer(
        new Encrypter($oldKey, 'AES-256-CBC'),
    );
    $transformed = $oldTransformer->transform(
        'notification.metadata',
        '{"key":"value"}',
    );
    $rotatedEncrypter = new Encrypter($newKey, 'AES-256-CBC');
    $rotatedEncrypter->previousKeys([$oldKey]);
    $rotated = new LaravelEncrypterSensitiveDataTransformer(
        $rotatedEncrypter,
    );

    expect($rotated->restore(
        'notification.metadata',
        $transformed,
    ))->toBe('{"key":"value"}')
        ->and(fn (): string => $rotated->restore(
            'scheduled_message.metadata',
            $transformed,
        ))->toThrow(
            SensitiveStorageException::class,
            'different attribute scope',
        );
});
