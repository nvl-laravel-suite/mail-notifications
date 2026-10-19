<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests;

use Nvl\MailNotifications\Tests\Fixtures\GlobalMailIdentity;
use Nvl\MailNotifications\Tests\Fixtures\ShadowMailNotifiable;

/** Declares host aliases before scoped delivery infrastructure is constructed. */
abstract class NotifiableOwnerScopeTestCase extends OwnerScopeTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('nvl-mail-notifications.notifiable_types', ['global.identity' => GlobalMailIdentity::class, 'shadow' => ShadowMailNotifiable::class]);
    }
}
