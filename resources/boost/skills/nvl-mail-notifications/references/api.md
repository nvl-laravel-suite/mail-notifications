# Public API: nvl/mail-notifications

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\MailNotifications\Contracts\DeliveryProfileResolver`
- `Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization`
- `Nvl\MailNotifications\Contracts\MailOwnerWorklist`
- `Nvl\MailNotifications\Contracts\MailTrackable`
- `Nvl\MailNotifications\Contracts\ProviderAdapter`
- `Nvl\MailNotifications\Contracts\ProviderConfigurationValidator`
- `Nvl\MailNotifications\Contracts\ProviderMessageIdResolver`
- `Nvl\MailNotifications\Contracts\ProvidesNotifiableTypes`
- `Nvl\MailNotifications\Contracts\RemoteWebhookManager`
- `Nvl\MailNotifications\Contracts\ScheduledMailReadAuthorization`
- `Nvl\MailNotifications\Contracts\ScheduledMailSchedulerContract`
- `Nvl\MailNotifications\Contracts\ScheduledMessageFactory`
- `Nvl\MailNotifications\Contracts\SensitiveDataRedactor`
- `Nvl\MailNotifications\Contracts\SensitiveDataTransformer`
- `Nvl\MailNotifications\Contracts\TrackableMessage`
- `Nvl\MailNotifications\Contracts\TrackingLifecycle`
- `Nvl\MailNotifications\Contracts\WebhookEventNormalizer`
- `Nvl\MailNotifications\Contracts\WebhookSignatureVerifier`
- `Nvl\MailNotifications\Database\Factories\MailNotificationEventFactory`
- `Nvl\MailNotifications\Database\Factories\MailNotificationFactory`
- `Nvl\MailNotifications\Database\Factories\ScheduledMailMessageFactory`
- `Nvl\MailNotifications\Enums\MailDeliveryStatus`
- `Nvl\MailNotifications\Enums\MailNotificationReadAbility`
- `Nvl\MailNotifications\Enums\MailNotificationsResponseCode`
- `Nvl\MailNotifications\Enums\MailOwnerOperation`
- `Nvl\MailNotifications\Enums\ScheduledMailReadAbility`
- `Nvl\MailNotifications\Enums\ScheduledMailStatus`
- `Nvl\MailNotifications\Events\MailAcceptedByProvider`
- `Nvl\MailNotifications\Events\MailDeliveryStatusChanged`
- `Nvl\MailNotifications\Events\MailTrackingFailed`
- `Nvl\MailNotifications\Events\MailTrackingStarted`
- `Nvl\MailNotifications\Events\MailWebhookAcknowledged`
- `Nvl\MailNotifications\Events\ScheduledMailCancelled`
- `Nvl\MailNotifications\Events\ScheduledMailClaimed`
- `Nvl\MailNotifications\Events\ScheduledMailFailed`
- `Nvl\MailNotifications\Events\ScheduledMailRecovered`
- `Nvl\MailNotifications\Events\ScheduledMailReplaced`
- `Nvl\MailNotifications\Events\ScheduledMailRescheduled`
- `Nvl\MailNotifications\Events\ScheduledMailRetrying`
- `Nvl\MailNotifications\Events\ScheduledMailScheduled`
- `Nvl\MailNotifications\Events\ScheduledMailSent`
- `Nvl\MailNotifications\Events\WebhookEventAmbiguous`
- `Nvl\MailNotifications\Exceptions\AmbiguousDeliveryEventException`
- `Nvl\MailNotifications\Exceptions\MailDeliveryCancelled`
- `Nvl\MailNotifications\Exceptions\MailNotificationsException`
- `Nvl\MailNotifications\Exceptions\MailRetentionException`
- `Nvl\MailNotifications\Exceptions\MailTrackingException`
- `Nvl\MailNotifications\Exceptions\ScheduledMailException`
- `Nvl\MailNotifications\Exceptions\SensitiveStorageException`
- `Nvl\MailNotifications\Exceptions\UnmatchedDeliveryEventException`
- `Nvl\MailNotifications\Exceptions\UnreadableSensitiveDataException`
- `Nvl\MailNotifications\Laravel\Concerns\TracksMailDelivery`
- `Nvl\MailNotifications\Models\MailNotification`
- `Nvl\MailNotifications\Models\MailNotificationEvent`
- `Nvl\MailNotifications\Models\ScheduledMailMessage`
- `Nvl\MailNotifications\Services\DatabaseTrackingLifecycle`
- `Nvl\MailNotifications\Services\MailHistory`
- `Nvl\MailNotifications\Services\MailHistoryAnonymizer`
- `Nvl\MailNotifications\Services\MailModelRegistry`
- `Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry`
- `Nvl\MailNotifications\Services\MailRetentionPruner`
- `Nvl\MailNotifications\Services\MailTestingInterceptor`
- `Nvl\MailNotifications\Services\ProviderMessageIdRegistry`
- `Nvl\MailNotifications\Services\ProviderRegistry`
- `Nvl\MailNotifications\Services\RemoteWebhookManagerRegistry`
- `Nvl\MailNotifications\Services\ScheduledMailProcessor`
- `Nvl\MailNotifications\Services\ScheduledMailReader`
- `Nvl\MailNotifications\Services\ScheduledMailRecovery`
- `Nvl\MailNotifications\Services\ScheduledMailScheduler`
- `Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry`
- `Nvl\MailNotifications\Services\WebhookProcessor`
- `Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery`
- `Nvl\MailNotifications\ValueObjects\NotifiableReference`
- `Nvl\MailNotifications\ValueObjects\PreparedMessage`
- `Nvl\MailNotifications\ValueObjects\ProviderAcceptance`
- `Nvl\MailNotifications\ValueObjects\ProviderMessageId`
- `Nvl\MailNotifications\ValueObjects\Recipient`
- `Nvl\MailNotifications\ValueObjects\RemoteWebhookManagementResult`
- `Nvl\MailNotifications\ValueObjects\RemoteWebhookRemoveOptions`
- `Nvl\MailNotifications\ValueObjects\RemoteWebhookSyncOptions`
- `Nvl\MailNotifications\ValueObjects\ScheduleMailData`
- `Nvl\MailNotifications\ValueObjects\ScheduledMailReadQuery`
- `Nvl\MailNotifications\ValueObjects\ScheduledMessageData`
- `Nvl\MailNotifications\ValueObjects\ScheduledRecipients`
- `Nvl\MailNotifications\ValueObjects\TrackingAttempt`
- `Nvl\MailNotifications\ValueObjects\TrackingContext`
- `Nvl\MailNotifications\ValueObjects\TransitionResult`
- `Nvl\MailNotifications\ValueObjects\TransportResult`
- `Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent`
- `Nvl\MailNotifications\ValueObjects\VerifiedWebhook`
- `Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement`
- `Nvl\MailNotifications\ValueObjects\WebhookRequest`

## Nvl\MailNotifications\Contracts\DeliveryProfileResolver

```text
interface Nvl\MailNotifications\Contracts\DeliveryProfileResolver
public abstract function resolve(): ?string
```

## Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization

```text
interface Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization
public abstract function authorize(Nvl\MailNotifications\Enums\MailNotificationReadAbility $ability, Illuminate\Contracts\Auth\Authenticatable $actor, ?Nvl\MailNotifications\Models\MailNotification $notification=null): void
```

## Nvl\MailNotifications\Contracts\MailOwnerWorklist

```text
interface Nvl\MailNotifications\Contracts\MailOwnerWorklist
public abstract function owners(Nvl\MailNotifications\Enums\MailOwnerOperation $operation): iterable
@return iterable<OwnerReference>
resolved @return iterable<Nvl\Support\Ownership\ValueObjects\OwnerReference>
```

## Nvl\MailNotifications\Contracts\MailTrackable

```text
interface Nvl\MailNotifications\Contracts\MailTrackable
public abstract function mailNotificationIdentifier(): string
public abstract function mailNotificationType(): string
```

## Nvl\MailNotifications\Contracts\ProviderAdapter

```text
interface Nvl\MailNotifications\Contracts\ProviderAdapter
public const string CONTAINER_TAG="mail-notifications.provider-adapters"
public abstract function name(): string
```

## Nvl\MailNotifications\Contracts\ProviderConfigurationValidator

```text
interface Nvl\MailNotifications\Contracts\ProviderConfigurationValidator
public abstract function validateConfiguration(bool $webhooksEnabled): void
```

## Nvl\MailNotifications\Contracts\ProviderMessageIdResolver

```text
interface Nvl\MailNotifications\Contracts\ProviderMessageIdResolver
public const string TAG="mail-notifications.message-id-resolvers"
public abstract function resolve(Nvl\MailNotifications\ValueObjects\TransportResult $result): ?Nvl\MailNotifications\ValueObjects\ProviderMessageId
public abstract function supports(Nvl\MailNotifications\ValueObjects\TransportResult $result): bool
```

## Nvl\MailNotifications\Contracts\ProvidesNotifiableTypes

```text
interface Nvl\MailNotifications\Contracts\ProvidesNotifiableTypes
public const string TAG="mail-notifications.notifiable-types"
public abstract function mailNotificationNotifiableTypes(): array
@return array<string, class-string<MailTrackable>>
resolved @return array<string, class-string<Nvl\MailNotifications\Contracts\MailTrackable>>
```

## Nvl\MailNotifications\Contracts\RemoteWebhookManager

```text
interface Nvl\MailNotifications\Contracts\RemoteWebhookManager
public const string TAG="mail-notifications.remote-webhook-managers"
public abstract function enabled(): bool
public abstract function provider(): string
public abstract function remove(Nvl\MailNotifications\ValueObjects\RemoteWebhookRemoveOptions $options): Nvl\MailNotifications\ValueObjects\RemoteWebhookManagementResult
public abstract function sync(Nvl\MailNotifications\ValueObjects\RemoteWebhookSyncOptions $options): Nvl\MailNotifications\ValueObjects\RemoteWebhookManagementResult
public abstract function validateConfiguration(): void
```

## Nvl\MailNotifications\Contracts\ScheduledMailReadAuthorization

```text
interface Nvl\MailNotifications\Contracts\ScheduledMailReadAuthorization
public abstract function authorize(Nvl\MailNotifications\Enums\ScheduledMailReadAbility $ability, Illuminate\Contracts\Auth\Authenticatable $actor, ?Nvl\MailNotifications\Models\ScheduledMailMessage $message=null): void
```

## Nvl\MailNotifications\Contracts\ScheduledMailSchedulerContract

```text
interface Nvl\MailNotifications\Contracts\ScheduledMailSchedulerContract
public abstract function cancel(string $messageId): Nvl\MailNotifications\Models\ScheduledMailMessage
public abstract function replacePending(string $messageId, Nvl\MailNotifications\ValueObjects\ScheduleMailData $data): Nvl\MailNotifications\Models\ScheduledMailMessage
public abstract function reschedule(string $messageId, Carbon\CarbonImmutable $scheduledFor, ?Carbon\CarbonImmutable $availableAt=null): Nvl\MailNotifications\Models\ScheduledMailMessage
public abstract function schedule(Nvl\MailNotifications\ValueObjects\ScheduleMailData $data): Nvl\MailNotifications\Models\ScheduledMailMessage
```

## Nvl\MailNotifications\Contracts\ScheduledMessageFactory

```text
interface Nvl\MailNotifications\Contracts\ScheduledMessageFactory
public const string TAG="mail-notifications.scheduled-message-factories"
public abstract function alias(): string
public abstract function make(Nvl\MailNotifications\ValueObjects\ScheduledMessageData $message): Illuminate\Mail\Mailable
public abstract function supportsVersion(int $version): bool
public abstract function validate(int $version, array $payload): void
@param array<string, mixed> $payload
```

## Nvl\MailNotifications\Contracts\SensitiveDataRedactor

```text
interface Nvl\MailNotifications\Contracts\SensitiveDataRedactor
public abstract function redact(array $data): array
@param array<string, mixed> $data
@return array<string, mixed>
```

## Nvl\MailNotifications\Contracts\SensitiveDataTransformer

```text
interface Nvl\MailNotifications\Contracts\SensitiveDataTransformer
public abstract function restore(string $scope, string $transformed): string
public abstract function transform(string $scope, string $plaintext): string
```

## Nvl\MailNotifications\Contracts\TrackableMessage

```text
interface Nvl\MailNotifications\Contracts\TrackableMessage
public abstract function trackingContext(): Nvl\MailNotifications\ValueObjects\TrackingContext
```

## Nvl\MailNotifications\Contracts\TrackingLifecycle

```text
interface Nvl\MailNotifications\Contracts\TrackingLifecycle
public abstract function accepted(Nvl\MailNotifications\ValueObjects\TrackingAttempt $attempt, Nvl\MailNotifications\ValueObjects\ProviderAcceptance $acceptance): void
public abstract function apply(Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent $event): Nvl\MailNotifications\ValueObjects\TransitionResult
@throws AmbiguousDeliveryEventException
resolved @throws Nvl\MailNotifications\Exceptions\AmbiguousDeliveryEventException
@throws UnmatchedDeliveryEventException
resolved @throws Nvl\MailNotifications\Exceptions\UnmatchedDeliveryEventException
public abstract function begin(Nvl\MailNotifications\ValueObjects\PreparedMessage $message): Nvl\MailNotifications\ValueObjects\TrackingAttempt
public abstract function failed(Nvl\MailNotifications\ValueObjects\TrackingAttempt $attempt, Throwable $exception): void
public abstract function queuedFailure(Nvl\MailNotifications\ValueObjects\PreparedMessage $message, Throwable $exception): void
```

## Nvl\MailNotifications\Contracts\WebhookEventNormalizer

```text
interface Nvl\MailNotifications\Contracts\WebhookEventNormalizer
public abstract function normalize(Nvl\MailNotifications\ValueObjects\VerifiedWebhook $webhook): Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent|Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement
```

## Nvl\MailNotifications\Contracts\WebhookSignatureVerifier

```text
interface Nvl\MailNotifications\Contracts\WebhookSignatureVerifier
public abstract function verify(Nvl\MailNotifications\ValueObjects\WebhookRequest $request): Nvl\MailNotifications\ValueObjects\VerifiedWebhook
```

## Nvl\MailNotifications\Database\Factories\MailNotificationEventFactory

```text
final class Nvl\MailNotifications\Database\Factories\MailNotificationEventFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<MailNotificationEvent>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\MailNotifications\Models\MailNotificationEvent>
public function accepted(): static
public function bounced(): static
public function clicked(): static
public function complained(): static
public function definition(): array
@return array<model-property<MailNotificationEvent>, mixed>
resolved @return array<model-property<Nvl\MailNotifications\Models\MailNotificationEvent>, mixed>
public function delayed(): static
public function delivered(): static
public function failed(): static
public function normalizedAs(Nvl\MailNotifications\Enums\MailDeliveryStatus $status): static
public function opened(): static
public function pending(): static
public function rejected(): static
public function unsubscribed(): static
```

## Nvl\MailNotifications\Database\Factories\MailNotificationFactory

```text
final class Nvl\MailNotifications\Database\Factories\MailNotificationFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<MailNotification>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\MailNotifications\Models\MailNotification>
public function accepted(): static
public function bounced(): static
public function clicked(): static
public function complained(): static
public function definition(): array
@return array<model-property<MailNotification>, mixed>
resolved @return array<model-property<Nvl\MailNotifications\Models\MailNotification>, mixed>
public function delayed(): static
public function delivered(): static
public function failed(): static
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
public function modelName(): string
@return class-string<MailNotification>
resolved @return class-string<Nvl\MailNotifications\Models\MailNotification>
public function opened(): static
public function pending(): static
public function rejected(): static
public function unsubscribed(): static
```

## Nvl\MailNotifications\Database\Factories\ScheduledMailMessageFactory

```text
final class Nvl\MailNotifications\Database\Factories\ScheduledMailMessageFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<ScheduledMailMessage>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\MailNotifications\Models\ScheduledMailMessage>
public function cancelled(): static
public function definition(): array
@return array<model-property<ScheduledMailMessage>, mixed>
resolved @return array<model-property<Nvl\MailNotifications\Models\ScheduledMailMessage>, mixed>
public function due(): static
public function failed(): static
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
public function modelName(): string
@return class-string<ScheduledMailMessage>
resolved @return class-string<Nvl\MailNotifications\Models\ScheduledMailMessage>
public function pending(): static
public function processing(): static
public function retrying(): static
public function sent(): static
```

## Nvl\MailNotifications\Enums\MailDeliveryStatus

```text
enum Nvl\MailNotifications\Enums\MailDeliveryStatus implements BackedEnum, UnitEnum: string
public const Nvl\MailNotifications\Enums\MailDeliveryStatus Accepted=Nvl\MailNotifications\Enums\MailDeliveryStatus::Accepted="accepted"
public const Nvl\MailNotifications\Enums\MailDeliveryStatus Bounced=Nvl\MailNotifications\Enums\MailDeliveryStatus::Bounced="bounced"
public const Nvl\MailNotifications\Enums\MailDeliveryStatus Clicked=Nvl\MailNotifications\Enums\MailDeliveryStatus::Clicked="clicked"
public const Nvl\MailNotifications\Enums\MailDeliveryStatus Complained=Nvl\MailNotifications\Enums\MailDeliveryStatus::Complained="complained"
public const Nvl\MailNotifications\Enums\MailDeliveryStatus Delayed=Nvl\MailNotifications\Enums\MailDeliveryStatus::Delayed="delayed"
public const Nvl\MailNotifications\Enums\MailDeliveryStatus Delivered=Nvl\MailNotifications\Enums\MailDeliveryStatus::Delivered="delivered"
public const Nvl\MailNotifications\Enums\MailDeliveryStatus Failed=Nvl\MailNotifications\Enums\MailDeliveryStatus::Failed="failed"
public const Nvl\MailNotifications\Enums\MailDeliveryStatus Opened=Nvl\MailNotifications\Enums\MailDeliveryStatus::Opened="opened"
public const Nvl\MailNotifications\Enums\MailDeliveryStatus Pending=Nvl\MailNotifications\Enums\MailDeliveryStatus::Pending="pending"
public const Nvl\MailNotifications\Enums\MailDeliveryStatus Rejected=Nvl\MailNotifications\Enums\MailDeliveryStatus::Rejected="rejected"
public const Nvl\MailNotifications\Enums\MailDeliveryStatus Unsubscribed=Nvl\MailNotifications\Enums\MailDeliveryStatus::Unsubscribed="unsubscribed"
public function canTransitionTo(self $next): bool
public function isTerminal(): bool
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\MailNotifications\Enums\MailNotificationReadAbility

