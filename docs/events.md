# NVL mail-notifications events

This document describes the implemented source behavior. Acceptance is exercised by the owning package suites and Core committed-event regression tests; current release execution evidence is tracked in consumer-readiness.md. The authoritative machine-readable schema is [event-catalog.json](../resources/event-catalog.json), catalog version `1`. Event `schemaVersion` is independent of catalog version.

## Publication and listener timing

The native host dispatcher receives the captured event after the supplied source connection outer commit, or immediately when that connection has no active transaction.

Callbacks attach to the matching native connection and current nesting record; native outer/savepoint rollback discards the corresponding callbacks.

Missing source transaction records fail before commit; Mail Notifications reports and drops unusable observations.

Host after-commit listeners and queue after_commit policies can add their own deferral after publication. Host transaction infrastructure and dispatcher bindings are preserved.

Local callbacks are not an outbox. Process exit between commit and callback can lose delivery; no crash durability or exactly-once delivery is promised.

One canonical object is dispatched per qualifying producer call. This is local publication, not cross-process deduplication or a guarantee that repeated observations are unique.

Use Nvl\Support\Events\DomainEventDispatcher::dispatch($event, $writerConnection). Native Event::dispatch() is immediate and has no package interception.

## Payload security and no-op behavior

Operational identifiers, statuses, immutable dates and bounded correlation. No recipient lists, mail content, scheduled factory payload, raw webhook payload or exception objects/messages; MailTrackingFailed includes exception class only. payloadVersion describes the scheduled factory payload, independently of event schemaVersion.

Delivery/lifecycle guards and scheduled claim fences govern observations; retries/recovery are distinct facts. Duplicate or unchanged status transitions are suppressed by native lifecycle rules. Observer registration/listener failures are reported and contained, never substituted for the original transport failure.

Factory aliases and payloadVersion in scheduled events are metadata only; no scheduled factory payload is copied. Listener/reporting failures stay contained in MailTrackingEventDispatcher.

Actor/owner identifiers do not grant access. Listeners must preserve the captured ownership and apply their own authorization when reading storage. Readonly payload fields and native value objects are schema facts; public constructors with mixed arrays do not create a new recursive sanitization boundary. Package producer shapes are documented below; hosts must not attach models, mutable service objects or private arbitrary data.

## Canonical events

