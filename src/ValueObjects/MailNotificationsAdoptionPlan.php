<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * Complete, bounded plan for one legacy Mail Notifications cutover.
 */
final readonly class MailNotificationsAdoptionPlan
{
    /**
     * @param  list<LegacyMailTableStage>  $stages
     * @param  list<LegacyMailForeignKey>  $foreignKeys
     */
    public function __construct(
        public ?string $connection,
        public array $stages,
        public ?LegacyMailNotificationMapping $notifications,
        public ?LegacyScheduledMailMapping $scheduledMessages,
        public array $foreignKeys,
        public bool $dropSources,
    ) {}
}