```text
enum Nvl\MailNotifications\Enums\MailNotificationReadAbility implements BackedEnum, UnitEnum: string
public const Nvl\MailNotifications\Enums\MailNotificationReadAbility List=Nvl\MailNotifications\Enums\MailNotificationReadAbility::List="list"
public const Nvl\MailNotifications\Enums\MailNotificationReadAbility Statistics=Nvl\MailNotifications\Enums\MailNotificationReadAbility::Statistics="statistics"
public const Nvl\MailNotifications\Enums\MailNotificationReadAbility Suggest=Nvl\MailNotifications\Enums\MailNotificationReadAbility::Suggest="suggest"
public const Nvl\MailNotifications\Enums\MailNotificationReadAbility View=Nvl\MailNotifications\Enums\MailNotificationReadAbility::View="view"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\MailNotifications\Enums\MailNotificationsResponseCode

```text
enum Nvl\MailNotifications\Enums\MailNotificationsResponseCode implements BackedEnum, Nvl\Support\Contracts\ResponseCode, UnitEnum: string
public const Nvl\MailNotifications\Enums\MailNotificationsResponseCode AmbiguousDeliveryEvent=Nvl\MailNotifications\Enums\MailNotificationsResponseCode::AmbiguousDeliveryEvent="ambiguous_delivery_event"
public const Nvl\MailNotifications\Enums\MailNotificationsResponseCode InvalidRetentionConfiguration=Nvl\MailNotifications\Enums\MailNotificationsResponseCode::InvalidRetentionConfiguration="invalid_retention_configuration"
public const Nvl\MailNotifications\Enums\MailNotificationsResponseCode MailDeliveryCancelled=Nvl\MailNotifications\Enums\MailNotificationsResponseCode::MailDeliveryCancelled="mail_delivery_cancelled"
public const Nvl\MailNotifications\Enums\MailNotificationsResponseCode MailTrackingFailed=Nvl\MailNotifications\Enums\MailNotificationsResponseCode::MailTrackingFailed="mail_tracking_failed"
public const Nvl\MailNotifications\Enums\MailNotificationsResponseCode OperationFailed=Nvl\MailNotifications\Enums\MailNotificationsResponseCode::OperationFailed="operation_failed"
public const Nvl\MailNotifications\Enums\MailNotificationsResponseCode ScheduledMailFailed=Nvl\MailNotifications\Enums\MailNotificationsResponseCode::ScheduledMailFailed="scheduled_mail_failed"
public const Nvl\MailNotifications\Enums\MailNotificationsResponseCode SensitiveDataUnreadable=Nvl\MailNotifications\Enums\MailNotificationsResponseCode::SensitiveDataUnreadable="sensitive_data_unreadable"
public const Nvl\MailNotifications\Enums\MailNotificationsResponseCode SensitiveStorageUnavailable=Nvl\MailNotifications\Enums\MailNotificationsResponseCode::SensitiveStorageUnavailable="sensitive_storage_unavailable"
public const Nvl\MailNotifications\Enums\MailNotificationsResponseCode UnmatchedDeliveryEvent=Nvl\MailNotifications\Enums\MailNotificationsResponseCode::UnmatchedDeliveryEvent="unmatched_delivery_event"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\MailNotifications\Enums\MailOwnerOperation