| Event | Schema version | Trigger |
| --- | --- | --- |
| [MailAcceptedByProvider](#mailacceptedbyprovider) | 1 | Provider accepted tracked attempt. |
| [MailDeliveryStatusChanged](#maildeliverystatuschanged) | 1 | Delivery status advanced. |
| [MailTrackingFailed](#mailtrackingfailed) | 1 | Operational tracking failed. |
| [MailTrackingStarted](#mailtrackingstarted) | 1 | Tracking attempt persisted. |
| [MailWebhookAcknowledged](#mailwebhookacknowledged) | 1 | Verified webhook acknowledged without lifecycle mutation. |
| [ScheduledMailCancelled](#scheduledmailcancelled) | 1 | Pending message cancelled. |
| [ScheduledMailClaimed](#scheduledmailclaimed) | 1 | Due message fenced for one attempt. |
| [ScheduledMailFailed](#scheduledmailfailed) | 1 | Scheduled delivery failed terminally. |
| [ScheduledMailRecovered](#scheduledmailrecovered) | 1 | Expired claim recovered. |
| [ScheduledMailReplaced](#scheduledmailreplaced) | 1 | Pending factory payload metadata replaced. |
| [ScheduledMailRescheduled](#scheduledmailrescheduled) | 1 | Pending availability changed. |
| [ScheduledMailRetrying](#scheduledmailretrying) | 1 | Scheduled retry made available. |
| [ScheduledMailScheduled](#scheduledmailscheduled) | 1 | Scheduled message persisted. |
| [ScheduledMailSent](#scheduledmailsent) | 1 | Scheduled delivery finalized as sent. |
| [WebhookEventAmbiguous](#webhookeventambiguous) | 1 | Verified webhook matched ambiguous deliveries. |

### MailAcceptedByProvider

`Nvl\MailNotifications\Events\MailAcceptedByProvider` · [source](../src/Events/MailAcceptedByProvider.php) · event schema `1`.

Provider accepted tracked attempt.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$attempt` | `Nvl\MailNotifications\ValueObjects\TrackingAttempt` | public | `required` | — |
| `$messageId` | `Nvl\MailNotifications\ValueObjects\ProviderMessageId` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$attempt` | `Nvl\MailNotifications\ValueObjects\TrackingAttempt` | — |
| `$messageId` | `Nvl\MailNotifications\ValueObjects\ProviderMessageId` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/DatabaseTrackingLifecycle.php](../src/Services/DatabaseTrackingLifecycle.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

### MailDeliveryStatusChanged

`Nvl\MailNotifications\Events\MailDeliveryStatusChanged` · [source](../src/Events/MailDeliveryStatusChanged.php) · event schema `1`.

Delivery status advanced.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$notificationId` | `string` | public | `required` | — |
| `$previousStatus` | `Nvl\MailNotifications\Enums\MailDeliveryStatus` | public | `required` | — |
| `$currentStatus` | `Nvl\MailNotifications\Enums\MailDeliveryStatus` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$notificationId` | `string` | — |
| `$previousStatus` | `Nvl\MailNotifications\Enums\MailDeliveryStatus` | — |
| `$currentStatus` | `Nvl\MailNotifications\Enums\MailDeliveryStatus` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/DatabaseTrackingLifecycle.php](../src/Services/DatabaseTrackingLifecycle.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |
| [Services/DatabaseTrackingLifecycle.php](../src/Services/DatabaseTrackingLifecycle.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |
| [Services/DatabaseTrackingLifecycle.php](../src/Services/DatabaseTrackingLifecycle.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

### MailTrackingFailed

`Nvl\MailNotifications\Events\MailTrackingFailed` · [source](../src/Events/MailTrackingFailed.php) · event schema `1`.

Operational tracking failed.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$correlationId` | `string` | public | `required` | — |
| `$attemptId` | `?string` | public | `required` | — |
| `$exceptionClass` | `string` | public | `required` | — |
| `$messageId` | `?Nvl\MailNotifications\ValueObjects\ProviderMessageId` | public | `null` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$correlationId` | `string` | — |
| `$attemptId` | `?string` | — |
| `$exceptionClass` | `string` | — |
| `$messageId` | `?Nvl\MailNotifications\ValueObjects\ProviderMessageId` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/DatabaseTrackingLifecycle.php](../src/Services/DatabaseTrackingLifecycle.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |
| [Services/TrackingRuntime.php](../src/Services/TrackingRuntime.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |
| [Services/TrackingRuntime.php](../src/Services/TrackingRuntime.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |
| [Services/TrackingRuntime.php](../src/Services/TrackingRuntime.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

### MailTrackingStarted

`Nvl\MailNotifications\Events\MailTrackingStarted` · [source](../src/Events/MailTrackingStarted.php) · event schema `1`.

Tracking attempt persisted.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$attempt` | `Nvl\MailNotifications\ValueObjects\TrackingAttempt` | public | `required` | — |
| `$category` | `string` | public | `required` | — |
| `$correlation` | `array` | public | `[]` | `array<string, string\|int\|bool\|null>` |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$attempt` | `Nvl\MailNotifications\ValueObjects\TrackingAttempt` | — |
| `$category` | `string` | — |
| `$correlation` | `array` | `array<string, string\|int\|bool\|null>` |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/DatabaseTrackingLifecycle.php](../src/Services/DatabaseTrackingLifecycle.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |
| [Services/DatabaseTrackingLifecycle.php](../src/Services/DatabaseTrackingLifecycle.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

### MailWebhookAcknowledged

`Nvl\MailNotifications\Events\MailWebhookAcknowledged` · [source](../src/Events/MailWebhookAcknowledged.php) · event schema `1`.

Verified webhook acknowledged without lifecycle mutation.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$acknowledgement` | `Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$acknowledgement` | `Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/WebhookProcessor.php](../src/Services/WebhookProcessor.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

### ScheduledMailCancelled

`Nvl\MailNotifications\Events\ScheduledMailCancelled` · [source](../src/Events/ScheduledMailCancelled.php) · event schema `1`.

Pending message cancelled.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$messageId` | `string` | public | `required` | — |
| `$cancelledAt` | `Carbon\CarbonImmutable` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$messageId` | `string` | — |
| `$cancelledAt` | `Carbon\CarbonImmutable` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/ScheduledMailScheduler.php](../src/Services/ScheduledMailScheduler.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

### ScheduledMailClaimed

`Nvl\MailNotifications\Events\ScheduledMailClaimed` · [source](../src/Events/ScheduledMailClaimed.php) · event schema `1`.

Due message fenced for one attempt.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$messageId` | `string` | public | `required` | — |
| `$attempt` | `int` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$messageId` | `string` | — |
| `$attempt` | `int` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/ScheduledMailClaimer.php](../src/Services/ScheduledMailClaimer.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

### ScheduledMailFailed

`Nvl\MailNotifications\Events\ScheduledMailFailed` · [source](../src/Events/ScheduledMailFailed.php) · event schema `1`.

Scheduled delivery failed terminally.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$messageId` | `string` | public | `required` | — |
| `$attempt` | `int` | public | `required` | — |
| `$failureType` | `string` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$messageId` | `string` | — |
| `$attempt` | `int` | — |
| `$failureType` | `string` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/ScheduledMailFinalizer.php](../src/Services/ScheduledMailFinalizer.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |
| [Services/ScheduledMailRecovery.php](../src/Services/ScheduledMailRecovery.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

### ScheduledMailRecovered

`Nvl\MailNotifications\Events\ScheduledMailRecovered` · [source](../src/Events/ScheduledMailRecovered.php) · event schema `1`.

Expired claim recovered.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$messageId` | `string` | public | `required` | — |
| `$attempt` | `int` | public | `required` | — |
| `$willRetry` | `bool` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$messageId` | `string` | — |
| `$attempt` | `int` | — |
| `$willRetry` | `bool` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/ScheduledMailRecovery.php](../src/Services/ScheduledMailRecovery.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

### ScheduledMailReplaced

`Nvl\MailNotifications\Events\ScheduledMailReplaced` · [source](../src/Events/ScheduledMailReplaced.php) · event schema `1`.

Pending factory payload metadata replaced.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$messageId` | `string` | public | `required` | — |
| `$previousFactoryAlias` | `string` | public | `required` | — |
| `$factoryAlias` | `string` | public | `required` | — |
| `$previousPayloadVersion` | `int` | public | `required` | — |
| `$payloadVersion` | `int` | public | `required` | — |
| `$previousScheduledFor` | `Carbon\CarbonImmutable` | public | `required` | — |
| `$previousAvailableAt` | `Carbon\CarbonImmutable` | public | `required` | — |
| `$scheduledFor` | `Carbon\CarbonImmutable` | public | `required` | — |
| `$availableAt` | `Carbon\CarbonImmutable` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$messageId` | `string` | — |
| `$previousFactoryAlias` | `string` | — |
| `$factoryAlias` | `string` | — |
| `$previousPayloadVersion` | `int` | — |
| `$payloadVersion` | `int` | — |
| `$previousScheduledFor` | `Carbon\CarbonImmutable` | — |
| `$previousAvailableAt` | `Carbon\CarbonImmutable` | — |
| `$scheduledFor` | `Carbon\CarbonImmutable` | — |
| `$availableAt` | `Carbon\CarbonImmutable` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/ScheduledMailScheduler.php](../src/Services/ScheduledMailScheduler.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

### ScheduledMailRescheduled

`Nvl\MailNotifications\Events\ScheduledMailRescheduled` · [source](../src/Events/ScheduledMailRescheduled.php) · event schema `1`.

Pending availability changed.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$messageId` | `string` | public | `required` | — |
| `$previousScheduledFor` | `Carbon\CarbonImmutable` | public | `required` | — |
| `$previousAvailableAt` | `Carbon\CarbonImmutable` | public | `required` | — |
| `$scheduledFor` | `Carbon\CarbonImmutable` | public | `required` | — |
| `$availableAt` | `Carbon\CarbonImmutable` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$messageId` | `string` | — |
| `$previousScheduledFor` | `Carbon\CarbonImmutable` | — |
| `$previousAvailableAt` | `Carbon\CarbonImmutable` | — |
| `$scheduledFor` | `Carbon\CarbonImmutable` | — |
| `$availableAt` | `Carbon\CarbonImmutable` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/ScheduledMailScheduler.php](../src/Services/ScheduledMailScheduler.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

### ScheduledMailRetrying

`Nvl\MailNotifications\Events\ScheduledMailRetrying` · [source](../src/Events/ScheduledMailRetrying.php) · event schema `1`.

Scheduled retry made available.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$messageId` | `string` | public | `required` | — |
| `$attempt` | `int` | public | `required` | — |
| `$availableAt` | `Carbon\CarbonImmutable` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$messageId` | `string` | — |
| `$attempt` | `int` | — |
| `$availableAt` | `Carbon\CarbonImmutable` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/ScheduledMailFinalizer.php](../src/Services/ScheduledMailFinalizer.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |
| [Services/ScheduledMailRecovery.php](../src/Services/ScheduledMailRecovery.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

### ScheduledMailScheduled

`Nvl\MailNotifications\Events\ScheduledMailScheduled` · [source](../src/Events/ScheduledMailScheduled.php) · event schema `1`.

Scheduled message persisted.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$messageId` | `string` | public | `required` | — |
| `$factoryAlias` | `string` | public | `required` | — |
| `$payloadVersion` | `int` | public | `required` | — |
| `$scheduledFor` | `Carbon\CarbonImmutable` | public | `required` | — |
| `$availableAt` | `Carbon\CarbonImmutable` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$messageId` | `string` | — |
| `$factoryAlias` | `string` | — |
| `$payloadVersion` | `int` | — |
| `$scheduledFor` | `Carbon\CarbonImmutable` | — |
| `$availableAt` | `Carbon\CarbonImmutable` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/ScheduledMailScheduler.php](../src/Services/ScheduledMailScheduler.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

### ScheduledMailSent

`Nvl\MailNotifications\Events\ScheduledMailSent` · [source](../src/Events/ScheduledMailSent.php) · event schema `1`.

Scheduled delivery finalized as sent.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$messageId` | `string` | public | `required` | — |
| `$attempt` | `int` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$messageId` | `string` | — |
| `$attempt` | `int` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/ScheduledMailFinalizer.php](../src/Services/ScheduledMailFinalizer.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

### WebhookEventAmbiguous

`Nvl\MailNotifications\Events\WebhookEventAmbiguous` · [source](../src/Events/WebhookEventAmbiguous.php) · event schema `1`.

Verified webhook matched ambiguous deliveries.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$provider` | `string` | public | `required` | — |
| `$providerEventId` | `string` | public | `required` | — |
| `$providerMessageId` | `?string` | public | `required` | — |
| `$correlationId` | `?string` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$provider` | `string` | — |
| `$providerEventId` | `string` | — |
| `$providerMessageId` | `?string` | — |
| `$correlationId` | `?string` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/WebhookProcessor.php](../src/Services/WebhookProcessor.php) | `MailTrackingEventDispatcher resolves PackageStorage::connection('mail-notifications') through the injected DatabaseManager; safeDispatch seams report and contain delivery failures.` |

## Referenced payload types

Native event field types are listed above; nested declared fields and backed enum values follow. Private captured envelopes are included because serialized/queued objects retain them. Dates use `Carbon\CarbonImmutable`. Spatie Data serialization can also carry its protected transformation metadata; immutable serialized payload graphs are checked by the C4 contract suite.

### MailDeliveryStatus

`Nvl\MailNotifications\Enums\MailDeliveryStatus` · [source](../src/Enums/MailDeliveryStatus.php).

Backed string values: `Pending = pending`, `Accepted = accepted`, `Delayed = delayed`, `Delivered = delivered`, `Opened = opened`, `Clicked = clicked`, `Bounced = bounced`, `Complained = complained`, `Rejected = rejected`, `Failed = failed`, `Unsubscribed = unsubscribed`.

### ProviderMessageId

`Nvl\MailNotifications\ValueObjects\ProviderMessageId` · [source](../src/ValueObjects/ProviderMessageId.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$provider` | `string` | — |
| `$value` | `string` | — |

### TrackingAttempt

`Nvl\MailNotifications\ValueObjects\TrackingAttempt` · [source](../src/ValueObjects/TrackingAttempt.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$id` | `string` | — |
| `$correlationId` | `string` | — |

### WebhookAcknowledgement

`Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement` · [source](../src/ValueObjects/WebhookAcknowledgement.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$provider` | `string` | — |
| `$event` | `string` | — |
| `$reason` | `string` | — |

## Deferred acceptance checks

Final testing must compare catalog types/defaults/aliases with actual classes, recursively inspect producer payloads, and prove source outer commit, nested rollback, unrelated connection independence and retry behavior without an uncommitted test-harness transaction. Where applicable it must cover legacy exact/cached/queued listeners, canonical fakes and wildcard delivery, tenant capture, package no-op guards and observational failure containment. This document does not report those checks as passing.

## Consumer event assertions

Use the canonical event class listed in the catalog for `Event::fake([...])` and `Event::assertDispatched(...)`. Laravel fake filters compare the emitted class name; an old alias import does not rename that canonical object. Legacy exact listeners are bridged at delivery time through Laravel’s native dispatcher. Keep compatibility listener tests on their exact legacy name, and migrate suffix-specific wildcards to canonical names.

