<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Closure;
use Nvl\MailNotifications\Compatibility\LegacyMailTenantOperations;
use Nvl\MailNotifications\Contracts\MailOwnerWorklist;
use Nvl\MailNotifications\Enums\MailOwnerOperation;
use Nvl\MailNotifications\Ownership\ScheduledMailCentralWorklist;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Contracts\OwnershipSystemRunner;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipContextResolver;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/** Runs mail commands inside separately admitted owner, explicit global, or re-authorized central producer scopes. @internal */
final readonly class MailOwnerOperations
{
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipContextResolver $context,
        private OwnershipSystemRunner $owners, private MailOwnerWorklist $worklist, private ScheduledMailCentralWorklist $central,
        private OwnershipQueueContext $queue, private LegacyMailTenantOperations $legacy) {}

    /** @template T
     * @param  Closure(): T  $callback
     * @return list<T>
     */
    public function run(MailOwnerOperation $operation, Closure $callback): array
    {
        if ($this->resources->get('mail.notifications')->ownership->strategy === 'unscoped') {
            return $this->legacy->run($callback);
        }
        $mode = $this->context->current()->snapshot()->mode;
        if (in_array($mode, [OwnershipContextMode::Owner, OwnershipContextMode::Central], true)) {
            return [$callback()];
        }
        if ($mode !== OwnershipContextMode::Unresolved) {
            throw new OwnershipViolation('Mail maintenance requires its enabled native runtime.');
        }
        $results = [];
        foreach ($this->worklist->owners($operation) as $owner) {
            $results[] = $this->owners->runSystem($owner, $operation->value, $callback);
        }
        $results[] = $callback();
        foreach ($this->central->envelopes($operation) as $envelope) {
            $results[] = $this->queue->run($envelope, $callback);
        }

        return $results;
    }
}
