# NVL Mail Notifications — API and usage

[← NVL Laravel Suite](../../../README.md)

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/laravel-suite:^1.0` |
| Module identifier | `nvl/mail-notifications` |
| PHP namespace | `Nvl\MailNotifications` |
| Service provider | `Nvl\MailNotifications\Providers\MailNotificationsServiceProvider` |
| Configuration | `config/mail-notifications.php` |

Provider-neutral, explicitly opt-in outbound mail tracking for Laravel.

## Purpose

`nvl/mail-notifications` records the lifecycle of selected Laravel Mailables
without replacing Laravel Mail or selecting a transport. Ordinary Mailables are
unaffected. A message participates in tracking only when it implements
`TrackableMessage` and uses `TracksMailDelivery`.

The package owns a tokenized default Laravel Markdown presentation, correlation
identifiers, normalized effective recipients,
pending and accepted delivery state, provider-neutral lifecycle transitions,
durable provider-event idempotency, privacy-safe metadata, after-commit events,
database-enforced status allowlists, optional protected JSON storage, bounded
history anonymization, and optional environment-safe recipient interception.
Provider SDKs, application mail content, sender policy, business notification
policy, concrete domain models, permissions, routes, and dashboards stay
outside the package.

The default theme loads after application-configured `mail.markdown.paths`, so
an existing host override remains authoritative. Publish the package views when
the host wants editable copies at Laravel's conventional override path.

## Requirements and installation

- PHP 8.3 or newer
- Laravel 12 or 13
- SQLite
- PostgreSQL supported by the installed Laravel version
- MySQL 8.0.16 or newer
- MariaDB 10.3 or newer through Laravel's `mariadb` driver, with the session
  variable `check_constraint_checks` enabled

```bash
composer require nvl/laravel-suite:^1.0
php artisan vendor:publish --tag=mail-notifications-config
php artisan vendor:publish --tag=mail-notifications-skills
php artisan vendor:publish --tag=mail-notifications-mail-views
php artisan migrate
```

Package discovery registers `MailNotificationsServiceProvider`. Migrations
load automatically unless `mail-notifications.migrations.enabled` is false.
Choose exactly one migration owner:

1. **Automatic vendor loading (default):** leave `mail-notifications.migrations.enabled=true`, do not publish `mail-notifications-migrations`, and run `php artisan migrate`.
2. **Host-owned published migrations:** publish `mail-notifications-migrations`, set `mail-notifications.migrations.enabled=false` before migrating, and maintain the published files as application migrations.

Never run both sources. Laravel retimestamps files published through the migration tag. `php artisan nvl:mail-notifications:doctor` reports a warning when automatic loading remains enabled and `database/migrations` contains a timestamp-independent name matching a package migration; `--strict` promotes that warning to failure. Keep the configured storage connection and table names stable between forward migrations. The first-release creator migrations install queue-failure
identity, privacy markers, retention indexes, and exact status invariants as one
complete schema contract. This unpublished package has no corrective
queue/status/privacy migration chain to replay.

PostgreSQL, MySQL 8.0.16+, and MariaDB 10.3+ through Laravel's `mariadb`
driver receive named `CHECK` constraints for notification status, normalized
provider-event type, and scheduled-message status. SQLite receives equivalent
named `INSERT` and `UPDATE` triggers without rebuilding tables. The
compatibility preflight rejects pre-existing configured tables unless they
expose the complete current columns, exact named indexes, status allowlists,
and ownership linkage and the creator has one authoritative migration-history
record.

The preflight and creator migrations fail before schema mutation when the
configured database cannot prove those invariants are enforced. Laravel
connection table prefixes are respected, and PostgreSQL schema-qualified table
names are supported; ownership checks compare the physical owner schema and
table. Run the strict doctor on the same connection/session used by deployment
and workers.

Package migration `down()` methods are intentionally no-ops: ordinary
`migrate:rollback` retains mail history tables, columns, indexes, constraints,
and SQLite invariant triggers and is not an uninstall mechanism. Laravel still
removes the corresponding migration history rows. If a package migration is
rolled back accidentally, do not rerun it against retained tables with
ambiguous history. Either restore the exact
migration-history records from the deployment backup after verifying the
schema, or disable package migrations and adopt the retained schema through
application-owned forward migrations.

The read-only compatibility preflight validates columns, portable types,
nullability, lengths, defaults, privacy-marker shape, primary/unique
constraints, exact status allowlists, provider-event ownership, and named
operational indexes before a creator can encounter an existing table. A
compatible pre-existing table pair is still rejected unless the exact package
creator is already recorded in migration history: otherwise its ownership and
future schema evolution have no single writer. Hosts that own an existing pair
must disable package migrations and baseline ownership intentionally. A
notification-only schema is rejected for the same reason: the preflight cannot
safely distinguish an interrupted package creation from a host-owned table, so
it will not let the creator complete that partial schema.

```bash
php artisan vendor:publish --tag=mail-notifications-migrations
php artisan migrate
```

## Explicit opt-in and opt-out

Implement `TrackableMessage` and use `TracksMailDelivery` on a Mailable:

```php
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;
use Nvl\MailNotifications\Contracts\TrackableMessage;
use Nvl\MailNotifications\Laravel\Concerns\TracksMailDelivery;
use Nvl\MailNotifications\ValueObjects\TrackingContext;

final class ReceiptMail extends Mailable implements TrackableMessage
{
    use TracksMailDelivery;

