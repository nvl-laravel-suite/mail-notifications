<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\MailHistory;
use Nvl\MailNotifications\Services\ScheduledMailReader;

function portableHistoryReader(): GenericUser
{
    return new GenericUser(['id' => 'administrator-1', 'email' => 'administrator@example.test']);
}

beforeEach(function (): void {
    config()->set('nvl-mail-notifications.management.authorization.callback', static fn (): bool => true);
    config()->set('nvl-mail-notifications.management.scheduled_authorization.callback', static fn (): bool => true);
});

it('reports a malformed tracked identifier as not found', function (): void {
    app(MailHistory::class)->get(portableHistoryReader(), 'not-a-uuid');
})->throws(ModelNotFoundException::class);

it('reports a malformed scheduled identifier as not found', function (): void {
    app(ScheduledMailReader::class)->get(portableHistoryReader(), 'not-a-uuid');
})->throws(ModelNotFoundException::class);

it('searches history case-insensitively with literal wildcard characters', function (): void {
    MailNotification::factory()->create(['subject' => 'Quarterly Report']);
    MailNotification::factory()->create(['subject' => 'Invoice_2026 ready']);
    MailNotification::factory()->create(['subject' => 'Invoice 2026 draft']);
    $history = app(MailHistory::class);

    expect($history->paginate(portableHistoryReader(), ['search' => 'quarterly'])['total'])->toBe(1)
        ->and($history->paginate(portableHistoryReader(), ['search' => '_'])['total'])->toBe(1)
        ->and($history->paginate(portableHistoryReader(), ['search' => '%'])['total'])->toBe(0)
        ->and($history->suggest(portableHistoryReader(), 'quarterly'))->toHaveCount(1)
        ->and($history->suggest(portableHistoryReader(), '_'))->toHaveCount(1)
        ->and($history->suggest(portableHistoryReader(), '%'))->toHaveCount(0);
});

it('compares history date filters as UTC instants with microseconds', function (): void {
    MailNotification::factory()->create(['created_at' => CarbonImmutable::parse('2026-11-20T08:30:00Z')]);
    $history = app(MailHistory::class);

    expect($history->paginate(portableHistoryReader(), ['from' => CarbonImmutable::parse('2026-11-20 10:00:00', 'Europe/Athens')])['total'])->toBe(1)
        ->and($history->paginate(portableHistoryReader(), ['to' => CarbonImmutable::parse('2026-11-20T08:30:00Z')])['total'])->toBe(1)
        ->and($history->paginate(portableHistoryReader(), ['to' => CarbonImmutable::parse('2026-11-20T08:30:00.5Z')])['total'])->toBe(1)
        ->and($history->paginate(portableHistoryReader(), ['to' => CarbonImmutable::parse('2026-11-20T08:29:59.5Z')])['total'])->toBe(0);
});

it('compares scheduled date filters as UTC instants with microseconds', function (): void {
    $scheduledFor = CarbonImmutable::parse('2026-11-20T08:30:00Z');
    ScheduledMailMessage::factory()->create(['scheduled_for' => $scheduledFor, 'available_at' => $scheduledFor]);
    $reader = app(ScheduledMailReader::class);

    expect($reader->paginate(portableHistoryReader(), ['from' => CarbonImmutable::parse('2026-11-20 10:00:00', 'Europe/Athens')])['total'])->toBe(1)
        ->and($reader->paginate(portableHistoryReader(), ['to' => CarbonImmutable::parse('2026-11-20T08:30:00Z')])['total'])->toBe(1)
        ->and($reader->paginate(portableHistoryReader(), ['to' => CarbonImmutable::parse('2026-11-20T08:30:00.5Z')])['total'])->toBe(1)
        ->and($reader->paginate(portableHistoryReader(), ['to' => CarbonImmutable::parse('2026-11-20T08:29:59.5Z')])['total'])->toBe(0);
});

it('rejects history and scheduled pages whose offset overflows', function (): void {
    MailNotification::factory()->create();

    expect(fn () => app(MailHistory::class)->paginate(portableHistoryReader(), ['page' => PHP_INT_MAX]))
        ->toThrow(InvalidArgumentException::class, 'page is too large')
        ->and(fn () => app(ScheduledMailReader::class)->paginate(portableHistoryReader(), ['page' => PHP_INT_MAX]))
        ->toThrow(InvalidArgumentException::class, 'page is too large');
});

it('returns no records for an ordinary page beyond the last one', function (): void {
    MailNotification::factory()->create();
    $scheduledFor = CarbonImmutable::parse('2026-11-20T08:30:00Z');
    ScheduledMailMessage::factory()->create(['scheduled_for' => $scheduledFor, 'available_at' => $scheduledFor]);

    $history = app(MailHistory::class)->paginate(portableHistoryReader(), ['page' => 3, 'per_page' => 1]);
    $scheduled = app(ScheduledMailReader::class)->paginate(portableHistoryReader(), ['page' => 3, 'per_page' => 1]);

    expect($history['items'])->toBe([])
        ->and($history['total'])->toBe(1)
        ->and($scheduled['items'])->toBe([])
        ->and($scheduled['total'])->toBe(1);
});
