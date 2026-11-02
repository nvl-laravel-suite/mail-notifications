<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Nvl\MailNotifications\Jobs\SendEmailMessage;
use Nvl\MailNotifications\Models\EmailAttempt;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Services\Delivery\MessageDispatcher;
use Nvl\MailNotifications\Services\Delivery\MessageSender;
use Nvl\MailNotifications\Services\Delivery\ProviderResult;
use Nvl\MailNotifications\Services\Delivery\Providers\MailerSendProvider;
use Nvl\MailNotifications\Support\ManagedConfiguration;

use function Pest\Laravel\mock;

test('unexpected submission errors emit only safe identifiers and remain uncertain without resending', function () {
    $this->freezeTime();
    $message = EmailMessage::factory()->create();
    $attempt = EmailAttempt::factory()->for($message)->create(['provider_account_id' => $message->provider_account_id, 'status' => 'pending']);
    $message->recipients()->create(['type' => 'to', 'email' => 'reader@example.com', 'delivery_status' => 'pending']);
    mock(MailerSendProvider::class)->shouldReceive('send')->once()->andThrow(new RuntimeException('secret-api-key private-body provider-response'));
    Log::spy();

    app(MessageSender::class)->send($message->id);
    app(MessageSender::class)->send($message->id);
    app(MessageDispatcher::class)->dispatchPending();

    $this->assertDatabaseHas(ManagedConfiguration::table('email_messages'), ['id' => $message->id, 'status' => 'unknown', 'next_attempt_at' => null, 'error_code' => 'submission_interrupted']);
    $this->assertDatabaseHas(ManagedConfiguration::table('email_attempts'), ['id' => $attempt->id, 'status' => 'unknown', 'retryable' => false]);
    $this->assertDatabaseCount(ManagedConfiguration::table('email_attempts'), 1);
    Log::shouldHaveReceived('error')->once()->with('Provider submission interrupted.', [
        'exception_class' => RuntimeException::class,
        'message_id' => $message->id,
        'attempt_id' => $attempt->id,
        'provider_account_id' => $message->provider_account_id,
    ]);
});

test('disabled delivery preserves queued intent without provider calls and resumes exactly once', function (string $key): void {
    Queue::fake([SendEmailMessage::class]);
    $message = EmailMessage::factory()->create(['status' => 'queued', 'dispatched_at' => now()]);
    $message->recipients()->create(['type' => 'to', 'email' => 'reader@example.test', 'delivery_status' => 'pending']);
    $attempt = EmailAttempt::factory()->for($message)->create(['provider_account_id' => $message->provider_account_id, 'status' => 'pending']);
    $hash = $message->request_hash;
    $provider = mock(MailerSendProvider::class);
    $provider->shouldReceive('send')->once()->andReturn(new ProviderResult('submitted', 'resumed-message'));
    config([$key => false]);

    app(MessageSender::class)->send($message->id);
    expect($message->fresh()->status)->toBe('queued')
        ->and($message->fresh()->dispatched_at)->toBeNull()
        ->and($attempt->fresh()->status)->toBe('pending')
        ->and(app(MessageDispatcher::class)->dispatchMessage($message->id))->toBeFalse();
    Queue::assertNothingPushed();

    config([$key => true]);
    expect(app(MessageDispatcher::class)->dispatchMessage($message->id))->toBeTrue();
    app(MessageSender::class)->send($message->id);
    app(MessageSender::class)->send($message->id);
    expect($message->fresh()->status)->toBe('submitted')
        ->and($message->fresh()->request_hash)->toBe($hash)
        ->and($message->attempts()->count())->toBe(1);
    Queue::assertPushed(SendEmailMessage::class, 1);
})->with(['nvl-mail-notifications.enabled', 'nvl-mail-notifications.managed.enabled', 'nvl-mail-notifications.managed.delivery.enabled']);

test('package testing enabled after acceptance prevents the original recipient from being contacted', function (): void {
    $message = EmailMessage::factory()->create(['status' => 'queued', 'testing_interception' => null]);
    $message->recipients()->create(['type' => 'to', 'email' => 'customer@example.test', 'delivery_status' => 'pending']);
    config(['mail.testing' => null, 'nvl-mail-notifications.testing' => [
        'enabled' => true, 'to_address' => 'qa@example.test', 'to_name' => 'QA',
        'respect_environment' => true, 'environments' => ['testing'],
    ]]);
    mock(MailerSendProvider::class)->shouldNotReceive('send');

    app(MessageSender::class)->send($message->id);

    expect($message->fresh()->status)->toBe('blocked')
        ->and($message->fresh()->error_code)->toBe('testing_interception_required');
});

test('expired provider claims still reconcile uncertainty while new sending is disabled', function (): void {
    $this->freezeTime();
    $message = EmailMessage::factory()->create(['status' => 'sending', 'lease_until' => now()->subMinute()]);
    $attempt = EmailAttempt::factory()->for($message)->create(['provider_account_id' => $message->provider_account_id, 'status' => 'sending']);
    config(['nvl-mail-notifications.managed.enabled' => false]);
    mock(MailerSendProvider::class)->shouldNotReceive('send');

    expect(app(MessageDispatcher::class)->dispatchPending())->toBe(0)
        ->and($message->fresh()->status)->toBe('unknown')
        ->and($message->fresh()->error_code)->toBe('worker_interrupted')
        ->and($attempt->fresh()->status)->toBe('unknown');
});