    public function trackingContext(): TrackingContext
    {
        return TrackingContext::forCategory('receipt');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Receipt');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.receipt');
    }
}
```

That class opts in by default. Disable one delivery fluently:

```php
Mail::to($recipient)->send(
    (new ReceiptMail)->withoutMailTracking(),
);
```

Call `withMailTracking()` to re-enable the same instance. Mailables without the
contract and concern never participate.

The concern's `forNotifiable()` and `withTrackingMetadata()` overrides apply to
one `send()` invocation. They survive queue serialization, then clear after the
delivery attempt even when delivery throws, so manually reusing a Mailable
cannot inherit another delivery's host association or metadata. Reapply them
explicitly before another manual send when that context is still intended.

Queued Mailables also serialize a random, non-sensitive `queue_reference`.
Normal delivery attempts retain their own correlation IDs, while Laravel's
terminal queued-email failure callback uses the queue reference to avoid
duplicating a row already failed or accepted by the send path. The original
Mailable instance drops that reference after enqueue, so later manual reuse
cannot join the queued copy's lifecycle.

Exclude whole Laravel mailers when tracking is not wanted for a transport:

```dotenv
MAIL_NOTIFICATIONS_EXCLUDED_MAILERS=smtp,log
```

Mailer exclusions apply after the Mailable opts in. SMTP and every other
Laravel transport continue to send normally. If SMTP is not excluded, the
standard Symfony transport message identifier provides provider-neutral
acceptance correlation. Provider adapters may supply a more specific message
identifier resolver without changing core.

Tracked delivery requires the selected mailer to resolve to Laravel's concrete
`Illuminate\Mail\Mailer` class or a subclass. This lets the package observe the
final message immediately at the Symfony transport boundary, independent of
Laravel event-listener order. Custom Symfony transports inside that mailer are
fully supported. A class that only decorates `Illuminate\Contracts\Mail\Mailer`
cannot expose that boundary safely; exclude it, move the decorator to the
Symfony transport layer, or use `fail_open` to deliver without a tracking row.
Excluded and opted-out custom mailers remain untouched.

Set `MAIL_NOTIFICATIONS_ENABLED=false` for an environment-wide off switch.

## Configuration and host integration

The published configuration contains only scalars, arrays, and class strings,
so it is safe to cache with `php artisan config:cache`. A normal host can plug
in its models and provider integration directly in
`config/mail-notifications.php`; no additional service provider is required:

```php
'notifiable_types' => [
    'account' => App\Models\Account::class,
],

'extensions' => [
    'provider_adapters' => [
        Nvl\MailNotifications\Adapters\MailerSend\MailerSendAdapter::class,
    ],
    'message_id_resolvers' => [],
    'notifiable_type_providers' => [],
    'scheduled_message_factories' => [
        App\Mail\Scheduled\ReceiptMessageFactory::class,
    ],
    'webhook_managers' => [
        Nvl\MailNotifications\Adapters\MailerSend\MailerSendRemoteWebhookManager::class,
    ],
],
```

One provider adapter implements `ProviderAdapter` and may also implement
`ProviderMessageIdResolver`, `WebhookSignatureVerifier`, and
`WebhookEventNormalizer`. This keeps message acceptance and webhook handling
in one provider-owned class without making the core package depend on its SDK.
`RemoteWebhookManager` is a separate, operator-only capability registered
through `extensions.webhook_managers`; it is never resolved for normal mail or
webhook delivery. Standalone resolvers and notifiable-type providers remain
available for integrations that are already split across classes.

The replaceable services are also class-string configuration:

```php
'services' => [
    'tracking_lifecycle' =>
        Nvl\MailNotifications\Services\DatabaseTrackingLifecycle::class,
    'sensitive_data_redactor' =>
        Nvl\MailNotifications\Services\DefaultSensitiveDataRedactor::class,
    'sensitive_storage_transformer' => null,
],
```

Custom implementations must satisfy their corresponding
`TrackingLifecycle`, `SensitiveDataRedactor`, or `SensitiveDataTransformer`
contract. Container injection continues to work for configured implementations.
The package validates extension and service classes during registration instead
of silently ignoring a broken integration.

The principal operational settings are:

| Concern | Configuration |
| --- | --- |
| Runtime | `enabled`, `tracking.enabled`, `webhooks.enabled` |
| Transport opt-out | `tracking.excluded_mailers` |
| Provider identity | `providers.default`, `providers.mailers` |
| Provider plug-ins | `extensions.*` |
| Host model aliases | `notifiable_types` |
| Persistence | `storage.connection`, `storage.tables.*` |
| Scheduled delivery | `scheduling.*`, disabled by default |
| Database retention | `retention.*`; scheduled-message pruning is disabled by default |
| Migration ownership | `migrations.enabled` |
| Privacy | `privacy.*`, `retention.anonymization.*`, `services.sensitive_data_redactor`, `services.sensitive_storage_transformer` |
| Safe test recipients | `testing.*`, with existing `mail.testing` taking precedence |
| Mail presentation | `presentation.enabled`, `presentation.auto_load`, brand values, and tokens |

Environment variables are available for global/tracking/presentation/webhook
switches, excluded mailers, testing interception, provider fallback, database
connection, table names, webhook payload size, redacted keys, and brand values.
Retention ages, status allowlists, scheduled-message opt-in, batch size, and
per-data-set limit also have `MAIL_NOTIFICATIONS_RETENTION_*`,
`MAIL_NOTIFICATIONS_SCHEDULED_RETENTION_*`, and
`MAIL_NOTIFICATIONS_PRUNE_*` environment variables.
Anonymization and protected sensitive-array storage use
`MAIL_NOTIFICATIONS_ANONYMIZATION_*`,
`MAIL_NOTIFICATIONS_SCHEDULED_ANONYMIZATION_*`, and
`MAIL_NOTIFICATIONS_SENSITIVE_STORAGE_*`.
`MAIL_NOTIFICATIONS_METADATA_MAX_DEPTH`,
`MAIL_NOTIFICATIONS_METADATA_MAX_ITEMS`,
`MAIL_NOTIFICATIONS_METADATA_MAX_STRING_BYTES`, and
`MAIL_NOTIFICATIONS_METADATA_MAX_TOTAL_BYTES` bound metadata processing.
Migration ownership, structured mappings, and class lists stay in the
published PHP configuration.

## Optional scheduled delivery

Scheduled delivery is alias- and version-based so durable rows never contain
arbitrary PHP class names. It is disabled by default. Enable it explicitly and
register each host-owned factory through configuration:

```dotenv
MAIL_NOTIFICATIONS_SCHEDULING_ENABLED=true
```

```php
use App\Mail\Scheduled\ReceiptMessageFactory;

