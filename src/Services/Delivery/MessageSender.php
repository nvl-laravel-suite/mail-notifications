<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Enums\EmailMessageType;
use Nvl\MailNotifications\Exceptions\ManagedDeliveryConflict;
use Nvl\MailNotifications\Models\EmailAttempt;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\MarketingOptOut;
use Nvl\MailNotifications\Models\RecipientSuppression;
use Nvl\MailNotifications\Models\SenderIdentity;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Throwable;

/** Executes trusted queued submission intents and host-authorized lifecycle commands.
 * @internal
 */
class MessageSender
{
    public function __construct(private ProviderManager $providers, private WebhookProcessor $webhooks, private MessageStatusProjector $statuses, private AttachmentRetention $attachments, private ProviderSendingAllowance $allowance, private MailTesting $testing, private ClientWebhookPublisher $clientWebhooks) {}

    /**
     * Claim and recheck a queued intent, then record provider acceptance, rejection or uncertainty without blind resubmission.
     */
    public function send(string $id): void
    {
        $attempt = DB::connection(ManagedConfiguration::connection())->transaction(function () use ($id): ?EmailAttempt {
            $message = EmailMessage::query()->with(['client.owner', 'providerAccount', 'recipients', 'messageAssets'])->lockForUpdate()->find($id);
            if (! $message || ! in_array($message->status, ['accepted', 'queued'], true)) {
                return null;
            }
            if (! ManagedConfiguration::sendingEnabled()) {
                $message->update(['dispatched_at' => null]);

                return null;
            }
            if ($message->send_at?->isFuture() || $message->next_attempt_at?->isFuture()) {
                return null;
            }
            $reason = $this->blockingReason($message);
            if ($reason !== null) {
                $message->update(['status' => 'blocked', 'error_code' => $reason, 'error_message' => 'Submission was blocked before contacting the provider.', 'dispatched_at' => null, 'next_attempt_at' => null]);
                $message->attempts()->where('status', 'pending')->update(['status' => 'blocked', 'error_code' => $reason, 'completed_at' => now()]);
                $this->attachments->markDisposition($message);
                $this->clientWebhooks->publish($message, 'message.blocked', 'blocked:'.$message->id.':'.Str::uuid());

                return null;
            }
            $delay = $this->allowance->reserve($message->providerAccount ?? throw new \RuntimeException('The provider account is unavailable.'));
            if ($delay > 0) {
                $message->update(['status' => 'accepted', 'next_attempt_at' => now()->addSeconds($delay), 'dispatched_at' => null, 'lease_until' => null, 'error_code' => 'provider_rate_limited', 'error_message' => 'Waiting for this provider account sending allowance.']);
                $this->clientWebhooks->publish($message, 'message.retry_scheduled', 'allowance:'.$message->id.':'.$message->next_attempt_at?->timestamp);

                return null;
            }
            $attempt = $message->attempts()->where('status', 'pending')->first();
            if (! $attempt) {
                $attempt = $message->attempts()->create(['provider_account_id' => $message->provider_account_id, 'number' => ($message->attempts()->orderByDesc('number')->first()->number ?? 0) + 1, 'status' => 'pending']);
            }
            $attempt->update(['status' => 'sending', 'started_at' => now()]);
            $message->update(['status' => 'sending', 'lease_until' => now()->addMinutes(2), 'error_code' => null, 'error_message' => null]);
            $attempt->setRelation('emailMessage', $message);
            $attempt->setRelation('providerAccount', $message->providerAccount);

            return $attempt;
        });
        if (! $attempt) {
            return;
        }
        try {
            $result = $this->providers->providerFor($attempt->providerAccount ?? throw new \RuntimeException('The provider account is unavailable.'))->send($attempt->emailMessage ?? throw new \RuntimeException('The accepted message is unavailable.'), $attempt);
        } catch (Throwable $exception) {
            Log::error('Provider submission interrupted.', [
                'exception_class' => get_debug_type($exception),
                'message_id' => $attempt->email_message_id,
                'attempt_id' => $attempt->id,
                'provider_account_id' => $attempt->provider_account_id,
            ]);
            $result = new ProviderResult('unknown', null, 'submission_interrupted', 'Provider submission could not be confirmed.');
        }
        if ($result->status !== 'submitted' && ($result->response['status'] ?? null) === 429) {
            try {
                $this->allowance->pause($attempt->providerAccount ?? throw new \RuntimeException('The provider account is unavailable.'), $result->retryAfter ?? 60);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        DB::connection(ManagedConfiguration::connection())->transaction(function () use ($attempt, $result): void {
            $message = EmailMessage::query()->lockForUpdate()->findOrFail($attempt->email_message_id);
            $attempt = EmailAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if (($attempt->status === 'submitted' || $message->error_code === 'restore_reconciliation_required') && $result->status !== 'submitted') {
                return;
            }
            $wasSubmitted = $attempt->status === 'submitted';
            $attempt->update(['status' => $result->status, 'provider_message_id' => $result->providerMessageId ?? $attempt->provider_message_id, 'retryable' => $result->retryable, 'response' => $result->response, 'error_code' => $result->errorCode, 'error_message' => $result->errorMessage, 'completed_at' => now()]);
            if ($result->errorCode === 'all_suppressed') {
                $message->recipients()->whereIn('delivery_status', ['pending', 'submitted'])->update(['delivery_status' => 'suppressed', 'suppressed_at' => now()]);
            }
            foreach ($result->suppressedEmails as $email) {
                $message->recipients()->where('email', mb_strtolower($email))->whereIn('delivery_status', ['pending', 'submitted'])->update(['delivery_status' => 'suppressed', 'suppressed_at' => now()]);
            }
            if ($result->status === 'submitted') {
                $message->update(['status' => $message->completed_at ? $message->status : 'submitted', 'submitted_at' => $message->submitted_at ?? now(), 'lease_until' => null, 'next_attempt_at' => null, 'error_code' => null, 'error_message' => null]);
                $message->recipients()->where('delivery_status', 'pending')->update(['delivery_status' => 'submitted']);
                $this->statuses->refresh($message);
            } else {
                $count = $message->attempts()->whereNotNull('started_at')->count();
                $retry = $result->status === 'failed' && $result->retryable && $count < 3;
                $message->update(['status' => $retry ? 'accepted' : $result->status, 'next_attempt_at' => $retry ? now()->addSeconds(max([60, 300][$count - 1], $result->retryAfter ?? 0)) : null, 'dispatched_at' => null, 'lease_until' => null, 'error_code' => $result->errorCode, 'error_message' => $result->errorMessage]);
            }
            $this->attachments->markDisposition($message);
            if (! $wasSubmitted) {
                $topic = $result->status === 'submitted' ? 'message.submitted' : ($message->next_attempt_at !== null ? 'message.retry_scheduled' : 'message.'.$message->status);
                $this->clientWebhooks->publish($message, $topic, 'attempt:'.$attempt->id.':'.$topic);
            }
        });
        try {
            $this->webhooks->reconcileAttempt($attempt->id);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Cancel eligible pending work; the host must authorize the message before invoking this internal command.
     */
    public function cancel(EmailMessage $message): EmailMessage
    {
        return DB::connection(ManagedConfiguration::connection())->transaction(function () use ($message): EmailMessage {
            $message = EmailMessage::query()->lockForUpdate()->findOrFail($message->id);
            if (! (in_array($message->status, ['accepted', 'queued', 'blocked', 'failed'], true) && ! $message->attempts()->whereIn('status', ['sending', 'submitted', 'unknown'])->exists())) {
                throw new ManagedDeliveryConflict('This message can no longer be cancelled.');
            }
            $message->update(['status' => 'cancelled', 'cancelled_at' => now(), 'dispatched_at' => null, 'next_attempt_at' => null]);
            $message->attempts()->where('status', 'pending')->update(['status' => 'cancelled', 'completed_at' => now()]);
            $this->attachments->markDisposition($message);
            $this->clientWebhooks->publish($message, 'message.cancelled', 'cancelled:'.$message->id);

            return $message;
        });
    }

    /**
     * Accept a safe explicit retry only after lifecycle, retained-byte and current eligibility checks.
     */
    public function retry(EmailMessage $message): EmailMessage
    {
        return DB::connection(ManagedConfiguration::connection())->transaction(function () use ($message): EmailMessage {
            $message = EmailMessage::query()->lockForUpdate()->findOrFail($message->id);
            if (! (in_array($message->status, ['failed', 'blocked'], true) && ! $message->attempts()->whereIn('status', ['sending', 'submitted', 'unknown'])->exists())) {
                throw new ManagedDeliveryConflict('Only definite failures without a possible provider acceptance can be retried.');
            }
            $this->attachments->prepareRetry($message);
            $message->update(['status' => 'accepted', 'next_attempt_at' => null, 'dispatched_at' => null, 'error_code' => null, 'error_message' => null]);
            $this->clientWebhooks->publish($message, 'message.retry_scheduled', 'manual-retry:'.$message->id.':'.Str::uuid());

            return $message;
        });
    }

    private function blockingReason(EmailMessage $message): ?string
    {
        if (($testingReason = $this->testing->blockingReason($message)) !== null) {
            return $testingReason;
        }
        if (! $message->client || ! $message->providerAccount || ! $message->client->isActive() || ! $message->client->owner->isActive() || ! $message->providerAccount->isActive() || $message->providerAccount->owner_id !== $message->client->owner_id) {
            return 'account_inactive';
        }
        $sender = SenderIdentity::whereKey($message->sender_identity_id)->where('owner_id', $message->client->owner_id)->where('provider_account_id', $message->provider_account_id)->where('email', $message->from_email)->first();
        if (! $sender?->isVerified()) {
            return 'sender_unverified';
        }
        if (RecipientSuppression::where('provider_account_id', $message->provider_account_id)->whereIn('email', $message->recipients->pluck('email'))->whereNull('released_at')->exists()) {
            return 'recipient_suppressed';
        }

        if ($message->type === EmailMessageType::Marketing && MarketingOptOut::query()->where('client_id', $message->client_id)->whereIn('email', $message->recipients->pluck('email'))->whereNull('released_at')->exists()) {
            return 'marketing_opted_out';
        }

        return $this->attachments->availabilityReason($message);
    }
}
