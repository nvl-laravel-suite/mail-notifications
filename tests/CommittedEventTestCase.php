<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests;

/** Boots the shared Mail Notifications fixture with native commits. */
abstract class CommittedEventTestCase extends TestCase
{
    use CommitsTestDatabase;
}
