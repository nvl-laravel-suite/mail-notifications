# Optional Laravel tracking and factory scheduling

These APIs observe ordinary Laravel transport mail or schedule host factories; they are separate from prepared-content acceptance. Keep them disabled in a delivery-only service. Use [installation](installation.md) for providers/publish/migrations and [API](api.md) for complete public signatures/value objects. Service namespace is `Nvl\MailNotifications\Services`; native scheduling/provider values are in `Nvl\MailNotifications\ValueObjects`.

## Capability and extension selection

| Need | Native capability |
| --- | --- |
| Track a host Laravel mailable | Implement `TrackableMessage`, use `Laravel\Concerns\TracksMailDelivery` |
| Native transport/status adapter | `TrackingLifecycle` / `DatabaseTrackingLifecycle`: `begin`, `accepted`, `failed`, `queuedFailure`, `apply` with verified native values |
| Authorized retained history | `MailHistory`: `get`, `getByProviderMessage`, `paginate`, `forNotifiable`, `statistics`, `suggest` |
| Schedule/edit future delivery | `ScheduledMailScheduler`: `schedule`, `cancel`, `reschedule`, `replacePending` |
| Safe scheduled admin queries | `ScheduledMailReader`: `get`, `paginate`, `statistics` |
| Worker processing/recovery | `ScheduledMailProcessor::process(limit:null): int`, `ScheduledMailRecovery::recover(limit:null): int` |
| Receive provider webhook | `WebhookProcessor::process(provider, WebhookRequest)` |
| Anonymize/prune | `MailHistoryAnonymizer::anonymize`, `MailRetentionPruner::prune` |
| Existing storage | `MailAdoption` with a reviewed native manifest |
| Provider administration | Registered `RemoteWebhookManager` capability and sync/remove commands |

Host extensions are configured under `extensions.provider_adapters`, `message_id_resolvers`, `notifiable_type_providers`, `scheduled_message_factories`, `webhook_managers` or their native container tags. Implement each API contract rather than replacing whole domain workflows. A `ProviderAdapter` names a provider; extra webhook/message-ID contracts define the actual supported capabilities. Register exact `MailTrackable` aliases/types for canonical notifiables. Provider names are not arbitrary authentication claims.

## Tracking a host mailable

```php
use Illuminate\Mail\Mailable;
use Nvl\MailNotifications\Contracts\TrackableMessage;
use Nvl\MailNotifications\Laravel\Concerns\TracksMailDelivery;

final class ReminderMail extends Mailable implements TrackableMessage
{
    use TracksMailDelivery;

    public function build(): static
    {
        return $this->subject('Account reminder')->html('<p>Your account reminder.</p>');
    }
}
```

Send it through Laravel's configured mailer normally. `withMailTracking`, `withoutMailTracking`, `forNotifiable(MailTrackable)` and `withTrackingMetadata(array)` control this message's context. If the host overrides `failed`, call `recordMailTrackingFailure` to retain native terminal queue-failure synchronization. Metadata is bounded/redacted JSON; bodies are host presentation, not persisted tracking metadata.

Global `enabled`, `tracking.enabled`, excluded mailers and `tracking.failure_policy` (`fail_closed` / `fail_open`) determine eligibility/failure behavior. Do not silently weaken failure policy when recording fails. Testing redirection is a separate global control; configure it deliberately through the package and `mail.testing` integration, even for untracked messages.

## Verified factory, schedule and safe-read workflow

A host factory has `alias(): string`, `supportsVersion(int): bool`, `validate(int, array): void`, `make(ScheduledMessageData): Mailable`. Define a stable named class and register it in `extensions.scheduled_message_factories` before resolution:

```php
use Illuminate\Mail\Mailable;
use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\MailNotifications\ValueObjects\ScheduledMessageData;

final class ReminderFactory implements ScheduledMessageFactory
{
    public function alias(): string { return 'account.reminder'; }
    public function supportsVersion(int $version): bool { return $version === 1; }

    public function validate(int $version, array $payload): void
    {
        if ($version !== 1 || $payload !== []) {
            throw new \InvalidArgumentException('Reminder version 1 accepts an empty payload.');
        }
    }

    public function make(ScheduledMessageData $message): Mailable
    {
        return new ReminderMail;
    }
}
```

With `scheduling.enabled=true`, registered factory and admitted native owner scope:

```php
use Carbon\CarbonImmutable;
use Nvl\MailNotifications\Services\ScheduledMailScheduler;
use Nvl\MailNotifications\Services\ScheduledMailReader;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\ScheduleMailData;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;

$message = app(ScheduledMailScheduler::class)->schedule(new ScheduleMailData(
    factoryAlias: 'account.reminder',
    payloadVersion: 1,
    payload: [],
    recipients: new ScheduledRecipients([new Recipient('ada@example.com', 'Ada')]),
    scheduledFor: CarbonImmutable::now('UTC')->addHour(),
    metadata: ['purpose' => 'account-reminder'],
));
$detail = app(ScheduledMailReader::class)->get($actor, $message->id);
```

The read requires an explicit host-authorized actor, separately from producer authority. Factories validate both before persistence and delivery, and must not choose recipients. Native Ownership requires stable class-resolved factories; do not capture ambient request/owner state in an anonymous factory. A factory's `make` receives persisted validated data, normalized recipients, UTC timing and attempt number.