'extensions' => [
    // ...
    'scheduled_message_factories' => [
        ReceiptMessageFactory::class,
    ],
],
```

A factory implements `ScheduledMessageFactory`: `alias()` returns its stable
persisted name, `supportsVersion()` declares compatible payload versions,
`validate()` checks a payload before persistence and again before delivery,
and `make()` rebuilds the Mailable from `ScheduledMessageData`. The factory
owns message content, provider-specific deferred-delivery configuration, and
may select any configured Laravel mailer:

```php
public function make(ScheduledMessageData $message): Mailable
{
    return (new ReceiptMail($message->payload['receipt_id']))
        ->mailer('mailersend');
}
```

Scheduling and tracking remain independently optional. When a scheduled
message must also be tracked, the factory must return an opted-in
`TrackableMessage` Mailable and compose the factory input into its
`TrackingContext`: use the stable `$message->notifiable` reference, merge safe
`$message->metadata`, and add a `scheduled_message_id` plus the intended
`scheduled_for` instant for the host read projection. Apply a payload-owned
locale with Laravel's native `locale()` method. The processor does not infer a
host model, locale, category, or provider template policy.

Factories must not choose TO, CC, or BCC recipients. Immediately before send,
the processor clears factory recipient state and replaces the final Symfony
message envelope with the normalized recipients persisted on the scheduled
row. This prevents normal Mailable `Envelope` declarations, fluent recipient
calls, or callbacks registered by `make()` from appending another recipient.
Laravel applies its global `alwaysTo` / `mail.to` interception afterward, so a
host test-inbox safety override remains authoritative and removes CC/BCC.

Schedule through the package write boundary using normalized package
recipients:

```php
use Carbon\CarbonImmutable;
use Nvl\MailNotifications\Services\ScheduledMailScheduler;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\ScheduleMailData;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;

$scheduled = $scheduler->schedule(new ScheduleMailData(
    factoryAlias: 'receipt',
    payloadVersion: 1,
    payload: ['receipt_id' => $receipt->getKey()],
    recipients: new ScheduledRecipients(
        to: [new Recipient($account->email, $account->name)],
    ),
    scheduledFor: CarbonImmutable::parse('2026-08-01 09:00:00', 'UTC'),
    availableAt: CarbonImmutable::parse('2026-07-29 09:00:00', 'UTC'),
));

