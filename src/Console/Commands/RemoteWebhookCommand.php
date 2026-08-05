<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use DomainException;
use Illuminate\Console\Command;
use Nvl\MailNotifications\Contracts\RemoteWebhookManager;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\Services\RemoteWebhookManagerRegistry;
use Nvl\MailNotifications\ValueObjects\RemoteWebhookManagementResult;
use Throwable;

/**
 * Provides sanitized manager selection and result rendering for operator commands.
 */
abstract class RemoteWebhookCommand extends Command
{
    /**
     * Select one provider or every explicitly registered manager.
     *
     * @return list<RemoteWebhookManager>|false
     */
    protected function selectedManagers(
        RemoteWebhookManagerRegistry $registry,
    ): array|false {
        $provider = $this->rawProviderOption();

        if ($provider !== null
            && (! is_string($provider) || trim($provider) === '')) {
            $this->components->error(
                'The --provider option must be a non-empty provider name.',
            );

            return false;
        }

        try {
            $managers = $provider !== null
                ? [$registry->resolve(trim($provider))]
                : array_values($registry->all());
        } catch (DomainException $exception) {
            $this->components->error($exception->getMessage());

            return false;
        }

        if ($managers === []) {
            $this->components->error(
                'No remote webhook managers are registered.',
            );

            return false;
        }

        return $managers;
    }

    /**
     * Read the untrusted console option before narrowing its runtime shape.
     */
    private function rawProviderOption(): mixed
    {
        return $this->option('provider');
    }

    /**
     * Validate one enabled manager and execute a sanitized operation callback.
     *
     * @param  callable(): RemoteWebhookManagementResult  $operation
     */
    protected function executeManager(
        RemoteWebhookManager $manager,
        callable $operation,
    ): bool {
        try {
            if (! $manager->enabled()) {
                throw new MailTrackingException(sprintf(
                    'Remote webhook management for [%s] is disabled.',
                    $manager->provider(),
                ));
            }

            $manager->validateConfiguration();
            $result = $operation();
            $this->renderResult($result);

            return $result->succeeded();
        } catch (MailTrackingException|DomainException $exception) {
            $this->components->error(sprintf(
                '[%s] %s',
                $manager->provider(),
                $exception->getMessage(),
            ));

            return false;
        } catch (Throwable) {
            $this->components->error(sprintf(
                '[%s] Remote webhook management failed unexpectedly.',
                $manager->provider(),
            ));

            return false;
        }
    }

    /**
     * Render only bounded counters and manager-supplied sanitized errors.
     */
    private function renderResult(RemoteWebhookManagementResult $result): void
    {
        $mode = $result->dryRun ? 'dry-run' : 'apply';
        $this->components->info(sprintf(
            '[%s] %s %s: planned=%d changed=%d unchanged=%d failed=%d.',
            $result->provider,
            $result->operation,
            $mode,
            $result->planned,
            $result->changed,
            $result->unchanged,
            $result->failed,
        ));

        foreach ($result->errors as $error) {
            $this->components->error(sprintf(
                '[%s] %s',
                $result->provider,
                $error,
            ));
        }
    }
}