`ScheduleMailData` accepts `factoryAlias`, positive `payloadVersion`, object `payload`, `ScheduledRecipients`, `scheduledFor`, optional `NotifiableReference`, object `metadata`, nullable `maxAttempts` and nullable `availableAt`. Alias is 1–128 trimmed characters; attempts are 1–100 when supplied; initial availability cannot follow intended delivery. Times normalize to UTC. Null availability defaults to delivery time; null maxAttempts uses configuration; null notifiable means none. Recipient names may be null. At least one TO is required and TO/CC/BCC are deduplicated with TO precedence. Payload bytes/fanout/metadata budgets still apply.

`cancel(messageId)` / `reschedule(messageId, scheduledFor, availableAt:null)` / `replacePending(messageId, ScheduleMailData)` operate only on pending unclaimed rows. Replacement writes the complete host-owned scheduling fields; it is not a partial/null-dropping patch. No caller-supplied claim token, status, attempts or native owner override is accepted.

## Safe query contracts and actor policy

Bind `MailNotificationReadAuthorization` and `ScheduledMailReadAuthorization`, or configure the corresponding `management.authorization.callback` / `management.scheduled_authorization.callback`. They receive the native read ability, explicit actor and optional selected model. Defaults deny. Readers resolve fresh canonical Eloquent actor state before policy; non-Eloquent identity freshness remains external. Policies must deliberately grant any administrative breadth.

Mail filters are `search`, `status` (`MailDeliveryStatus`), `mailer`, `messageCategory`, `from`, `to` (`CarbonImmutable`), `acceptedOnly`, `failedOnly` (exact booleans), `sort`, `direction`, `page`, `per_page`. Scheduled filters are `status` (`ScheduledMailStatus`), `factoryAlias`, `notifiable` (`NotifiableReference`), `from`, `to`, `dueOnly`, `sort`, `direction`, `page`, `per_page`. Unknown keys/wrong types reject; see API query values for allowed sorts and bounds. Optional null filters mean no criterion, not authority expansion beyond current owner scope.

Reader pages return `items`, `current_page`, `last_page`, `per_page`, `total`; statistics and suggestions are bounded. Detail/history omit payloads, complete recipients, BCC/CC lists, metadata, provider raw events, claim tokens and errors. Scheduled projection exposes only the primary recipient. `getByProviderMessage` requires `ProviderMessageId`; `forNotifiable` requires a registered exact `NotifiableReference`. Keep raw models inside trusted host code and use safe arrays for responses.

## Webhooks, committed claims and recovery

Build `WebhookRequest::fromLaravelRequest($provider, $request)` and call `WebhookProcessor::process($provider, $webhook)`. The provider in both arguments must match. The original body/headers are retained for signature verification before normalization or persisted effects. Host endpoints choose middleware/HTTP mapping. Built-in MailerSend uses configured signing secret, headers, message-ID extraction and timestamp bounds; provider-specific event envelopes must stay intact.

Provider replay/out-of-order events use native lifecycle receipts, immutable delivery identities and scoped notifiable ownership. Do not set delivery statuses or recreate receipt handling in controllers. Scheduling mutations transact on actual source storage and observational events follow outer commit. Processor/claimer must begin outside an ambient scheduled source transaction, and transport checks the actual source again after factory preparation. Fenced claim tokens and recovery preserve ownership of retries. Transport acceptance cannot provide distributed exactly-once delivery.

Configure `scheduling.delivery_profile` as an approved Laravel mailer in `allowed_delivery_profiles`, or leave it null for the default. A `delivery_profile_setting` explicitly selects optional Settings; it needs the loaded provider and a defined setting, with the same allowlist. Do not globally mutate mail configuration in reused workers. Native owner envelopes restore producer context; historical/background identity alone grants no host business permission.

## Privacy, models and operational commands

`anonymize(dryRun:false, limit:null, cutoff:null)` / `prune(...)` return native result values for independently bounded notification/schedule data sets. Cutoffs are nullable `CarbonImmutable`, normalized UTC and cannot be in the future. Configure admitted statuses/ages and scheduled privacy explicitly; anonymization must be enabled. Preview with `dryRun:true` before applying selected retention. Privacy metadata budgets/redaction and optional sensitive-storage encryption remain active.

Choose `models.notification` / `models.scheduled_message` before boot with compatible `MailNotification` / `ScheduledMailMessage` subclasses. Keep UUIDs, required casts, UTC timestamps, canonical hooks, ownership and resource classification. Fixed event/receipt/identity/lock rows share the package-configured actual connection and native tables; subclass-only storage changes reject. Model selection does not adopt existing data.

Commands: `nvl:mail-notifications:doctor --strict`, `nvl:mail-notifications:process-scheduled --limit=100`, `nvl:mail-notifications:recover-scheduled --limit=100`, `nvl:mail-notifications:anonymize --dry-run`, `nvl:mail-notifications:prune --dry-run`, `nvl:mail-notifications:adopt`, `nvl:mail-notifications:webhooks:sync --dry-run`, `nvl:mail-notifications:webhooks:remove --dry-run`. Exact additional flags live in API. Run workers/scheduling within reviewed owner worklists; remote webhook mutation remains an explicit host operation. Map scheduling/tracking/retention, authorization, invalid-input and replay failures without disclosing secrets. Host mailables control language and localized copy; scheduling stores explicit template payload/version rather than ambient locale assumptions.