```text
enum Nvl\MailNotifications\Enums\MailOwnerOperation implements BackedEnum, UnitEnum: string
public const Nvl\MailNotifications\Enums\MailOwnerOperation Anonymize=Nvl\MailNotifications\Enums\MailOwnerOperation::Anonymize="mail.anonymize"
public const Nvl\MailNotifications\Enums\MailOwnerOperation ProcessScheduled=Nvl\MailNotifications\Enums\MailOwnerOperation::ProcessScheduled="mail.process-scheduled"
public const Nvl\MailNotifications\Enums\MailOwnerOperation Prune=Nvl\MailNotifications\Enums\MailOwnerOperation::Prune="mail.prune"
public const Nvl\MailNotifications\Enums\MailOwnerOperation RecoverScheduled=Nvl\MailNotifications\Enums\MailOwnerOperation::RecoverScheduled="mail.recover-scheduled"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\MailNotifications\Enums\ScheduledMailReadAbility

```text
enum Nvl\MailNotifications\Enums\ScheduledMailReadAbility implements BackedEnum, UnitEnum: string
public const Nvl\MailNotifications\Enums\ScheduledMailReadAbility List=Nvl\MailNotifications\Enums\ScheduledMailReadAbility::List="list"
public const Nvl\MailNotifications\Enums\ScheduledMailReadAbility Statistics=Nvl\MailNotifications\Enums\ScheduledMailReadAbility::Statistics="statistics"
public const Nvl\MailNotifications\Enums\ScheduledMailReadAbility View=Nvl\MailNotifications\Enums\ScheduledMailReadAbility::View="view"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\MailNotifications\Enums\ScheduledMailStatus

