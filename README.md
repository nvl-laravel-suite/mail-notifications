# NVL Mail Notifications

Embed prepared HTML/CSS/text email delivery in Laravel with client/owner isolation, provider accounts, verified senders, private attachments, business references, events and signed callbacks. Targets PHP 8.4+ and Laravel 13. Host applications own content rendering, customer permissions, HTTP authentication/routes and admin UI.

| Integration | Install |
| --- | --- |
| Own sending, provider credentials, mail records, Media storage and workers in this app | `nvl/mail-notifications` |
| Call an existing Mail Notifications service from another app | `nvl/mail-notification-bridge` with a service-issued client ID/API token and service URL |

No Template Maker, template catalogue, frontend package or broker is required. The package also preserves its optional ordinary Laravel transport-tracking and factory-scheduling APIs.

## Install and select capabilities

```sh
composer require nvl/mail-notifications:^7.0
php artisan vendor:publish --tag=nvl-mail-notifications-config --no-interaction
```

Laravel discovers `Nvl\MailNotifications\Providers\MailNotificationsServiceProvider`. A prepared-content host can use:

```php
return [
    'managed' => ['enabled' => true],
    'tracking' => ['enabled' => false],
    'scheduling' => ['enabled' => false],
];
```

Configure storage, actual database connections, native Media authorization/scanning and sender accounts before accepting mail. Choose **one** migration owner: vendor-loaded schema (default) or a reviewed host-published copy with `migrations.enabled=false`. The single initial alpha mail migration creates the complete mail graph; its rollback destroys mail tables and data. Review [installation](resources/boost/skills/nvl-mail-notifications/references/installation.md) and [database](docs/database.md) before running migrations. Configure before boot and preserve applied history.

## Use the delivery boundary

`Nvl\MailNotifications\Services\ManagedDelivery::send($client, $input, $idempotencyKey, $authorize)` accepts validated rendered content for an existing client. The live host authorizer must deny with false or an exception; the authenticated `nvl-send-managed-mail` Gate is the documented alternative. Owner defaults and client settings select the eligible verified account/sender. Provider credentials and client/owner authority are never message inputs.

A host Mailable can use `Laravel\Concerns\SubmitsMailDelivery`: `toMailDelivery($options)` prepares a clone without submission, and `submitMailDelivery(...)` performs one-step acceptance. For preview parity, prepare once and submit that exact array through `ManagedDelivery`. Keep idempotency keys/payloads stable on retry; later host edits do not alter already accepted content. See the [tested English/Bulgarian Mailable and private-attachment recipe](docs/delivery.md#attachments-and-native-mailables).

Prepared-message `send_at` works independently of the older Mailable scheduler. Provider submission is not proof of inbox delivery. Unknown outcomes require reconciliation before any resend. Client marketing preferences and provider suppressions remain separate.

## Consumer guides

- [Architecture, minimal profile and capability boundaries](docs/architecture.md)
- [Native input, live authorization, Mailable preparation and attachments](docs/delivery.md)
- [Database relationships, migration ownership and Media evidence](docs/database.md)
- [Workers, provider sync, verified webhooks, callbacks, pause and recovery](docs/operations.md)
- [Optional Laravel tracking, scheduling, authorized history and privacy](resources/boost/skills/nvl-mail-notifications/references/laravel-tracking.md)
- [Public PHP signatures and array shapes](resources/boost/skills/nvl-mail-notifications/references/api.md)
- [Optional tracking/scheduling PHP event contracts](docs/events.md)
- [Upgrade and previous namespace identities](UPGRADING.md)

Canonical implementations live in `src/Models`, `src/Services`, `src/Jobs`, `src/Enums` and `src/Contracts`; factories/seeders use `database/`. Previous `Managed` identities remain compatibility aliases for callers and stored jobs/morphs. Do not add another implementation tree or rewrite persisted identities for a namespace change.

## Agent skill and publication

The installed Composer package includes `resources/boost/skills/nvl-mail-notifications` with task-based routing, agent metadata, portable guides and the generated API catalogue. Laravel Boost can discover it; [installation](resources/boost/skills/nvl-mail-notifications/references/installation.md#skills) documents Boost installation/update. Direct publication copies the complete self-contained skill:

```sh
php artisan vendor:publish --tag=nvl-mail-notifications-skills --no-interaction
```

Keep application conventions separate from vendor guidance. Update the skills/config tags separately; blanket provider publication can duplicate retimestamped migrations. Copying only `SKILL.md` discards the references agents need.

## Operations and verification

Use a durable queue/worker and host-owned recovery/retention schedules. Run `php artisan nvl:mail-notifications:doctor --strict` to inspect local declarations; it does not establish real provider delivery, public callback reachability or malware-scanner effectiveness. The [operations guide](docs/operations.md) names the exact bounded commands and failure semantics.

Optional Laravel factory scheduling uses `ScheduledMailProcessor` to submit due Mailables and `ScheduledMailRecovery` to recover expired claims; see the [tracking and scheduling guide](resources/boost/skills/nvl-mail-notifications/references/laravel-tracking.md).

Delivery environment inputs use the `NVL_MAIL_NOTIFICATIONS_` prefix. Existing `MAIL_` delivery inputs remain fallback aliases; an explicit canonical value takes precedence.

Provider catalogue metadata is seeded explicitly from the host seeder with `$this->call(\Nvl\MailNotifications\Database\Seeders\IntegrationSeeder::class);`. Optional provider marks can be published with `php artisan vendor:publish --tag=nvl-mail-notifications-assets`; message/catalogue bytes use native Media.

Tests exercise native validation, authority, isolation, outer-commit dispatch, replay, uncertainty, storage and documentation recipes. Run the owning package suite and static analysis in development. Real provider credentials, public webhook delivery and the deployment's queue/database matrix need their own integration evidence; a local doctor or catalogue is not a production certificate.

See [contributing](CONTRIBUTING.md), [changelog](CHANGELOG.md) and [security](SECURITY.md).