$scheduler->reschedule(
    $scheduled->id,
    scheduledFor: $newDeliveryUtc,
    availableAt: $newSubmissionUtc,
);
$scheduler->replacePending($scheduled->id, $replacement);
$scheduler->cancel($scheduled->id);
```

`scheduledFor` is the intended recipient delivery instant. Optional
`availableAt` is the earlier instant at which the package may claim the row and
submit it to the host factory. Both are normalized to UTC, and initial
availability must be at or before intended delivery. Omitting `availableAt`
defaults it to `scheduledFor`, preserving ordinary due-time delivery.

An early `availableAt` does not make Laravel or a provider defer delivery. The
host factory must map `ScheduledMessageData::$scheduledFor` to its provider's
actual `sendAt` or equivalent option and validate that provider's supported
lead-time window. Only request early availability when that factory guarantees
deferred provider delivery; the provider-neutral package neither assumes a
three-day limit nor adds provider scheduling metadata. The factory also
receives `availableAt`, which represents eligibility for the current attempt;
retry backoff may move that operational value beyond the original delivery
instant, so provider delivery decisions must continue to use `scheduledFor`.

Cancellation and rescheduling are allowed only while a message is pending.
Rescheduling accepts the same optional `availableAt`; omitting it resets
availability to the new `scheduledFor`. `replacePending()` takes both values
from its replacement `ScheduleMailData`, so delivery and initial submission
timing are replaced atomically.
`replacePending()` atomically revalidates and replaces the factory alias,
payload version/data, recipient envelope, notifiable reference, metadata,
schedule, and max-attempt ceiling on a pending row. It preserves attempts
already made and rejects a ceiling that would leave the row unclaimable.
Processing and terminal rows can never be replaced.
Due messages are atomically claimed with an opaque token; the attempt counter
increments exactly once per successful claim. Delivery runs after the claim
transaction commits, and sent/retry/failure finalization must still match that
token, so an expired worker cannot overwrite a newer claim. Retry availability
uses the configured deterministic `scheduling.backoff_seconds`. Exhausted
messages become failed, expired claims can be recovered, and persisted failure
details contain only a bounded exception class or safe package code—never a raw
exception message. Metadata uses the package redactor.
Scheduled payloads are unredacted and must contain only minimal durable
identifiers; never put rendered content, secrets, tokens, or provider responses
in them. Payload and metadata must each be a top-level object with string keys;
top-level JSON lists are rejected before either create or replacement writes.
Scheduled metadata is redacted by the package.
Payload bytes and the deduplicated TO+CC+BCC recipient count are bounded before
host factory validation and again before delivery. Configure the limits with
`scheduling.max_payload_bytes` and `scheduling.max_recipients`.

Claim fencing prevents stale database writes, not duplicate external side
effects. A worker crash after provider acceptance but before local sent
finalization leaves an ambiguous claim that recovery may deliver again. Use a
provider idempotency key when the transport supports one, and configure the
claim TTL longer than one worst-case send attempt plus operational margin.
The processor claims each message immediately before its send attempt, so later
messages in a bounded run do not consume their lease while earlier messages are
being delivered.

The package supplies bounded one-shot commands but does not choose the host's
scheduler frequency:

```bash
php artisan nvl:mail-notifications:process-scheduled --limit=50
php artisan nvl:mail-notifications:recover-scheduled --limit=50
```

Run both commands from the host scheduler at a cadence appropriate to the
application and claim TTL. `ScheduledMailClaimed`, `ScheduledMailSent`,
`ScheduledMailRetrying`, `ScheduledMailFailed`, and `ScheduledMailRecovered`
cover processing. `ScheduledMailScheduled`, `ScheduledMailCancelled`,
`ScheduledMailRescheduled`, and `ScheduledMailReplaced` cover host mutations.
Scheduling and replacement events expose both UTC timing values; rescheduling
and replacement also expose both previous values.
All are storage-transaction after-commit observational events. The package
dispatcher attaches each event to the exact configured storage connection,
rather than Laravel's generic cross-connection after-commit marker, so an
unrelated open host transaction does not delay a committed package event and a
storage rollback emits nothing. Regular listeners therefore run after the
package write commits. A host listener that separately opts into Laravel's
after-commit handling retains that host-owned behavior. Listener failures are
reported without altering delivery state.

## Explicit database retention

Retention is a bounded, database-only operation. It never calls a mail
provider, sends a message, or registers itself with Laravel's scheduler. The
host must invoke or schedule each run explicitly:

```bash
php artisan nvl:mail-notifications:prune --dry-run
php artisan nvl:mail-notifications:prune --limit=500
php artisan nvl:mail-notifications:prune \
    --before=2026-06-30T00:00:00Z \
    --limit=500
```

`--dry-run` returns deterministic candidate and owned provider-event counts
without mutation. `--before` accepts an absolute, non-future RFC 3339 timestamp
and normalizes it to UTC. `--limit` is applied independently to tracked
notifications and scheduled messages, and deletion queries use the configured
bounded batch size.

Tracked notifications are eligible only when their status is in
`retention.notifications.statuses` and their lifecycle completion
`status_changed_at` is older than the cutoff. Legacy rows with no
`status_changed_at` fall back to `created_at`. Provider-event rows owned by a
selected notification are deleted in the same transaction. The package schema
indexes both `(status, status_changed_at)` and the existing
`(status, created_at)` legacy fallback path; the doctor reports either missing
lookup.

Scheduled-message retention is separately opt-in:

```dotenv
MAIL_NOTIFICATIONS_SCHEDULED_RETENTION_ENABLED=true
```

When disabled, pruning and the retention doctor check do not inspect the
scheduled-message table or validate its retention age/status settings. When
enabled, only allowlisted `sent`, `failed`, and `cancelled` rows can be removed.
Age is measured from the matching `sent_at`, `failed_at`, or `cancelled_at`;
legacy rows missing that timestamp fall back to `updated_at`. Pending and
processing rows are always protected, regardless of age or configuration.
Fresh scheduled-mail storage includes a status-prefixed index for each terminal
timestamp and for the `updated_at` fallback.

Run the strict doctor after changing retention configuration. Its independent
`retention.configuration` check validates notification settings, batch/limit
bounds, and—only when enabled—scheduled-message settings.

## Protected storage and history anonymization

Sensitive-array storage is an opt-in persistence hook, disabled by default.
Set a cache-safe transformer class and enable it only after the strict doctor
passes:

```php
use Nvl\MailNotifications\Services\LaravelEncrypterSensitiveDataTransformer;

'services' => [
    // ...
    'sensitive_storage_transformer' =>
        LaravelEncrypterSensitiveDataTransformer::class,
],

