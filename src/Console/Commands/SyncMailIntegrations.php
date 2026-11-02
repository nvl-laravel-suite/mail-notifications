<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Services\Integrations\SyncProviderConfiguration;

/** Trusted operator entry point for the opt-in managed delivery engine.
 * @internal
 */
class SyncMailIntegrations extends Command
{
    protected $signature = 'nvl:mail-notifications:delivery:integrations:sync {account : Provider account UUID}';

    protected $description = 'Queue synchronization of all sending domains and sender identities for one provider account';

    /**
     * Start a durable paginated domain/sender synchronization for the explicitly selected provider account.
     */
    public function handle(SyncProviderConfiguration $sync): int
    {
        $id = $this->argument('account');
        $account = is_string($id) ? ProviderAccount::query()->find($id) : null;
        if (! $account) {
            $this->error('Provider account not found.');

            return self::FAILURE;
        }
        try {
            $run = $sync->start($account);
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Synchronization queued.');
        $this->line('Run: '.$run->id);
        $this->line('Status: '.$run->status->value);

        return self::SUCCESS;
    }
}
