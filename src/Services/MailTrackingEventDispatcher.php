<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use RuntimeException;
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
     * @param  Closure(): DatabaseTransactionsManager  $transactions
     */
    public function __construct(
        private Closure $events,
        private Closure $transactions,
        private ExceptionHandler $exceptions,
        private DatabaseManager $database,
        private Repository $config,
    ) {}

    /**
     * Dispatch an event after commit without allowing host listeners to alter delivery.
     */
    public function dispatch(object $event): void
    {
        try {
            $connection = $this->database->connection(
                $this->storageConnectionName(),
            );
        } catch (Throwable $exception) {
            $this->reportSafely($exception);
            $this->dispatchNow($event);

            return;
        }

        $this->dispatchWhenStorageCommits($event, $connection);
    }

    /**
     * Bind dispatch to the exact active storage transaction and its parents.
     */
    private function dispatchWhenStorageCommits(
        object $event,
        Connection $connection,
    ): void {
        if ($connection->transactionLevel() === 0) {
            $this->dispatchNow($event);

            return;
        }

        try {
            $transactions = ($this->transactions)();
            $connectionName = $connection->getName();
            $applicable = $transactions
                ->callbackApplicableTransactions()
                ->filter(
                    static fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connectionName,
                )
                ->last();

            if ($applicable instanceof DatabaseTransactionRecord) {
                $applicable->addCallback(
                    function () use ($connection, $event): void {
                        $this->dispatchWhenStorageCommits(
                            $event,
                            $connection,
                        );
                    },
                );

                return;
            }

            $connectionIsDeliberatelyExcluded = $transactions
                ->getPendingTransactions()
                ->contains(
                    static fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connectionName,
                );

            if ($connectionIsDeliberatelyExcluded) {
                $this->dispatchNow($event);

                return;
            }

            $this->reportSafely(new RuntimeException(
                'The active mail notification storage transaction is not registered with Laravel\'s transaction manager.',
            ));
        } catch (Throwable $exception) {
            $this->reportSafely($exception);
        }
    }

    /**
     * Resolve the optional configured storage connection name.
     */
    private function storageConnectionName(): ?string
    {
        $configured = $this->config->get(
            'mail-notifications.storage.connection',
        );

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : null;
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