'privacy' => [
    // ...
    'sensitive_storage' => [
        'enabled' => true,
        'max_transformed_bytes' => 262_144,
    ],
],
```

The built-in transformer uses Laravel's configured encrypter. New values use
the current application key; Laravel's `APP_PREVIOUS_KEYS` support keeps older
ciphertext readable during rotation. A host may instead provide any
`SensitiveDataTransformer` class with its own current and previous protection
profiles.

Protection covers notification TO/CC/BCC recipients and metadata, provider
event metadata, and scheduled-message payload, TO/CC/BCC recipients, and
metadata. Subjects, sender fields, primary-recipient lookup, notifiable
references, status and provider identities remain ordinary queryable columns.
Use database or disk encryption as the broader at-rest control when those
scalar columns must also be protected.

Protected arrays use a versioned package envelope. Version 2 base64-encodes
opaque transformer output, so arbitrary binary ciphertext remains valid JSON;
version 1 envelopes and legacy plaintext JSON remain readable. Enabling
protection therefore does not require an immediate backfill. New and
deliberately rewritten values are protected through package lifecycle and
scheduler services, whose internal model casts apply the storage codec. Treat
package Eloquent models as host query projections, not arbitrary runtime write
APIs. Direct model or query-builder writes to lifecycle, payload, recipient,
or metadata fields bypass package invariants and are unsupported. Protected
arrays are opaque to database JSON queries.

Disabling protection does not reinterpret existing envelopes as plaintext.
Missing or retired keys, a wrong transformer, a malformed envelope, and
disabled protection with protected history all throw
`UnreadableSensitiveDataException`. Keep the matching transformer and previous
keys available for at least as long as protected rows are retained.

Anonymization is a separate, disabled-by-default stage that removes identifying
content without deleting lifecycle history:

```dotenv
MAIL_NOTIFICATIONS_ANONYMIZATION_ENABLED=true
MAIL_NOTIFICATIONS_ANONYMIZATION_DAYS=180
MAIL_NOTIFICATIONS_SCHEDULED_ANONYMIZATION_ENABLED=true
MAIL_NOTIFICATIONS_SCHEDULED_ANONYMIZATION_DAYS=90
```

```bash
php artisan nvl:mail-notifications:anonymize --dry-run
php artisan nvl:mail-notifications:anonymize --limit=500
php artisan nvl:mail-notifications:anonymize \
    --before=2026-06-30T00:00:00Z \
    --limit=500
```

Notification anonymization clears subject, sender, effective recipients,
primary-recipient lookup, host notifiable association, and metadata. Provider
event anonymization clears only event metadata. Scheduled-message anonymization
clears payload, recipients, failure detail, host notifiable association, and
metadata from allowlisted terminal rows. Row, correlation, queue, provider,
provider-message, and provider-event identities remain available for
idempotency, late webhook correlation, and operational audit.

Each data set has an independent configured or command limit and is updated in
bounded batches. A nullable `redacted_at` marker makes repeated runs honest and
idempotent; provider events are independently marked so a notification with
many events may require more than one run. Scheduled-message anonymization has
its own opt-in switch. Run anonymization before deletion when the host needs a
minimum-data retention window. Pruning remains a separate command and does not
implicitly require or schedule anonymization.

An anonymized notification stays anonymized during later acceptance, local
failure reconciliation, and provider retries. A new provider event correlated
after its notification was anonymized is persisted with no metadata and its own
`redacted_at` marker; an exact retry remains idempotent, while immutable event
identity conflicts still fail closed. These paths never rehydrate cleared
metadata or move the original redaction marker.

## Tokenized Markdown presentation

The package supplies responsive HTML and plain-text components plus a polished
neutral default theme. It contains no host-specific assets, social links,
translation keys, or brand copy. It respects `mail.markdown.theme`,
`mail.markdown.paths`, the configured application name and URL, and every
Laravel mailer/from setting. It does not select or reconfigure a transport.

Configure brand values and safe design tokens under
`mail-notifications.presentation`. Tokens cover typography, canvas and surface
colors, heading/body/muted colors, primary and semantic colors, borders,
container and component radii, content and logo sizing, and text sizes. The
values are validated before they are shared with the Blade theme. Use
`MAIL_BRAND_HEADER_ENABLED=false` or `MAIL_BRAND_FOOTER_ENABLED=false` when an
application wants body-only messages, and configure the remaining
`MAIL_BRAND_*` values without changing package views.

Set `MAIL_NOTIFICATIONS_PRESENTATION_ENABLED=false` to avoid automatically
loading package components, or
`MAIL_NOTIFICATIONS_PRESENTATION_AUTO_LOAD=false` when presentation should be
available only after publishing. Once views are published into
`resources/views/vendor/mail`, they are application-owned Laravel overrides.

## Testing interception

The package honors an existing `mail.testing` configuration first. When it is
absent, `mail-notifications.testing` provides the same environment-aware
settings. Enabling test mode applies Laravel's global recipient override, so
all outbound mail is redirected before tracking captures effective recipients.
The package never bypasses Laravel's configured mailer, queue, or from address.

```dotenv
MAIL_TESTING_ENABLED=true
MAIL_TESTING_TO_ADDRESS=mail-preview@example.test
MAIL_TESTING_TO_NAME="Mail Preview"
MAIL_TESTING_RESPECT_ENV=true
MAIL_TESTING_ENVIRONMENTS=local,testing,staging
```

Keep environment enforcement enabled and exclude production. The strict doctor
reports a missing/invalid recipient, an empty allowlist, unrestricted
interception, or a production allowlist before queue workers are deployed.

## Tracking context

`TrackingContext` contains a stable message category, optional host notifiable
reference, and safe metadata. A host model may implement `MailTrackable` and be
attached without persisting a PHP class name. Its
`mailNotificationType()` alias must be registered in `notifiable_types` or by a
`ProvidesNotifiableTypes` extension before the context can be persisted:

```php
$context = TrackingContext::forCategory('account.password-reset')
    ->forNotifiable($account)
    ->withMetadata(['request_id' => $requestId]);
