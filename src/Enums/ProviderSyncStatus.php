<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

/** Records the durable lifecycle of provider inventory synchronization.
 * @api
 */
enum ProviderSyncStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Retrying = 'retrying';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Identify a synchronization run that still owns executable or retryable work.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running, self::Retrying], true);
    }
}