```text
enum Nvl\MailNotifications\Enums\ScheduledMailStatus implements BackedEnum, UnitEnum: string
public const Nvl\MailNotifications\Enums\ScheduledMailStatus Cancelled=Nvl\MailNotifications\Enums\ScheduledMailStatus::Cancelled="cancelled"
public const Nvl\MailNotifications\Enums\ScheduledMailStatus Failed=Nvl\MailNotifications\Enums\ScheduledMailStatus::Failed="failed"
public const Nvl\MailNotifications\Enums\ScheduledMailStatus Pending=Nvl\MailNotifications\Enums\ScheduledMailStatus::Pending="pending"
public const Nvl\MailNotifications\Enums\ScheduledMailStatus Processing=Nvl\MailNotifications\Enums\ScheduledMailStatus::Processing="processing"
public const Nvl\MailNotifications\Enums\ScheduledMailStatus Sent=Nvl\MailNotifications\Enums\ScheduledMailStatus::Sent="sent"
public function isTerminal(): bool
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\MailNotifications\Events\MailAcceptedByProvider

```text
final class Nvl\MailNotifications\Events\MailAcceptedByProvider implements Nvl\Support\Contracts\DomainEvent
public function __construct(Nvl\MailNotifications\ValueObjects\TrackingAttempt $attempt, Nvl\MailNotifications\ValueObjects\ProviderMessageId $messageId, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly Nvl\MailNotifications\ValueObjects\TrackingAttempt $attempt
public readonly Nvl\MailNotifications\ValueObjects\ProviderMessageId $messageId
public readonly int $schemaVersion
```

## Nvl\MailNotifications\Events\MailDeliveryStatusChanged

```text
final class Nvl\MailNotifications\Events\MailDeliveryStatusChanged implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $notificationId, Nvl\MailNotifications\Enums\MailDeliveryStatus $previousStatus, Nvl\MailNotifications\Enums\MailDeliveryStatus $currentStatus, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly Nvl\MailNotifications\Enums\MailDeliveryStatus $currentStatus
public readonly string $notificationId
public readonly Nvl\MailNotifications\Enums\MailDeliveryStatus $previousStatus
public readonly int $schemaVersion
```

## Nvl\MailNotifications\Events\MailTrackingFailed

```text
final class Nvl\MailNotifications\Events\MailTrackingFailed implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $correlationId, ?string $attemptId, string $exceptionClass, ?Nvl\MailNotifications\ValueObjects\ProviderMessageId $messageId=null, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly ?string $attemptId
public readonly string $correlationId
public readonly string $exceptionClass
public readonly ?Nvl\MailNotifications\ValueObjects\ProviderMessageId $messageId
public readonly int $schemaVersion
```

## Nvl\MailNotifications\Events\MailTrackingStarted

```text
final class Nvl\MailNotifications\Events\MailTrackingStarted implements Nvl\Support\Contracts\DomainEvent
public function __construct(Nvl\MailNotifications\ValueObjects\TrackingAttempt $attempt, string $category, array $correlation=[], int $schemaVersion=1): mixed
@param array<string, (string | int | bool | null)> $correlation
public function schemaVersion(): int
public readonly Nvl\MailNotifications\ValueObjects\TrackingAttempt $attempt
public readonly string $category
public readonly array $correlation
@param array<string, (string | int | bool | null)> $correlation
public readonly int $schemaVersion
```

## Nvl\MailNotifications\Events\MailWebhookAcknowledged

```text
final class Nvl\MailNotifications\Events\MailWebhookAcknowledged implements Nvl\Support\Contracts\DomainEvent
public function __construct(Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement $acknowledgement, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement $acknowledgement
public readonly int $schemaVersion
```

## Nvl\MailNotifications\Events\ScheduledMailCancelled

```text
final class Nvl\MailNotifications\Events\ScheduledMailCancelled implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $messageId, Carbon\CarbonImmutable $cancelledAt, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly Carbon\CarbonImmutable $cancelledAt
public readonly string $messageId
public readonly int $schemaVersion
```

## Nvl\MailNotifications\Events\ScheduledMailClaimed

```text
final class Nvl\MailNotifications\Events\ScheduledMailClaimed implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $messageId, int $attempt, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly int $attempt
public readonly string $messageId
public readonly int $schemaVersion
```

## Nvl\MailNotifications\Events\ScheduledMailFailed

```text
final class Nvl\MailNotifications\Events\ScheduledMailFailed implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $messageId, int $attempt, string $failureType, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly int $attempt
public readonly string $failureType
public readonly string $messageId
public readonly int $schemaVersion
```

## Nvl\MailNotifications\Events\ScheduledMailRecovered

```text
final class Nvl\MailNotifications\Events\ScheduledMailRecovered implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $messageId, int $attempt, bool $willRetry, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly int $attempt
public readonly string $messageId
public readonly int $schemaVersion
public readonly bool $willRetry
```

## Nvl\MailNotifications\Events\ScheduledMailReplaced

```text
final class Nvl\MailNotifications\Events\ScheduledMailReplaced implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $messageId, string $previousFactoryAlias, string $factoryAlias, int $previousPayloadVersion, int $payloadVersion, Carbon\CarbonImmutable $previousScheduledFor, Carbon\CarbonImmutable $previousAvailableAt, Carbon\CarbonImmutable $scheduledFor, Carbon\CarbonImmutable $availableAt, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly Carbon\CarbonImmutable $availableAt
public readonly string $factoryAlias
public readonly string $messageId
public readonly int $payloadVersion
public readonly Carbon\CarbonImmutable $previousAvailableAt
public readonly string $previousFactoryAlias
public readonly int $previousPayloadVersion
public readonly Carbon\CarbonImmutable $previousScheduledFor
public readonly Carbon\CarbonImmutable $scheduledFor
public readonly int $schemaVersion
```

## Nvl\MailNotifications\Events\ScheduledMailRescheduled

```text
final class Nvl\MailNotifications\Events\ScheduledMailRescheduled implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $messageId, Carbon\CarbonImmutable $previousScheduledFor, Carbon\CarbonImmutable $previousAvailableAt, Carbon\CarbonImmutable $scheduledFor, Carbon\CarbonImmutable $availableAt, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly Carbon\CarbonImmutable $availableAt
public readonly string $messageId
public readonly Carbon\CarbonImmutable $previousAvailableAt
public readonly Carbon\CarbonImmutable $previousScheduledFor
public readonly Carbon\CarbonImmutable $scheduledFor
public readonly int $schemaVersion
```

## Nvl\MailNotifications\Events\ScheduledMailRetrying

```text
final class Nvl\MailNotifications\Events\ScheduledMailRetrying implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $messageId, int $attempt, Carbon\CarbonImmutable $availableAt, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly int $attempt
public readonly Carbon\CarbonImmutable $availableAt
public readonly string $messageId
public readonly int $schemaVersion
```

## Nvl\MailNotifications\Events\ScheduledMailScheduled

```text
final class Nvl\MailNotifications\Events\ScheduledMailScheduled implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $messageId, string $factoryAlias, int $payloadVersion, Carbon\CarbonImmutable $scheduledFor, Carbon\CarbonImmutable $availableAt, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly Carbon\CarbonImmutable $availableAt
public readonly string $factoryAlias
public readonly string $messageId
public readonly int $payloadVersion
public readonly Carbon\CarbonImmutable $scheduledFor
public readonly int $schemaVersion
```

## Nvl\MailNotifications\Events\ScheduledMailSent

```text
final class Nvl\MailNotifications\Events\ScheduledMailSent implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $messageId, int $attempt, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly int $attempt
public readonly string $messageId
public readonly int $schemaVersion
```

## Nvl\MailNotifications\Events\WebhookEventAmbiguous

```text
final class Nvl\MailNotifications\Events\WebhookEventAmbiguous implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $provider, string $providerEventId, ?string $providerMessageId, ?string $correlationId, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly ?string $correlationId
public readonly string $provider
public readonly string $providerEventId
public readonly ?string $providerMessageId
public readonly int $schemaVersion
```

## Nvl\MailNotifications\Exceptions\AmbiguousDeliveryEventException

```text
final class Nvl\MailNotifications\Exceptions\AmbiguousDeliveryEventException extends DomainException implements Nvl\Support\Contracts\RespondableException
public function __construct(): mixed
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\MailNotifications\Exceptions\MailDeliveryCancelled

```text
final class Nvl\MailNotifications\Exceptions\MailDeliveryCancelled extends Nvl\MailNotifications\Exceptions\MailNotificationsException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\MailNotifications\Exceptions\MailNotificationsException

```text
class Nvl\MailNotifications\Exceptions\MailNotificationsException extends RuntimeException implements Nvl\Support\Contracts\RespondableException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\MailNotifications\Exceptions\MailRetentionException

```text
final class Nvl\MailNotifications\Exceptions\MailRetentionException extends Nvl\MailNotifications\Exceptions\MailNotificationsException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\MailNotifications\Exceptions\MailTrackingException

```text
final class Nvl\MailNotifications\Exceptions\MailTrackingException extends Nvl\MailNotifications\Exceptions\MailNotificationsException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\MailNotifications\Exceptions\ScheduledMailException

```text
final class Nvl\MailNotifications\Exceptions\ScheduledMailException extends Nvl\MailNotifications\Exceptions\MailNotificationsException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\MailNotifications\Exceptions\SensitiveStorageException

```text
class Nvl\MailNotifications\Exceptions\SensitiveStorageException extends Nvl\MailNotifications\Exceptions\MailNotificationsException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\MailNotifications\Exceptions\UnmatchedDeliveryEventException

```text
final class Nvl\MailNotifications\Exceptions\UnmatchedDeliveryEventException extends DomainException implements Nvl\Support\Contracts\RespondableException
public function __construct(): mixed
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\MailNotifications\Exceptions\UnreadableSensitiveDataException

```text
final class Nvl\MailNotifications\Exceptions\UnreadableSensitiveDataException extends Nvl\MailNotifications\Exceptions\SensitiveStorageException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\MailNotifications\Laravel\Concerns\TracksMailDelivery

```text
trait Nvl\MailNotifications\Laravel\Concerns\TracksMailDelivery
@mixin Mailable
resolved @mixin Illuminate\Mail\Mailable
public function failed(?Throwable $exception): void
public function forNotifiable(Nvl\MailNotifications\Contracts\MailTrackable $notifiable): static
public function hasMailTrackingEnabled(): bool
public function later(mixed $delay, Illuminate\Contracts\Queue\Factory $queue): mixed
public function queue(Illuminate\Contracts\Queue\Factory $queue): mixed
public function recordMailTrackingFailure(?Throwable $exception): void
public function send(mixed $mailer): ?Illuminate\Mail\SentMessage
@param (MailFactory | MailerContract) $mailer
resolved @param (Illuminate\Contracts\Mail\Factory | Illuminate\Contracts\Mail\Mailer) $mailer
public function withMailTracking(): static
public function withoutMailTracking(): static
public function withTrackingMetadata(array $metadata): static
@param array<string, mixed> $metadata
```

## Nvl\MailNotifications\Models\MailNotification

```text
class Nvl\MailNotifications\Models\MailNotification extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables::Notifications
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function freshTimestamp(): Illuminate\Support\Carbon
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\MailNotifications\Models\MailNotification>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function providerEvents(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<MailNotificationEvent, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\MailNotifications\Models\MailNotificationEvent, $this>
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property (CarbonImmutable | null) $accepted_at
resolved @property (Carbon\CarbonImmutable | null) $accepted_at
@property (list<array{email: string, name: (string | null)}> | null) $bcc_recipients
@property (list<array{email: string, name: (string | null)}> | null) $cc_recipients
@property string $correlation_id
@property CarbonImmutable $created_at
resolved @property Carbon\CarbonImmutable $created_at
@property (CarbonImmutable | null) $delivered_at
resolved @property (Carbon\CarbonImmutable | null) $delivered_at
@property (CarbonImmutable | null) $failed_at
resolved @property (Carbon\CarbonImmutable | null) $failed_at
@property (string | null) $from_email
@property (string | null) $from_name
@property string $id
@property string $mailer
@property string $message_category
@property (array<string, mixed> | null) $metadata
@property (string | null) $notifiable_id
@property (string | null) $notifiable_type
@property (string | null) $owner_reference
@property (string | null) $owner_scope_key
@property (string | null) $ownership_key
@property (string | null) $primary_recipient_email
@property (string | null) $provider
@property (string | null) $provider_message_id
@property (CarbonImmutable | null) $provider_occurred_at
resolved @property (Carbon\CarbonImmutable | null) $provider_occurred_at
@property (string | null) $queue_reference
@property (CarbonImmutable | null) $redacted_at
resolved @property (Carbon\CarbonImmutable | null) $redacted_at
@property MailDeliveryStatus $status
resolved @property Nvl\MailNotifications\Enums\MailDeliveryStatus $status
@property (CarbonImmutable | null) $status_changed_at
resolved @property (Carbon\CarbonImmutable | null) $status_changed_at
@property (string | null) $subject
@property (string | null) $tenant_id
@property list<array{email: string, name: (string | null)}> $to_recipients
@property CarbonImmutable $updated_at
resolved @property Carbon\CarbonImmutable $updated_at
```

## Nvl\MailNotifications\Models\MailNotificationEvent

```text
final class Nvl\MailNotifications\Models\MailNotificationEvent extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables::Events
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function freshTimestamp(): Illuminate\Support\Carbon
public function getConnectionName(): ?string
public function getTable(): string
public function mailNotification(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<MailNotification, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\MailNotifications\Models\MailNotification, $this>
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\MailNotifications\Models\MailNotificationEvent>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property CarbonImmutable $created_at
resolved @property Carbon\CarbonImmutable $created_at
@property string $id
@property string $mail_notification_id
@property (array<string, mixed> | null) $metadata
@property MailDeliveryStatus $normalized_type
resolved @property Nvl\MailNotifications\Enums\MailDeliveryStatus $normalized_type
@property CarbonImmutable $occurred_at
resolved @property Carbon\CarbonImmutable $occurred_at
@property (string | null) $owner_scope_key
@property CarbonImmutable $processed_at
resolved @property Carbon\CarbonImmutable $processed_at
@property string $provider
@property string $provider_event_id
@property (string | null) $provider_message_id
@property (CarbonImmutable | null) $redacted_at
resolved @property (Carbon\CarbonImmutable | null) $redacted_at
@property (string | null) $tenant_id
@property CarbonImmutable $updated_at
resolved @property Carbon\CarbonImmutable $updated_at
```

## Nvl\MailNotifications\Models\ScheduledMailMessage

```text
class Nvl\MailNotifications\Models\ScheduledMailMessage extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables::ScheduledMessages
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function freshTimestamp(): Illuminate\Support\Carbon
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\MailNotifications\Models\ScheduledMailMessage>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property int $attempts
@property CarbonImmutable $available_at
resolved @property Carbon\CarbonImmutable $available_at
@property (list<array{email: string, name: (string | null)}> | null) $bcc_recipients
@property (CarbonImmutable | null) $cancelled_at
resolved @property (Carbon\CarbonImmutable | null) $cancelled_at
@property (list<array{email: string, name: (string | null)}> | null) $cc_recipients
@property (string | null) $claim_token
@property CarbonImmutable $created_at
resolved @property Carbon\CarbonImmutable $created_at
@property string $factory_alias
@property (CarbonImmutable | null) $failed_at
resolved @property (Carbon\CarbonImmutable | null) $failed_at
@property string $id
@property (CarbonImmutable | null) $last_attempt_at
resolved @property (Carbon\CarbonImmutable | null) $last_attempt_at
@property (string | null) $last_error
@property (CarbonImmutable | null) $locked_until
resolved @property (Carbon\CarbonImmutable | null) $locked_until
@property int $max_attempts
@property (array<string, mixed> | null) $metadata
@property (string | null) $notifiable_id
@property (string | null) $notifiable_type
@property (string | null) $owner_reference
@property (string | null) $owner_scope_key
@property (array<string, mixed> | null) $ownership_envelope
@property (string | null) $ownership_key
@property array<string, mixed> $payload
@property int $payload_version
@property (CarbonImmutable | null) $redacted_at
resolved @property (Carbon\CarbonImmutable | null) $redacted_at
@property CarbonImmutable $scheduled_for
resolved @property Carbon\CarbonImmutable $scheduled_for
@property (CarbonImmutable | null) $sent_at
resolved @property (Carbon\CarbonImmutable | null) $sent_at
@property ScheduledMailStatus $status
resolved @property Nvl\MailNotifications\Enums\ScheduledMailStatus $status
@property (array{mode: string, tenant_id: (string | null), version: int} | null) $tenant_envelope
@property (string | null) $tenant_id
@property list<array{email: string, name: (string | null)}> $to_recipients
@property CarbonImmutable $updated_at
resolved @property Carbon\CarbonImmutable $updated_at
```

## Nvl\MailNotifications\Services\DatabaseTrackingLifecycle

```text
final readonly class Nvl\MailNotifications\Services\DatabaseTrackingLifecycle implements Nvl\MailNotifications\Contracts\TrackingLifecycle
public function __construct(Nvl\MailNotifications\Contracts\SensitiveDataRedactor $redactor, Nvl\MailNotifications\Services\MailTrackingEventDispatcher $events, Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry $notifiableTypes, Nvl\MailNotifications\Services\SensitiveStorageCodec $sensitiveStorage, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\MailNotifications\Services\MailOwnershipEnvelope $tenantEnvelope, Nvl\MailNotifications\Services\MailNotifiableAccess $notifiableAccess): mixed
public function accepted(Nvl\MailNotifications\ValueObjects\TrackingAttempt $attempt, Nvl\MailNotifications\ValueObjects\ProviderAcceptance $acceptance): void
public function apply(Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent $event): Nvl\MailNotifications\ValueObjects\TransitionResult
@throws AmbiguousDeliveryEventException
resolved @throws Nvl\MailNotifications\Exceptions\AmbiguousDeliveryEventException
@throws UnmatchedDeliveryEventException
resolved @throws Nvl\MailNotifications\Exceptions\UnmatchedDeliveryEventException
public function begin(Nvl\MailNotifications\ValueObjects\PreparedMessage $message): Nvl\MailNotifications\ValueObjects\TrackingAttempt
public function failed(Nvl\MailNotifications\ValueObjects\TrackingAttempt $attempt, Throwable $exception): void
public function queuedFailure(Nvl\MailNotifications\ValueObjects\PreparedMessage $message, Throwable $exception): void
```

## Nvl\MailNotifications\Services\MailHistory

```text
final readonly class Nvl\MailNotifications\Services\MailHistory
public const array COLUMNS=['id', 'correlation_id', 'queue_reference', 'mailer', 'provider', 'provider_message_id', 'status', 'message_category', 'subject', 'from_email', 'from_name', 'primary_recipient_email', 'notifiable_type', 'notifiable_id', 'accepted_at', 'delivered_at', 'failed_at', 'status_changed_at', 'provider_occurred_at', 'redacted_at', 'created_at', 'updated_at']
@var list<string>
public function __construct(Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization $authorization, Nvl\MailNotifications\Services\MailNotificationReadQueryBuilder $queries, Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry $notifiableTypes, Nvl\MailNotifications\Services\ProviderRegistry $providers, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Support\Auth\CanonicalActorResolver $actors): mixed
public function forNotifiable(Illuminate\Contracts\Auth\Authenticatable $actor, Nvl\MailNotifications\ValueObjects\NotifiableReference $notifiable, array $filters=[]): array
@param array<string, mixed> $filters
@return array<string, mixed>
public function get(Illuminate\Contracts\Auth\Authenticatable $actor, string $id): array
@return array<string, mixed>
public function getByProviderMessage(Illuminate\Contracts\Auth\Authenticatable $actor, Nvl\MailNotifications\ValueObjects\ProviderMessageId $messageId): array
@return array<string, mixed>
public function paginate(Illuminate\Contracts\Auth\Authenticatable $actor, array $filters=[]): array
@param array<string, mixed> $filters
@return array<string, mixed>
public function statistics(Illuminate\Contracts\Auth\Authenticatable $actor, array $filters=[]): array
@param array<string, mixed> $filters
@return array<string, mixed>
public function suggest(Illuminate\Contracts\Auth\Authenticatable $actor, string $search, ?Nvl\MailNotifications\Enums\MailDeliveryStatus $status=null, ?string $mailer=null, ?int $limit=null): array
@return list<array<string, mixed>>
```

## Nvl\MailNotifications\Services\MailHistoryAnonymizer

```text
final readonly class Nvl\MailNotifications\Services\MailHistoryAnonymizer
public function __construct(Nvl\MailNotifications\Services\MailAnonymizationConfiguration $configuration, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\MailNotifications\Services\MailOwnershipEnvelope $ownership): mixed
public function anonymize(bool $dryRun=false, ?int $limit=null, ?Carbon\CarbonImmutable $cutoff=null): Nvl\MailNotifications\ValueObjects\MailAnonymizationResult
```

## Nvl\MailNotifications\Services\MailModelRegistry

```text
final class Nvl\MailNotifications\Services\MailModelRegistry
public function __construct(Illuminate\Contracts\Config\Repository $configuration, Nvl\Support\OwnerRegistry $identities): mixed
public function notificationClass(): string
@return class-string<MailNotification>
resolved @return class-string<Nvl\MailNotifications\Models\MailNotification>
public function scheduledClass(): string
@return class-string<ScheduledMailMessage>
resolved @return class-string<Nvl\MailNotifications\Models\ScheduledMailMessage>
```

## Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry

```text
final class Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry
public function __construct(iterable $providers=[], array $configuredTypes=[]): mixed
@param iterable<mixed> $providers
@param array<array-key, mixed> $configuredTypes
public function all(): array
@return array<string, class-string<MailTrackable>>
resolved @return array<string, class-string<Nvl\MailNotifications\Contracts\MailTrackable>>
public function resolve(string $alias): ?string
@return (class-string<MailTrackable> | null)
resolved @return (class-string<Nvl\MailNotifications\Contracts\MailTrackable> | null)
```

## Nvl\MailNotifications\Services\MailRetentionPruner

```text
final readonly class Nvl\MailNotifications\Services\MailRetentionPruner
public function __construct(Nvl\MailNotifications\Services\MailRetentionConfiguration $configuration, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\MailNotifications\Services\MailOwnershipEnvelope $ownership): mixed
public function prune(bool $dryRun=false, ?int $limit=null, ?Carbon\CarbonImmutable $cutoff=null): Nvl\MailNotifications\ValueObjects\MailRetentionResult
```

## Nvl\MailNotifications\Services\MailTestingInterceptor

```text
final readonly class Nvl\MailNotifications\Services\MailTestingInterceptor
public function __construct(Illuminate\Contracts\Foundation\Application $application, Illuminate\Contracts\Config\Repository $config, Illuminate\Mail\MailManager $mail): mixed
public function apply(): void
public function inspect(): Nvl\MailNotifications\ValueObjects\MailNotificationsDoctorCheck
```

## Nvl\MailNotifications\Services\ProviderMessageIdRegistry

```text
final class Nvl\MailNotifications\Services\ProviderMessageIdRegistry
public function __construct(iterable $resolvers, Nvl\MailNotifications\Services\SymfonyMessageIdResolver $fallback): mixed
@param iterable<mixed> $resolvers
public function resolve(Nvl\MailNotifications\ValueObjects\TransportResult $result): Nvl\MailNotifications\ValueObjects\ProviderMessageId
```

## Nvl\MailNotifications\Services\ProviderRegistry

```text
final class Nvl\MailNotifications\Services\ProviderRegistry
public function __construct(iterable $adapters=[]): mixed
@param iterable<mixed> $adapters
public function all(): array
@return array<string, ProviderAdapter>
resolved @return array<string, Nvl\MailNotifications\Contracts\ProviderAdapter>
public function resolve(string $provider): Nvl\MailNotifications\Contracts\ProviderAdapter
```

## Nvl\MailNotifications\Services\RemoteWebhookManagerRegistry

```text
final class Nvl\MailNotifications\Services\RemoteWebhookManagerRegistry
public function __construct(iterable $managers=[]): mixed
@param iterable<mixed> $managers
public function all(): array
@return array<string, RemoteWebhookManager>
resolved @return array<string, Nvl\MailNotifications\Contracts\RemoteWebhookManager>
public function resolve(string $provider): Nvl\MailNotifications\Contracts\RemoteWebhookManager
```

## Nvl\MailNotifications\Services\ScheduledMailProcessor

```text
final readonly class Nvl\MailNotifications\Services\ScheduledMailProcessor
public function __construct(Nvl\MailNotifications\Services\ScheduledMailConfiguration $configuration, Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry $factories, Nvl\MailNotifications\Services\ScheduledMailClaimer $claimer, Nvl\MailNotifications\Services\ScheduledMailFinalizer $finalizer, Illuminate\Contracts\Mail\Factory $mail, Nvl\MailNotifications\Services\ScheduledMailInputGuard $input, Nvl\MailNotifications\Services\MailOwnershipEnvelope $tenantEnvelope, Nvl\Support\Ownership\Contracts\OwnershipQueueContext $ownershipQueue, Nvl\MailNotifications\Contracts\DeliveryProfileResolver $deliveryProfiles): mixed
public function process(?int $limit=null): int
```

## Nvl\MailNotifications\Services\ScheduledMailReader

```text
final readonly class Nvl\MailNotifications\Services\ScheduledMailReader
public const array DETAIL_COLUMNS=[...self::LIST_COLUMNS, 'last_attempt_at', 'notifiable_type', 'notifiable_id', 'redacted_at', 'updated_at']
@var list<string>
public const array LIST_COLUMNS=['id', 'factory_alias', 'payload_version', 'to_recipients', 'status', 'scheduled_for', 'available_at', 'attempts', 'max_attempts', 'sent_at', 'failed_at', 'cancelled_at', 'created_at']
@var list<string>
public function __construct(Nvl\MailNotifications\Contracts\ScheduledMailReadAuthorization $authorization, Nvl\MailNotifications\Services\ScheduledMailReadQueryBuilder $queries, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Support\Auth\CanonicalActorResolver $actors): mixed
public function get(Illuminate\Contracts\Auth\Authenticatable $actor, string $id): array
@return array<string, mixed>
public function paginate(Illuminate\Contracts\Auth\Authenticatable $actor, array $filters=[]): array
@param array<string, mixed> $filters
@return array<string, mixed>
public function statistics(Illuminate\Contracts\Auth\Authenticatable $actor, array $filters=[]): array
@param array<string, mixed> $filters
@return array<string, mixed>
```

## Nvl\MailNotifications\Services\ScheduledMailRecovery

```text
final readonly class Nvl\MailNotifications\Services\ScheduledMailRecovery
public function __construct(Nvl\MailNotifications\Services\ScheduledMailConfiguration $configuration, Nvl\MailNotifications\Services\MailTrackingEventDispatcher $events, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\MailNotifications\Services\MailOwnershipEnvelope $tenantEnvelope): mixed
public function recover(?int $limit=null): int
```

## Nvl\MailNotifications\Services\ScheduledMailScheduler

```text
final readonly class Nvl\MailNotifications\Services\ScheduledMailScheduler implements Nvl\MailNotifications\Contracts\ScheduledMailSchedulerContract
public function __construct(Nvl\MailNotifications\Services\ScheduledMailConfiguration $configuration, Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry $factories, Nvl\MailNotifications\Contracts\SensitiveDataRedactor $redactor, Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry $notifiableTypes, Nvl\MailNotifications\Services\MailTrackingEventDispatcher $events, Nvl\MailNotifications\Services\ScheduledMailInputGuard $input, Nvl\MailNotifications\Services\SensitiveStorageCodec $sensitiveStorage, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\MailNotifications\Services\MailOwnershipEnvelope $tenantEnvelope, Nvl\MailNotifications\Services\MailNotifiableAccess $notifiableAccess): mixed
public function cancel(string $messageId): Nvl\MailNotifications\Models\ScheduledMailMessage
public function replacePending(string $messageId, Nvl\MailNotifications\ValueObjects\ScheduleMailData $data): Nvl\MailNotifications\Models\ScheduledMailMessage
public function reschedule(string $messageId, Carbon\CarbonImmutable $scheduledFor, ?Carbon\CarbonImmutable $availableAt=null): Nvl\MailNotifications\Models\ScheduledMailMessage
public function schedule(Nvl\MailNotifications\ValueObjects\ScheduleMailData $data): Nvl\MailNotifications\Models\ScheduledMailMessage
```

## Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry

```text
final class Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry
public function __construct(iterable $factories=[], ?Illuminate\Contracts\Container\Container $container=null): mixed
@param iterable<mixed> $factories
public function all(): array
@return array<string, ScheduledMessageFactory>
resolved @return array<string, Nvl\MailNotifications\Contracts\ScheduledMessageFactory>
public function resolve(string $alias, int $version): Nvl\MailNotifications\Contracts\ScheduledMessageFactory
```

## Nvl\MailNotifications\Services\WebhookProcessor

```text
final readonly class Nvl\MailNotifications\Services\WebhookProcessor
public function __construct(Nvl\MailNotifications\Services\ProviderRegistry $providers, Nvl\MailNotifications\Contracts\TrackingLifecycle $lifecycle, Illuminate\Contracts\Config\Repository $config, Nvl\MailNotifications\Services\MailDeliveryReconciler $ownership, ?Nvl\MailNotifications\Services\MailTrackingEventDispatcher $events=null): mixed
public function allowedContentTypes(): array
@return list<string>
public function enabled(): bool
public function maximumPayloadBytes(): int
public function process(string $provider, Nvl\MailNotifications\ValueObjects\WebhookRequest $request): Nvl\MailNotifications\ValueObjects\TransitionResult|Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement
public function unknownEventPolicy(): Nvl\MailNotifications\Enums\UnknownWebhookEventPolicy
public function unmatchedEventPolicy(): Nvl\MailNotifications\Enums\UnmatchedWebhookEventPolicy
public function unmatchedEventRetryGraceSeconds(): int
```

## Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery

```text
final readonly class Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery
public function __construct(?string $search=null, ?Nvl\MailNotifications\Enums\MailDeliveryStatus $status=null, ?string $mailer=null, ?string $messageCategory=null, ?Carbon\CarbonImmutable $from=null, ?Carbon\CarbonImmutable $to=null, bool $acceptedOnly=false, bool $failedOnly=false, string $sort="created_at", string $direction="desc", int $page=1, int $perPage=25): mixed
public readonly bool $acceptedOnly
public readonly string $direction
@var ('asc' | 'desc')
public readonly bool $failedOnly
public readonly ?Carbon\CarbonImmutable $from
public readonly ?string $mailer
public readonly ?string $messageCategory
public readonly int $page
public readonly int $perPage
public readonly ?string $search
public readonly string $sort
public readonly ?Nvl\MailNotifications\Enums\MailDeliveryStatus $status
public readonly ?Carbon\CarbonImmutable $to
```

## Nvl\MailNotifications\ValueObjects\NotifiableReference

```text
final readonly class Nvl\MailNotifications\ValueObjects\NotifiableReference
public function __construct(string $type, string $identifier): mixed
public static function fromTrackable(Nvl\MailNotifications\Contracts\MailTrackable $trackable): self
public readonly string $identifier
public readonly string $type
```

## Nvl\MailNotifications\ValueObjects\PreparedMessage

```text
final readonly class Nvl\MailNotifications\ValueObjects\PreparedMessage
public function __construct(string $correlationId, string $mailer, Nvl\MailNotifications\ValueObjects\TrackingContext $context, ?Nvl\MailNotifications\ValueObjects\Recipient $from, array $to, array $cc=[], array $bcc=[], ?string $subject=null, ?string $queueReference=null): mixed
@param list<Recipient> $to
resolved @param list<Nvl\MailNotifications\ValueObjects\Recipient> $to
@param list<Recipient> $cc
resolved @param list<Nvl\MailNotifications\ValueObjects\Recipient> $cc
@param list<Recipient> $bcc
resolved @param list<Nvl\MailNotifications\ValueObjects\Recipient> $bcc
public readonly array $bcc
@param list<Recipient> $bcc
resolved @param list<Nvl\MailNotifications\ValueObjects\Recipient> $bcc
public readonly array $cc
@param list<Recipient> $cc
resolved @param list<Nvl\MailNotifications\ValueObjects\Recipient> $cc
public readonly Nvl\MailNotifications\ValueObjects\TrackingContext $context
public readonly string $correlationId
public readonly ?Nvl\MailNotifications\ValueObjects\Recipient $from
public readonly string $mailer
public readonly ?string $queueReference
public readonly ?string $subject
public readonly array $to
@param list<Recipient> $to
resolved @param list<Nvl\MailNotifications\ValueObjects\Recipient> $to
```

## Nvl\MailNotifications\ValueObjects\ProviderAcceptance

```text
final readonly class Nvl\MailNotifications\ValueObjects\ProviderAcceptance
public function __construct(Nvl\MailNotifications\ValueObjects\ProviderMessageId $messageId, array $metadata=[]): mixed
@param array<string, mixed> $metadata
public readonly Nvl\MailNotifications\ValueObjects\ProviderMessageId $messageId
public readonly array $metadata
@param array<string, mixed> $metadata
```

## Nvl\MailNotifications\ValueObjects\ProviderMessageId

```text
final readonly class Nvl\MailNotifications\ValueObjects\ProviderMessageId
public function __construct(string $provider, string $value): mixed
public readonly string $provider
public readonly string $value
```

## Nvl\MailNotifications\ValueObjects\Recipient

```text
final readonly class Nvl\MailNotifications\ValueObjects\Recipient
public function __construct(string $email, ?string $name=null): mixed
public function toArray(): array
@return array{email: string, name: (string | null)}
public readonly string $email
public readonly ?string $name
```

## Nvl\MailNotifications\ValueObjects\RemoteWebhookManagementResult

```text
final readonly class Nvl\MailNotifications\ValueObjects\RemoteWebhookManagementResult
public function __construct(string $provider, string $operation, int $planned, int $changed, int $unchanged, int $failed, bool $dryRun, array $errors=[]): mixed
@param list<string> $errors
public function succeeded(): bool
public readonly int $changed
public readonly bool $dryRun
public readonly array $errors
@var list<string>
public readonly int $failed
public readonly string $operation
public readonly int $planned
public readonly string $provider
public readonly int $unchanged
```

## Nvl\MailNotifications\ValueObjects\RemoteWebhookRemoveOptions

```text
final readonly class Nvl\MailNotifications\ValueObjects\RemoteWebhookRemoveOptions
public function __construct(bool $all=false, bool $dryRun=false): mixed
public readonly bool $all
public readonly bool $dryRun
```

## Nvl\MailNotifications\ValueObjects\RemoteWebhookSyncOptions

```text
final readonly class Nvl\MailNotifications\ValueObjects\RemoteWebhookSyncOptions
public function __construct(bool $force=false, bool $dryRun=false): mixed
public readonly bool $dryRun
public readonly bool $force
```

## Nvl\MailNotifications\ValueObjects\ScheduleMailData

```text
final readonly class Nvl\MailNotifications\ValueObjects\ScheduleMailData
public function __construct(string $factoryAlias, int $payloadVersion, array $payload, Nvl\MailNotifications\ValueObjects\ScheduledRecipients $recipients, Carbon\CarbonImmutable $scheduledFor, ?Nvl\MailNotifications\ValueObjects\NotifiableReference $notifiable=null, array $metadata=[], ?int $maxAttempts=null, ?Carbon\CarbonImmutable $availableAt=null): mixed
@param array<string, mixed> $payload
@param array<string, mixed> $metadata
public readonly Carbon\CarbonImmutable $availableAt
public readonly string $factoryAlias
public readonly ?int $maxAttempts
public readonly array $metadata
@param array<string, mixed> $metadata
public readonly ?Nvl\MailNotifications\ValueObjects\NotifiableReference $notifiable
public readonly array $payload
@param array<string, mixed> $payload
public readonly int $payloadVersion
public readonly Nvl\MailNotifications\ValueObjects\ScheduledRecipients $recipients
public readonly Carbon\CarbonImmutable $scheduledFor
```

## Nvl\MailNotifications\ValueObjects\ScheduledMailReadQuery

```text
final readonly class Nvl\MailNotifications\ValueObjects\ScheduledMailReadQuery
public function __construct(?Nvl\MailNotifications\Enums\ScheduledMailStatus $status=null, ?string $factoryAlias=null, ?Nvl\MailNotifications\ValueObjects\NotifiableReference $notifiable=null, ?Carbon\CarbonImmutable $from=null, ?Carbon\CarbonImmutable $to=null, bool $dueOnly=false, string $sort="scheduled_for", string $direction="asc", int $page=1, int $perPage=25): mixed
public readonly string $direction
@var ('asc' | 'desc')
public readonly bool $dueOnly
public readonly ?string $factoryAlias
public readonly ?Carbon\CarbonImmutable $from
public readonly ?Nvl\MailNotifications\ValueObjects\NotifiableReference $notifiable
public readonly int $page
public readonly int $perPage
public readonly string $sort
public readonly ?Nvl\MailNotifications\Enums\ScheduledMailStatus $status
public readonly ?Carbon\CarbonImmutable $to
```

## Nvl\MailNotifications\ValueObjects\ScheduledMessageData

```text
final readonly class Nvl\MailNotifications\ValueObjects\ScheduledMessageData
public function __construct(string $id, string $factoryAlias, int $payloadVersion, array $payload, Nvl\MailNotifications\ValueObjects\ScheduledRecipients $recipients, Carbon\CarbonImmutable $scheduledFor, ?Nvl\MailNotifications\ValueObjects\NotifiableReference $notifiable, array $metadata, int $attempt, ?Carbon\CarbonImmutable $availableAt=null): mixed
@param array<string, mixed> $payload
@param array<string, mixed> $metadata
public readonly int $attempt
public readonly Carbon\CarbonImmutable $availableAt
public readonly string $factoryAlias
public readonly string $id
public readonly array $metadata
@param array<string, mixed> $metadata
public readonly ?Nvl\MailNotifications\ValueObjects\NotifiableReference $notifiable
public readonly array $payload
@param array<string, mixed> $payload
public readonly int $payloadVersion
public readonly Nvl\MailNotifications\ValueObjects\ScheduledRecipients $recipients
public readonly Carbon\CarbonImmutable $scheduledFor
```

## Nvl\MailNotifications\ValueObjects\ScheduledRecipients

```text
final readonly class Nvl\MailNotifications\ValueObjects\ScheduledRecipients
public function __construct(array $to, array $cc=[], array $bcc=[]): mixed
@param list<Recipient> $to
resolved @param list<Nvl\MailNotifications\ValueObjects\Recipient> $to
@param list<Recipient> $cc
resolved @param list<Nvl\MailNotifications\ValueObjects\Recipient> $cc
@param list<Recipient> $bcc
resolved @param list<Nvl\MailNotifications\ValueObjects\Recipient> $bcc
public function bccPayload(): array
@return list<array{email: string, name: (string | null)}>
public function ccPayload(): array
@return list<array{email: string, name: (string | null)}>
public static function fromPersisted(array $to, array $cc=[], array $bcc=[]): self
@param array<array-key, mixed> $to
@param array<array-key, mixed> $cc
@param array<array-key, mixed> $bcc
public function toPayload(): array
@return list<array{email: string, name: (string | null)}>
public readonly array $bcc
@var list<Recipient>
resolved @var list<Nvl\MailNotifications\ValueObjects\Recipient>
public readonly array $cc
@var list<Recipient>
resolved @var list<Nvl\MailNotifications\ValueObjects\Recipient>
public readonly array $to
@var list<Recipient>
resolved @var list<Nvl\MailNotifications\ValueObjects\Recipient>
```

## Nvl\MailNotifications\ValueObjects\TrackingAttempt

```text
final readonly class Nvl\MailNotifications\ValueObjects\TrackingAttempt
public function __construct(string $id, string $correlationId): mixed
public readonly string $correlationId
public readonly string $id
```

## Nvl\MailNotifications\ValueObjects\TrackingContext

```text
final readonly class Nvl\MailNotifications\ValueObjects\TrackingContext
public function __construct(string $category, ?Nvl\MailNotifications\ValueObjects\NotifiableReference $notifiable=null, array $metadata=[], array $correlation=[]): mixed
@param array<string, mixed> $metadata
@param array<string, mixed> $correlation
public static function forCategory(string $category): self
public function forNotifiable(Nvl\MailNotifications\Contracts\MailTrackable $notifiable): self
public function withCorrelation(array $correlation): self
@param array<string, mixed> $correlation
public function withMetadata(array $metadata): self
@param array<string, mixed> $metadata
public readonly string $category
public readonly array $correlation
@var array<string, (string | int | bool | null)>
public readonly array $metadata
@param array<string, mixed> $metadata
public readonly ?Nvl\MailNotifications\ValueObjects\NotifiableReference $notifiable
```

## Nvl\MailNotifications\ValueObjects\TransitionResult

```text
final readonly class Nvl\MailNotifications\ValueObjects\TransitionResult
public function __construct(string $notificationId, Nvl\MailNotifications\Enums\MailDeliveryStatus $previousStatus, Nvl\MailNotifications\Enums\MailDeliveryStatus $currentStatus, bool $applied, bool $duplicate=false): mixed
public readonly bool $applied
public readonly Nvl\MailNotifications\Enums\MailDeliveryStatus $currentStatus
public readonly bool $duplicate
public readonly string $notificationId
public readonly Nvl\MailNotifications\Enums\MailDeliveryStatus $previousStatus
```

## Nvl\MailNotifications\ValueObjects\TransportResult

```text
final readonly class Nvl\MailNotifications\ValueObjects\TransportResult
public function __construct(string $mailer, Illuminate\Mail\SentMessage $message): mixed
public readonly string $mailer
public readonly Illuminate\Mail\SentMessage $message
```

## Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent

```text
final readonly class Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent
public function __construct(string $provider, string $eventId, Nvl\MailNotifications\Enums\MailDeliveryStatus $status, Carbon\CarbonImmutable $occurredAt, ?string $providerMessageId=null, ?string $correlationId=null, array $metadata=[]): mixed
@param array<string, mixed> $metadata
public readonly ?string $correlationId
public readonly string $eventId
public readonly array $metadata
@param array<string, mixed> $metadata
public readonly Carbon\CarbonImmutable $occurredAt
public readonly string $provider
public readonly ?string $providerMessageId
public readonly Nvl\MailNotifications\Enums\MailDeliveryStatus $status
```

## Nvl\MailNotifications\ValueObjects\VerifiedWebhook

```text
final readonly class Nvl\MailNotifications\ValueObjects\VerifiedWebhook
public function __construct(string $provider, array $payload): mixed
@param array<string, mixed> $payload
public readonly array $payload
@param array<string, mixed> $payload
public readonly string $provider
```

## Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement

```text
final readonly class Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement
public function __construct(string $provider, string $event, string $reason): mixed
public readonly string $event
public readonly string $provider
public readonly string $reason
```

## Nvl\MailNotifications\ValueObjects\WebhookRequest

```text
final readonly class Nvl\MailNotifications\ValueObjects\WebhookRequest
public function __construct(string $provider, string $body, array $headers, ?string $method=null, ?string $uri=null): mixed
@param array<string, string> $headers
public static function fromLaravelRequest(string $provider, Illuminate\Http\Request $request): self
public readonly string $body
public readonly array $headers
@var array<string, string>
public readonly ?string $method
public readonly string $provider
public readonly ?string $uri
```