```

The configured redactor recursively masks sensitive keys before persistence,
redacts object/resource values by default, and bounds nesting, item count,
individual string bytes, and aggregate bytes so cyclic or excessively broad
input cannot exhaust a worker. Key matching lowercases and removes separators,
so defaults such as `api_key`, `two_factor_code`, `verification_code`,
`magic_link`, and `otp` also cover camelCase and kebab-case variants. Rendered
HTML, rendered text, template variables, provider responses, and raw webhook
payloads are never stored by core. BCC recipients remain in storage for
operational correlation and must be protected by host authorization.

## Provider integration

Core has no provider SDK dependency. Register adapters and resolvers through
the configuration-first extension lists above. Advanced integrations may
instead tag container bindings with `ProviderAdapter::CONTAINER_TAG`,
`ProviderMessageIdResolver::TAG`, or `ProvidesNotifiableTypes::TAG`; configured
and tagged extensions are composed into the same registries.

The built-in
`Nvl\MailNotifications\Adapters\MailerSend\MailerSendAdapter` is optional and
unregistered by default. It has no MailerSend SDK or API dependency. Opt in
through `extensions.provider_adapters` and configure its provider-neutral
boundary:

```php
use Nvl\MailNotifications\Adapters\MailerSend\MailerSendAdapter;
use Nvl\MailNotifications\Adapters\MailerSend\MailerSendRemoteWebhookManager;

'extensions' => [
    'provider_adapters' => [MailerSendAdapter::class],
    'webhook_managers' => [MailerSendRemoteWebhookManager::class],
],

'providers' => [
    'mailersend' => [
        'mailers' => ['mailersend'],
        'signing_secret' => env(
            'MAIL_NOTIFICATIONS_MAILERSEND_SIGNING_SECRET',
        ),
        // The package includes MailerSend's fixed webhook.test secret.
        'signature_headers' => ['signature'],
        'message_id_headers' => [
            'x-mailersend-message-id',
            'x-message-id',
        ],
        'timestamp_bounds' => [
            'maximum_past_age_seconds' => 604800,
            'maximum_future_skew_seconds' => 300,
        ],
        'management' => [
            'enabled' => env(
                'MAIL_NOTIFICATIONS_MAILERSEND_MANAGEMENT_ENABLED',
                false,
            ),
            'token' => env('MAIL_NOTIFICATIONS_MAILERSEND_API_TOKEN'),
            'domain_id' => env('MAIL_NOTIFICATIONS_MAILERSEND_DOMAIN_ID'),
            'api_url' => 'https://api.mailersend.com/v1',
            'timeout_seconds' => 10,
            'connect_timeout_seconds' => 3,
            'pagination' => [
                'page_size' => 100,
                'max_pages' => 10,
            ],
            'webhook' => [
                'name' => 'Mail Notifications',
                'url' => env(
                    'MAIL_NOTIFICATIONS_MAILERSEND_WEBHOOK_URL',
                ),
                'events' => [
                    'activity.sent',
                    'activity.delivered',
                    'activity.deferred',
                    'activity.opened',
                    'activity.opened_unique',
                    'activity.clicked',
                    'activity.clicked_unique',
                    'activity.soft_bounced',
                    'activity.hard_bounced',
                    'activity.unsubscribed',
                    'activity.spam_complaint',
                ],
                'enabled' => true,
                'version' => 2,
            ],
        ],
    ],
],
```

MailerSend v2 signs the exact raw request body using lowercase hexadecimal
HMAC-SHA256 in its `Signature` header; its signature algorithm has no signed
timestamp or signature-tolerance input. Independently, the adapter requires the
verified provider `created_at` to be no more than seven days old or five minutes
in the future by default. Both event-time bounds are validated by the strict
doctor. The transport-specific `X-MailerSend-Message-Id` header is accepted
directly. The generic `X-Message-Id` compatibility header is trusted only for
Laravel mailer names listed in `providers.mailersend.mailers`.

MailerSend signs the URL-validation `webhook.test` request with its documented
fixed test secret. The adapter accepts that secret only when the authenticated
root event type is exactly `webhook.test`; it can never authenticate an
activity event. Validation requests return a typed acknowledgement without
creating a delivery event.

The adapter normalizes `sent`, `delivered`, `deferred`, `opened`,
`opened_unique`, `clicked`, `clicked_unique`, `soft_bounced`, `hard_bounced`,
`spam_complaint`, and `unsubscribed`, and extracts MailerSend message identity
from webhook and transport boundaries. The authenticated webhook route remains
host-owned.

The host owns the webhook route and controller. Build the package request from
the original Laravel request after selecting the route's provider:

```php
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvl\MailNotifications\Exceptions\UnmatchedDeliveryEventException;
use Nvl\MailNotifications\Services\WebhookProcessor;
use Nvl\MailNotifications\ValueObjects\WebhookRequest;
use Throwable;

