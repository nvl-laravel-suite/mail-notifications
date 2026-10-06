<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Events\ConnectionCommitCallbacks;
use Throwable;

/**
 * Dispatches observational package events safely after active transactions commit.
 */
final readonly class MailTrackingEventDispatcher
{
    /**
     * Create the after-commit package event dispatcher.
     *
     * @param  Closure(): Dispatcher  $events
     */
    public function __construct(
        private Closure $events,
        private ConnectionCommitCallbacks $commits,
        private ExceptionHandler $exceptions,
        private DatabaseManager $database,
    ) {}

    /**
     * Dispatch an event after commit without allowing host listeners to alter delivery.
     */
    public function dispatch(object $event): void
    {
        try {
            $connection = $this->database->connection(
                PackageStorage::connection('mail-notifications'),
            );
            $this->commits->afterCommit($connection, function () use ($event): void {
                $this->dispatchNow($event);
            });
        } catch (Throwable $exception) {
            $this->reportSafely($exception);

        }

    }

    /**
     * Dispatch an event when no active transaction can defer it again.
     */
    private function dispatchNow(object $event): void
    {
        try {
            ($this->events)()->dispatch($event);
        } catch (Throwable $exception) {
            $this->reportSafely($exception);
        }
    }

    /**
     * Report listener failures without making reporting part of mail delivery.
     */
    private function reportSafely(Throwable $exception): void
    {
        try {
            $this->exceptions->report($exception);
        } catch (Throwable $reportingException) {
            error_log(sprintf(
                'Mail notification event reporting failed [%s -> %s].',
                $exception::class,
                $reportingException::class,
            ));
        }
    }
}
