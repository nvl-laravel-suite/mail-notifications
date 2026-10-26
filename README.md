# NVL Mail Notifications

`nvl/mail-notifications` tracks Laravel mail delivery, processes verified provider webhooks, schedules delivery, and protects retained history. It targets PHP 8.4 and Laravel 13. The host application owns mailables, copy, themes, HTTP routes, request parsing, admin resources, and frontend types.

## Install

```sh
composer require nvl/mail-notifications:^7.0
php artisan migrate
```

Laravel discovers `Nvl\MailNotifications\Providers\MailNotificationsServiceProvider`. Vendor migrations load by default. If the host publishes and owns them, disable vendor migration loading before migrating. Configure tracking, providers, privacy, scheduling, optional Settings, and retention in `nvl-mail-notifications` config; run `php artisan nvl:mail-notifications:doctor --strict` after installation.

## Domain operations

| Service | Purpose |
| --- | --- |
| `MailHistory` | Authorized `get`, `getByProviderMessage`, `paginate`, `forNotifiable`, `statistics`, and `suggest` reads with bounded safe arrays |
| `ScheduledMailReader` | Authorized `get`, `paginate`, and `statistics` reads with protected payloads omitted |
| `ScheduledMailScheduler` | Complete `schedule`, `cancel`, and `reschedule` transactions |
| `ScheduledMailProcessor` | Claim and deliver a bounded batch after its claim commits |
| `ScheduledMailRecovery` | Recover stale claims and retry according to configured policy |
| `MailHistoryAnonymizer` / `MailRetentionPruner` | Bounded retained-history privacy operations |
| `WebhookProcessor` | Verify a named provider request before normalization and lifecycle persistence |
| `DatabaseTrackingLifecycle` | Idempotent status transitions and provider event storage |
| `MailTestingInterceptor` | Global testing-address redirection when explicitly enabled |

Host authorization is required for history and scheduled reads. Query filters are native shaped arrays with allowlisted keys and bounded pagination. Returned arrays exclude protected recipient lists, payloads, metadata, claim tokens, and raw provider event metadata. Keep raw package models inside trusted host code.

The scheduler accepts `ScheduleMailData`, which validates recipients, factory identity, timing, and payload before persistence. Host applications register `ScheduledMessageFactory` implementations to build their own mailables. Native ownership captures producer authority for scheduled work; claims, retries, and provider callbacks restore authority from verified persisted state. Background identity alone does not grant a host permission.

Register provider adapters for message IDs and webhook verification. Pass a `WebhookRequest` built from the host request to `WebhookProcessor::process($provider, $request)`. Signature verification precedes normalization and database effects. Provider replay and out-of-order events follow the retained lifecycle rules. The package does not expose an HTTP endpoint.

Settings is optional. Configured delivery profiles work without its provider; an explicitly requested Settings-backed profile fails clearly if the provider is absent. Tracking and testing interception remain independent of host mail presentation. Sensitive storage can encrypt eligible persisted fields when explicitly enabled and configured.

See [contributing](CONTRIBUTING.md) for package development and [events](docs/events.md) for lifecycle events.
