<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use Nvl\MailNotifications\Models\EmailAttempt;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\WebhookReceipt;
use Nvl\MailNotifications\Services\Delivery\WebhookProcessor;

/** @return array{0: EmailMessage, 1: EmailAttempt} */
function submittedProviderAttempt(string $providerMessageId = 'provider-message-1'): array
{
    $message = EmailMessage::factory()->create(['status' => 'submitted', 'submitted_at' => now()]);
    $message->recipients()->create(['type' => 'to', 'email' => 'reader@example.com', 'delivery_status' => 'submitted']);
    $attempt = EmailAttempt::factory()->for($message)->create(['provider_account_id' => $message->provider_account_id, 'status' => 'submitted', 'provider_message_id' => $providerMessageId]);

    return [$message, $attempt];
}

/** @param array<string, mixed> $payload */
function providerReceipt(EmailMessage $message, array $payload, int $ageInMinutes = 0): WebhookReceipt
{
    $receipt = WebhookReceipt::factory()->create(['provider_account_id' => $message->provider_account_id, 'payload' => $payload]);
    WebhookReceipt::query()->whereKey($receipt->id)->update(['created_at' => now()->subMinutes($ageInMinutes), 'updated_at' => now()->subMinutes($ageInMinutes)]);

    return $receipt;
}

/** @return array<string, mixed> */
function deliveredProviderEvent(string $eventId, string $providerMessageId = 'provider-message-1'): array
{
    return ['type' => 'activity.delivered', 'created_at' => now()->toIso8601String(), 'data' => ['id' => $eventId, 'message_id' => $providerMessageId, 'recipient' => 'reader@example.com']];
}

it('ignores a verified provider event of another type that carries no message id', function (): void {
    [$message] = submittedProviderAttempt();
    $receipt = providerReceipt($message, ['type' => 'sender_identity.verified', 'created_at' => now()->toIso8601String(), 'data' => ['id' => 'identity-event']]);

    app(WebhookProcessor::class)->process($receipt->id);

    expect($receipt->fresh()->status)->toBe('ignored')
        ->and($receipt->fresh()->processed_at)->not->toBeNull();
});

it('closes an unreadable activity receipt as failed and still reconciles the receipts behind it', function (): void {
    [$message] = submittedProviderAttempt();
    $unreadable = providerReceipt($message, ['type' => 'activity.delivered', 'created_at' => now()->toIso8601String(), 'data' => ['id' => 'no-message-id', 'recipient' => 'reader@example.com']], 10);
    $delivered = providerReceipt($message, deliveredProviderEvent('delivered-event'));

    expect(app(WebhookProcessor::class)->reconcile())->toBe(2);

    expect($unreadable->fresh()->status)->toBe('failed')
        ->and($unreadable->fresh()->error_code)->toBe('invalid_payload')
        ->and($unreadable->fresh()->processed_at)->not->toBeNull()
        ->and($delivered->fresh()->status)->toBe('processed')
        ->and($message->recipients()->first()->delivery_status)->toBe('delivered');
});

it('reconciles a submission attempt past unrelated and unreadable receipts of the same account', function (): void {
    [$message, $attempt] = submittedProviderAttempt();
    $delivered = providerReceipt($message, deliveredProviderEvent('delivered-event'), 10);
    $other = providerReceipt($message, ['type' => 'sender_identity.verified', 'created_at' => now()->toIso8601String(), 'data' => ['id' => 'identity-event']], 5);
    $unreadable = providerReceipt($message, ['type' => 'activity.delivered', 'created_at' => now()->toIso8601String(), 'data' => ['id' => 'no-message-id']]);

    app(WebhookProcessor::class)->reconcileAttempt($attempt->id);

    expect($delivered->fresh()->status)->toBe('processed')
        ->and($other->fresh()->status)->toBe('ignored')
        ->and($unreadable->fresh()->status)->toBe('failed')
        ->and($message->recipients()->first()->delivery_status)->toBe('delivered');
});

it('reports a receipt that cannot be applied, retries it on a bounded schedule and then closes it as failed', function (): void {
    $this->freezeTime();
    Exceptions::fake();
    [$message] = submittedProviderAttempt();
    $broken = providerReceipt($message, [...deliveredProviderEvent('broken-event'), 'created_at' => 'not-a-date'], 10);
    $delivered = providerReceipt($message, deliveredProviderEvent('delivered-event'));

    expect(app(WebhookProcessor::class)->reconcile())->toBe(2);

    expect($broken->fresh()->status)->toBe('failed')
        ->and($broken->fresh()->error_code)->toBe('processing_failed')
        ->and($broken->fresh()->processing_attempts)->toBe(1)
        ->and($broken->fresh()->processed_at)->toBeNull()
        ->and($broken->fresh()->next_attempt_at?->getTimestamp())->toBe(now()->addMinutes(5)->getTimestamp())
        ->and($delivered->fresh()->status)->toBe('processed');
    Exceptions::assertReportedCount(1);
    expect(app(WebhookProcessor::class)->reconcile())->toBe(0);

    $this->travel(6)->hours();
    expect(app(WebhookProcessor::class)->reconcile())->toBe(1);

    expect($broken->fresh()->status)->toBe('failed')
        ->and($broken->fresh()->processed_at)->not->toBeNull()
        ->and($broken->fresh()->next_attempt_at)->toBeNull();
    expect(app(WebhookProcessor::class)->reconcile())->toBe(0);
});

it('reconciles only the unprocessed receipt states that are ever written', function (): void {
    [$message] = submittedProviderAttempt();
    $states = [];
    foreach (['pending', 'unmatched', 'failed', 'received'] as $index => $status) {
        $states[$status] = providerReceipt($message, deliveredProviderEvent('event-'.$status, 'unknown-message-'.$index));
        $states[$status]->update(['status' => $status]);
    }

    expect(app(WebhookProcessor::class)->reconcile())->toBe(3)
        ->and($states['received']->fresh()->processing_attempts)->toBe(0)
        ->and($states['pending']->fresh()->processing_attempts)->toBe(1);
});
