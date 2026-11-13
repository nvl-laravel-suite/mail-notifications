<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Nvl\MailNotifications\Models\EmailAttempt;
use Nvl\MailNotifications\Models\EmailEvent;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\EmailRecipient;
use Nvl\MailNotifications\Models\MarketingOptOut;
use Nvl\MailNotifications\Models\RecipientSuppression;
use Nvl\MailNotifications\Models\WebhookReceipt;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Throwable;

/**
 * Matches verified provider activity to delivery attempts and projects recipient/message state.
 *
 * @phpstan-type ProviderEventData array{id: string, message_id: string, recipient: string, correlation_id: string|null, tags: list<string>, meta: array<string, mixed>}
 *
 * @internal
 */
class WebhookProcessor
{
    public function __construct(private MessageStatusProjector $statuses, private AttachmentRetention $attachments, private ClientWebhookPublisher $clientWebhooks) {}

    private const EVENTS = ['submitted', 'sent', 'delivered', 'soft_bounced', 'hard_bounced', 'deferred', 'opened', 'opened_unique', 'clicked', 'clicked_unique', 'unsubscribed', 'spam_complaint', 'suppressed'];

    /**
     * Revisit bounded unmatched provider evidence against locally stored delivery attempts.
     */
    public function reconcile(int $limit = 100, ?string $providerAccountId = null): int
    {
        $query = WebhookReceipt::query()->whereNull('processed_at')->whereIn('status', ['pending', 'unmatched', 'failed'])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
        if ($providerAccountId !== null) {
            $query->where('provider_account_id', $providerAccountId);
        }
        $ids = $query->orderBy('updated_at')->orderBy('id')->limit(min(1000, max(1, $limit)))->pluck('id');
        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new \UnexpectedValueException('Webhook receipt identifiers must be strings.');
            }
            try {
                $this->process($id);
            } catch (Throwable $exception) {
                report($exception);
                $this->retryLater($id, 'failed', 'processing_failed');
            }
        }

        return $ids->count();
    }

    /**
     * Match retained provider evidence to one existing submission attempt without widening its client scope.
     */
    public function reconcileAttempt(string $attemptId): void
    {
        $attempt = EmailAttempt::query()->with('providerAccount')->whereIn('status', ['sending', 'submitted', 'unknown'])->find($attemptId);
        if ($attempt === null) {
            return;
        }
        $receipts = WebhookReceipt::query()->where('provider_account_id', $attempt->provider_account_id)
            ->whereNull('processed_at')->whereIn('status', ['pending', 'unmatched', 'failed'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();
        $provider = $attempt->providerAccount?->provider;
        foreach ($receipts as $receipt) {
            try {
                $event = $this->event($receipt);
                if ($event === null) {
                    continue;
                }
                $data = $event['data'];
                $correlated = in_array($provider, ['sendgrid', 'mailgun'], true) && ($data['correlation_id'] ?? null) === $attempt->id;
                $tagged = $provider === 'mailersend' && in_array('attempt-'.$attempt->id, $data['tags'], true);
                $identified = $provider === 'sendgrid'
                    ? in_array($data['message_id'], $attempt->remote_message_ids ?? [], true)
                    : $attempt->provider_message_id !== null && ($data['message_id']) === $attempt->provider_message_id;
                $receipt->setRelation('providerAccount', $attempt->providerAccount);
                if (($correlated || $tagged || $identified) && $this->matchAttempt($receipt, $data)?->id === $attempt->id) {
                    $this->process($receipt->id);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * Normalize and persist an already verified provider receipt with replay and out-of-order safeguards.
     */
    public function process(string $id): void
    {
        $receipt = WebhookReceipt::find($id);
        if (! $receipt || $receipt->processed_at) {
            return;
        }
        $event = $this->event($receipt);
        if ($event === null) {
            return;
        }
        $type = $event['type'];
        $data = $event['data'];
        $attempt = $this->matchAttempt($receipt, $data);
        if (! $attempt) {
            $this->retryLater($id, 'unmatched', 'message_unmatched');

            return;
        }
        $connection = DB::connection(ManagedConfiguration::connection());
        $connection->transaction(function () use ($id, $attempt, $data, $type, $event, $connection): void {
            $message = EmailMessage::query()->lockForUpdate()->findOrFail($attempt->email_message_id);
            $receipt = WebhookReceipt::query()->lockForUpdate()->findOrFail($id);
            if ($receipt->processed_at) {
                return;
            }
            $previousStatus = $message->status;
            $attempt = EmailAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $isSendGrid = $receipt->providerAccount?->provider === 'sendgrid';
            if (! $isSendGrid && $attempt->provider_message_id !== null && $attempt->provider_message_id !== $data['message_id']) {
                $receipt->update(['status' => 'unmatched', 'error_code' => 'correlation_conflict', 'processed_at' => now(), 'next_attempt_at' => null, 'processing_attempts' => $receipt->processing_attempts + 1]);

                return;
            }
            $recipient = $message->recipients()->where('email', mb_strtolower($data['recipient']))->lockForUpdate()->first();
            if (! $recipient) {
                $receipt->update(['status' => 'unmatched', 'error_code' => 'recipient_unmatched', 'processed_at' => now(), 'next_attempt_at' => null, 'processing_attempts' => $receipt->processing_attempts + 1]);

                return;
            }
            if (EmailEvent::where('provider_account_id', $receipt->provider_account_id)->where('provider_event_id', $data['id'])->exists()) {
                $receipt->update(['status' => 'duplicate', 'processed_at' => now()]);

                return;
            }
            $at = CarbonImmutable::parse($event['created_at'])->utc();
            $metadata = array_intersect_key($data['meta'], array_flip(['bounce_code', 'bounce_type', 'suppression_reason', 'unsubscribe_reason']));
            if ($isSendGrid && $data['message_id'] !== '') {
                $metadata['provider_message_id'] = $data['message_id'];
            }
            try {
                $event = $connection->transaction(fn (): EmailEvent => EmailEvent::create(['provider_account_id' => $receipt->provider_account_id, 'email_message_id' => $message->id, 'email_recipient_id' => $recipient->id, 'webhook_receipt_id' => $receipt->id, 'provider_event_id' => $data['id'], 'type' => $type, 'occurred_at' => $at, 'metadata' => $metadata]));
            } catch (UniqueConstraintViolationException) {
                $receipt->update(['status' => 'duplicate', 'processed_at' => now()]);

                return;
            }
            $attemptUpdates = ['status' => 'submitted', 'retryable' => false, 'completed_at' => $attempt->completed_at ?? now()];
            if ($isSendGrid) {
                if ($data['message_id'] !== '') {
                    $attemptUpdates['remote_message_ids'] = array_slice(array_values(array_unique([...($attempt->remote_message_ids ?? []), $data['message_id']])), 0, 100);
                }
            } else {
                $attemptUpdates['provider_message_id'] = $data['message_id'];
            }
            $attempt->update($attemptUpdates);
            $message->update(['submitted_at' => $message->submitted_at ?? now(), 'lease_until' => null, 'next_attempt_at' => null, 'error_code' => null, 'error_message' => null]);
            $message->recipients()->where('delivery_status', 'pending')->update(['delivery_status' => 'submitted']);
            $recipient->refresh();
            $this->applyEvent($recipient, $type, $at, $metadata, $receipt->provider_account_id);
            $this->statuses->refresh($message);
            $this->attachments->markDisposition($message);
            $this->clientWebhooks->publish($message, 'message.'.$type, 'provider:'.$event->id, $recipient->id, $at);
            if ($message->status !== $previousStatus && in_array($message->status, ['submitted', 'failed'], true) && $type !== $message->status) {
                $this->clientWebhooks->publish($message, 'message.'.$message->status, 'provider-status:'.$event->id, null, $at);
            }
            $receipt->update(['status' => 'processed', 'processed_at' => now(), 'error_code' => null, 'next_attempt_at' => null, 'processing_attempts' => $receipt->processing_attempts + 1]);
        });
    }

    /**
     * Keep an unapplied receipt for a bounded number of later runs, then close it with the given status.
     */
    private function retryLater(string $id, string $status, string $errorCode): void
    {
        DB::connection(ManagedConfiguration::connection())->transaction(function () use ($id, $status, $errorCode): void {
            $receipt = WebhookReceipt::query()->lockForUpdate()->findOrFail($id);
            if ($receipt->processed_at !== null) {
                return;
            }
            $attempts = $receipt->processing_attempts + 1;
            $expired = $attempts >= 12 || $receipt->created_at?->lessThanOrEqualTo(now()->subHours(6));
            $receipt->update(['status' => $status, 'error_code' => $errorCode, 'processing_attempts' => $attempts,
                'processed_at' => $expired ? now() : null, 'next_attempt_at' => $expired ? null : now()->addMinutes(5)]);
        });
    }

    /**
     * Read the receipt's supported activity event, closing a receipt that is another event type or unreadable.
     *
     * @return array{type: string, created_at: string, data: ProviderEventData}|null
     */
    private function event(WebhookReceipt $receipt): ?array
    {
        try {
            $event = $this->payload($receipt);
        } catch (\UnexpectedValueException) {
            $receipt->update(['status' => 'failed', 'error_code' => 'invalid_payload', 'processed_at' => now(), 'next_attempt_at' => null]);

            return null;
        }
        if ($event === null) {
            $receipt->update(['status' => 'ignored', 'processed_at' => now()]);
        }

        return $event;
    }

    /**
     * Decide the event type first; only a supported activity event must carry the activity fields.
     *
     * @return array{type: string, created_at: string, data: ProviderEventData}|null
     */
    private function payload(WebhookReceipt $receipt): ?array
    {
        $payload = $receipt->payload;
        $type = $payload['type'] ?? null;
        if (! is_string($type)) {
            throw new \UnexpectedValueException('The stored provider event payload is invalid.');
        }
        $type = str_starts_with($type, 'activity.') ? substr($type, 9) : '';
        if (! in_array($type, self::EVENTS, true)) {
            return null;
        }
        $createdAt = $payload['created_at'] ?? null;
        $data = $payload['data'] ?? [];
        if (! is_string($createdAt) || ! is_array($data)) {
            throw new \UnexpectedValueException('The stored provider event payload is invalid.');
        }
        $id = $data['id'] ?? '';
        $messageId = $data['message_id'] ?? null;
        $recipient = $data['recipient'] ?? '';
        $correlationId = $data['correlation_id'] ?? null;
        if (! is_string($id) || ! is_string($messageId) || ! is_string($recipient) || ($correlationId !== null && ! is_string($correlationId))) {
            throw new \UnexpectedValueException('The stored provider event identifiers are invalid.');
        }
        $rawTags = $data['tags'] ?? [];
        $rawMeta = $data['meta'] ?? [];
        if (! is_array($rawTags) || ! is_array($rawMeta)) {
            throw new \UnexpectedValueException('The stored provider event metadata is invalid.');
        }
        $tags = [];
        foreach ($rawTags as $tag) {
            if (! is_string($tag)) {
                throw new \UnexpectedValueException('The stored provider event tag is invalid.');
            }
            $tags[] = $tag;
        }
        $meta = [];
        foreach (['bounce_code', 'bounce_type', 'suppression_reason', 'unsubscribe_reason'] as $key) {
            if (array_key_exists($key, $rawMeta)) {
                $meta[$key] = $rawMeta[$key];
            }
        }

        return ['type' => $type, 'created_at' => $createdAt, 'data' => ['id' => $id, 'message_id' => $messageId, 'recipient' => $recipient, 'correlation_id' => $correlationId, 'tags' => $tags, 'meta' => $meta]];
    }

    /** @param ProviderEventData $data */
    private function matchAttempt(WebhookReceipt $receipt, array $data): ?EmailAttempt
    {
        $query = EmailAttempt::where('provider_account_id', $receipt->provider_account_id)->whereIn('status', ['sending', 'submitted', 'unknown']);
        if ($receipt->providerAccount?->provider === 'sendgrid') {
            if ($data['message_id'] !== '' && ($attempt = (clone $query)->whereJsonContains('remote_message_ids', $data['message_id'])->first())) {
                return $attempt;
            }
            if (is_string($data['correlation_id'] ?? null)) {
                return (clone $query)->whereKey($data['correlation_id'])->first();
            }

            return null;
        }
        $attempt = (clone $query)->where('provider_message_id', $data['message_id'])->first();
        if ($attempt) {
            return $attempt;
        }
        if ($receipt->providerAccount?->provider === 'mailgun' && is_string($data['correlation_id'] ?? null)) {
            $attempt = (clone $query)->whereKey($data['correlation_id'])->first();
            if ($attempt) {
                return $attempt;
            }
        }
        foreach ($data['tags'] as $tag) {
            if (preg_match('/\Aattempt-([0-9a-f-]{36})\z/i', $tag, $matches)) {
                $attempt = (clone $query)->whereKey($matches[1])->first();
                if ($attempt) {
                    return $attempt;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $metadata */
    private function applyEvent(EmailRecipient $recipient, string $type, CarbonImmutable $at, array $metadata, string $accountId): void
    {
        $engagement = match ($type) {
            'opened', 'opened_unique' => 'opened',
            'clicked', 'clicked_unique' => 'clicked',
            default => null,
        };
        if ($engagement !== null) {
            $field = $engagement.'_at';
            $count = $engagement === 'opened' ? 'open_count' : 'click_count';
            $recipient->update([$field => $recipient->$field && $recipient->$field->lessThan($at) ? $recipient->$field : $at, $count => $recipient->$count + 1]);

            return;
        }
        $reason = match ($type) {
            'hard_bounced' => 'hard_bounce', 'spam_complaint' => 'complaint', 'unsubscribed' => 'unsubscribed',
            'suppressed' => match ($metadata['suppression_reason'] ?? '') {
                'hard_bounced' => 'hard_bounce', 'spam_complained' => 'complaint', 'unsubscribed' => 'unsubscribed', default => null
            },
            default => null,
        };
        if ($reason === 'unsubscribed') {
            $optOut = MarketingOptOut::firstOrCreate(['client_id' => ($recipient->emailMessage ?? throw new \RuntimeException('The accepted message is unavailable.'))->client_id, 'email' => $recipient->email], ['released_at' => null]);
            MarketingOptOut::query()->whereKey($optOut->id)
                ->where(fn ($query) => $query->whereNull('released_at')->orWhere('released_at', '<', $at))
                ->update(['released_at' => null]);
        } elseif ($reason !== null) {
            RecipientSuppression::updateOrCreate(['provider_account_id' => $accountId, 'email' => $recipient->email], ['reason' => $reason, 'released_at' => null]);
        }
        $timestampField = match ($type) {
            'hard_bounced', 'soft_bounced' => 'bounced_at', 'delivered' => 'delivered_at', 'spam_complaint' => 'complained_at', 'unsubscribed' => 'unsubscribed_at', 'suppressed' => 'suppressed_at', default => null,
        };
        $updates = [];
        if ($timestampField !== null && ($recipient->$timestampField === null || $recipient->$timestampField->greaterThan($at))) {
            $updates[$timestampField] = $at;
        }
        if (in_array($type, ['spam_complaint', 'unsubscribed'], true)) {
            $recipient->update($updates);

            return;
        }
        $rank = ['pending' => 0, 'submitted' => 1, 'sent' => 2, 'deferred' => 3, 'soft_bounced' => 3, 'delivered' => 4, 'hard_bounced' => 5, 'suppressed' => 5];
        $newRank = $rank[$type] ?? 0;
        $oldRank = $rank[$recipient->delivery_status] ?? 0;
        if ($newRank > $oldRank || ($newRank === $oldRank && ($recipient->delivery_event_at === null || $at->greaterThanOrEqualTo($recipient->delivery_event_at)))) {
            $updates['delivery_status'] = $type;
            $updates['delivery_event_at'] = $at;
        }
        $recipient->update($updates);
    }
}