public function __invoke(
    Request $request,
    WebhookProcessor $webhooks,
): Response {
    try {
        $webhooks->process(
            provider: 'mailersend',
            request: WebhookRequest::fromLaravelRequest(
                provider: 'mailersend',
                request: $request,
            ),
        );
    } catch (UnmatchedDeliveryEventException) {
        return response()->noContent(503);
    } catch (DomainException) {
        return response()->noContent(400);
    } catch (Throwable $exception) {
        report($exception);

        return response()->noContent(503);
    }

    return response()->noContent();
}
```

The factory preserves the raw body, HTTP method, and request target (including
the query string), and supplies a case-insensitive header map for provider
signature algorithms. Real HTTP requests must be `POST` with an allowlisted JSON
content type; `application/json` parameters such as `charset=utf-8` are
accepted. Return `204` only after the synchronous processor has durably applied
or explicitly acknowledged the event. The example returns `503` for a recent
tracking-row visibility race or an operational/storage failure so the provider
can retry, and `400` for a verified but invalid request or identity conflict.
Never expose exception messages in the response. If a host adds an asynchronous
boundary, it must durably persist the verified work before returning `2xx`.

The processor deliberately uses explicit typed stages—registered adapter,
signature verifier, normalizer, lifecycle—not Laravel's generic pipeline. The
stages have different contracts and failure semantics, so adapters and
replaceable lifecycle services are the supported extension points.

Remote MailerSend management is disabled and unregistered by default. It uses
Laravel's HTTP client against the official `/v1/webhooks` endpoints and never
calls MailerSend during package registration or boot. Before syncing, deploy
the host-owned HTTPS route above at the exact configured URL and make sure it
can return `2xx` for MailerSend's signed validation probe. Then configure the
manager class, API token, sending-domain ID, unique managed name, HTTPS callback
URL, bounded timeout/pagination, supported activity events, and v2 payload:

```bash
php artisan nvl:mail-notifications:webhooks:sync --provider=mailersend --dry-run
php artisan nvl:mail-notifications:webhooks:sync --provider=mailersend
```

An existing same-name webhook must match exactly; inspect the dry run and add
`--force` to update drift or upgrade a legacy v1 record. More than one
same-name record is ambiguous and fails closed. Removal is scoped to the unique
configured name unless the operator explicitly requests every webhook in the
configured domain:

```bash
php artisan nvl:mail-notifications:webhooks:remove --provider=mailersend --dry-run
php artisan nvl:mail-notifications:webhooks:remove --provider=mailersend
php artisan nvl:mail-notifications:webhooks:remove --provider=mailersend --all --dry-run
```

MailerSend generates an individual signing secret when the webhook is created.
The commands deliberately never print or persist that secret—or any provider
response body. Retrieve the generated secret securely from MailerSend, copy it
to `MAIL_NOTIFICATIONS_MAILERSEND_SIGNING_SECRET`, reload cached configuration,
and run `php artisan nvl:mail-notifications:doctor --strict`. The fixed
`webhook.test` secret is only for URL validation and must never replace the
individual activity signing secret. Activity signing secrets must contain
between 16 and 4,096 bytes; the URL-validation secret is MailerSend's exact
fixed value and is intentionally not environment-configurable.

`WebhookProcessor` enforces the package and webhook switches, bounds the raw
payload before adapter decoding, requires signature verification and
normalization, checks provider identity at every boundary, and applies the
event through the configured lifecycle. Authenticated unsupported events are
acknowledged without lifecycle mutation by default so provider event expansion
does not create retries; set
`MAIL_NOTIFICATIONS_WEBHOOK_UNKNOWN_EVENT_POLICY=reject` for strict rejection.
Acknowledgements dispatch `MailWebhookAcknowledged` as a safe observational
after-commit event. Set
`MAIL_NOTIFICATIONS_WEBHOOKS_ENABLED=false` to stop webhook processing without
changing Laravel mail delivery. When the MailerSend adapter is registered and
webhooks are enabled, the strict package doctor validates its signing secret,
header names, and allowed Laravel mailer names before traffic arrives.

A MailerSend sending domain may also emit valid events for untracked Mailables.
Tracked-message lookup misses use a separate
`webhooks.unmatched_events.policy`: the safe
`retry_then_acknowledge` default retries recent events for five minutes so a
tracking-row visibility race can recover, then acknowledges older misses as
`unmatched_event` without persistence. Set
`MAIL_NOTIFICATIONS_WEBHOOK_UNMATCHED_EVENT_POLICY=reject` for perpetual strict
rejection, or explicitly choose `acknowledge` only when immediate dropping of
tracking races is acceptable. This policy is independent from unknown event
types.

Core correlation is intentionally strict: it uses `correlation_id` when one is
present, otherwise the exact `(provider, provider_message_id)` identity. It
never falls back to recipient address, subject, category, or timing because
those values are not transport-message identities and can match unrelated
deliveries. A custom `TrackingLifecycle` must likewise resolve exactly one
tracked delivery before mutation and throw
`AmbiguousDeliveryEventException` when multiple candidates remain.

The package-owned schema makes both supported identities unique, so ambiguity
indicates a broken/adopted schema or a defective custom lifecycle. The
processor acknowledges that verified webhook once as `ambiguous_event`
without lifecycle mutation or retry, dispatches the privacy-safe after-commit
`WebhookEventAmbiguous`, and also dispatches `MailWebhookAcknowledged`. Alert
on the typed ambiguity event and repair the identity constraint or lifecycle;
do not add recipient fallback.

Provider events are idempotent by provider and event identifier. Status changes
are monotonic, stale events do not move state backward, and terminal failures
cannot be replaced by older success events. Reusing an event identifier with
different immutable event data is rejected as an identity conflict instead of
being treated as a harmless retry. Late delivery milestones may backfill their
timestamp without weakening a more advanced status.

One tracking record represents one transport message. TO, CC, and BCC arrays
capture the effective wire recipients, while provider lifecycle status remains
message-level. Send a separate tracked message per recipient when the provider
emits recipient-specific delivery outcomes that must remain independently
queryable.

## Failure policy

`fail_closed` is the default. A failure to create the pending record prevents
the opted-in message from reaching transport. Set
`MAIL_NOTIFICATIONS_FAILURE_POLICY=fail_open` when delivery must continue even
if pre-send tracking persistence is unavailable.

The same policy applies when a custom mailer contract hides the transport:

| Policy | Custom mailer contract |
| --- | --- |
| `fail_closed` | Emits `MailTrackingFailed` and stops before delivery. |
| `fail_open` | Emits `MailTrackingFailed` and delivers once without tracking. |

Do not synchronously send fail-closed tracked mail inside an open host database
transaction on the same storage connection. The pending write joins that
transaction, so a later host rollback could erase tracking after the transport
has accepted the message. Dispatch queued mail after commit or configure an
independent tracking connection when the surrounding business transaction must
remain open.

Laravel invokes `failed()` after a queued Mailable exhausts its attempts. The
concern implements that hook and synchronizes a terminal failure even when the
job failed before normal send/bootstrap. It reuses a completed attempt when one
exists; otherwise it creates one deterministic fallback row fenced by the queue
reference. It never selects a pending attempt by recipient, subject, timing, or
queue-group ordering, so one duplicate worker cannot mark another worker's
in-flight attempt failed. Repeated callbacks are idempotent through the fallback
primary/correlation identity.

The fallback snapshot contains only declarative envelope addresses, subject,
configured sender, safe tracking context, and redacted metadata. It never
renders or stores the message body and stores only the exception class, not its
message or trace.

When a Mailable already defines an application failure hook, explicitly compose
the package helper:

```php
use Throwable;

