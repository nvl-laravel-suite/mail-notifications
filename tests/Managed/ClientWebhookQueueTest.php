<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Nvl\MailNotifications\Jobs\DeliverClientWebhook;
use Nvl\MailNotifications\Models\ClientWebhookDelivery;
use Nvl\MailNotifications\Services\Delivery\ClientWebhookPublisher;
use Nvl\MailNotifications\Services\ManagedDoctor;

it('reports a callback that cannot be queued durably and keeps it pending for recovery', function (): void {
    Exceptions::fake();
    Queue::fake([DeliverClientWebhook::class]);
    config()->set('queue.default', 'sync');
    $delivery = ClientWebhookDelivery::factory()->create();

    expect(app(ClientWebhookPublisher::class)->dispatch($delivery->id))->toBeFalse();

    expect($delivery->fresh()->status)->toBe('pending')
        ->and($delivery->fresh()->dispatched_at)->toBeNull();
    Queue::assertNothingPushed();
    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'durable queue connection'));
});

it('fails callback recovery once when due callbacks have no durable queue', function (): void {
    Exceptions::fake();
    Queue::fake([DeliverClientWebhook::class]);
    config()->set('queue.default', 'sync');

    expect(app(ClientWebhookPublisher::class)->recover())->toBe(0);

    $deliveries = ClientWebhookDelivery::factory()->count(2)->create();

    expect(fn () => app(ClientWebhookPublisher::class)->recover())->toThrow(RuntimeException::class, 'durable queue connection');
    expect($deliveries->first()->fresh()->status)->toBe('pending');
    Queue::assertNothingPushed();
    Exceptions::assertNothingReported();
});

it('warns in the doctor when client callbacks have no durable queue connection', function (): void {
    config()->set('queue.default', 'sync');
    $check = collect(app(ManagedDoctor::class)->inspect())->firstWhere('key', 'managed.client_webhook_queue');

    expect($check->passed)->toBeFalse()
        ->and($check->severity)->toBe('warning')
        ->and($check->message)->toContain('durable queue connection', 'stay pending');

    config()->set('queue.default', 'database');
    $check = collect(app(ManagedDoctor::class)->inspect())->firstWhere('key', 'managed.client_webhook_queue');

    expect($check->passed)->toBeTrue();
});