public function failed(?Throwable $exception): void
{
    $this->recordMailTrackingFailure($exception);

    // Host-owned alerting or cleanup...
}
```

`recordMailTrackingFailure()` accepts Laravel's nullable manual-failure value
and never lets tracking synchronization replace the original queue failure. A
custom `newQueuedJob()` override must delegate to its parent so the queue
reference is assigned before serialization. The callback is necessarily
best-effort when Laravel cannot deserialize the queued command, or when the
worker is terminated before failure callbacks can run.

Once a transport has accepted a message, a tracking update failure never throws
back into the mail send path because doing so could cause a queue retry and a
duplicate delivery. The package emits `MailTrackingFailed` with identifiers
and exception type only. When provider identity was resolved before the update
failed, the event also carries that safe `ProviderMessageId`. A host may enqueue
an idempotent repair that reconstructs the event's `TrackingAttempt` and calls
`TrackingLifecycle::accepted()` with that identity; the package database
lifecycle accepts the same identity repeatedly. Repair tracking state only—
never resend the Mailable. Package lifecycle events are observational:
exceptions from host listeners are reported through Laravel's exception handler
but do not cancel delivery, replace a transport exception, or force a webhook
retry.

## Operations

Inspect configuration, retention and anonymization bounds, sensitive-storage
round trips, production interception safety, required column types, lengths,
nullability and defaults, exact database status invariants, case-sensitive
provider identities, foreign-key ownership, UTC behavior, and operational
indexes without mutation:

```bash
php artisan nvl:mail-notifications:doctor --strict --format=json
```

The host owns mail transport configuration, queue policy, webhook routes,
provider credentials, authorization, scheduled-mail command frequency,
retention/anonymization scheduling, encryption-key retention, published view
customizations, and application-facing projections.

After changing environment-backed settings or configured extension classes in
production, rebuild Laravel's configuration cache and restart queue workers.

When installed through `nvl/laravel-suite` with NVL Data enabled, the provider
registers its public backed enums as TypeScript sources. Strict generation can
therefore resolve contracts such as
`Nvl.MailNotifications.Enums.MailDeliveryStatus` without a host
`LiteralTypeScriptType` replacement:

```bash
php artisan nvl:data:types:generate --fail-on-warning
php artisan nvl:data:types:check --fail-on-warning
```

Package services and model-managed timestamps are generated, stored, compared,
and restored in UTC with microsecond precision, independently from the host
application timezone. The configured database/session timezone must still be
UTC so database-generated values and session comparisons share that contract
across SQLite, PostgreSQL, and MySQL-family deployments.

## Development and verification

Package-owned Eloquent factories are available to consumer test suites without
host factory discovery. Their defaults use reserved `example.test` addresses,
omit subjects and host notifiable references, and preserve package lifecycle
invariants:

```php
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;

$notification = MailNotification::factory()->delivered()->create();
$event = MailNotificationEvent::factory()
    ->for($notification)
    ->bounced()
    ->create();
$scheduledMessage = ScheduledMailMessage::factory()->processing()->create();
```

Notification and provider-event factories expose a named state for every
`MailDeliveryStatus`. Scheduled-message factories expose `pending`,
`processing`, `sent`, `failed`, and `cancelled` states plus `due` and
`retrying` operational fixtures.

```bash
composer validate --strict
composer format:test
composer analyse
composer test
```

The isolated Testbench suite verifies package boot, opt-in and opt-out behavior,
global and per-mailer switches, effective recipients, queue serialization,
failure policy, transition monotonicity, event idempotency, redaction, and
forbidden dependency boundaries. It also verifies database-enforced enum
allowlists, protected-array legacy reads and rotation failures, independently
bounded anonymization, consolidated creator schemas, and forward-only rollback.
Scheduled-mail
coverage includes registry versioning, normalized recipients, token-fenced
transitions, mailer selection, retry exhaustion, claim recovery, commands, and
observational events. On
PostgreSQL, a dedicated committed-fixture test synchronizes two worker
processes at the `SELECT ... FOR UPDATE` boundary and proves that every due row
is claimed once with a unique fence. It then recovers and reclaims one lease to
prove that stale-token finalization cannot overwrite the newer claim. A second
two-worker proof synchronizes duplicate queued-failure callbacks immediately
before fallback insertion and asserts one failed row plus exactly one started,
failed, and status-change event. These tests run `migrate:fresh` only when the
PostgreSQL database name starts with `nvl_mail_notifications_test_`; SQLite and
environments without the optional process primitives skip only these
database-specific proofs. The suite also boots a complete configuration-first
host fixture with custom services, notifiable aliases, two message-ID resolver
styles, authenticated webhook normalization, and payload/runtime guards.

## License

NVL Mail Notifications is open-sourced software licensed under the MIT license.
