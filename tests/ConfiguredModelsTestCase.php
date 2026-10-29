<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\MailNotifications\Tests\Fixtures\ExtendedMailNotification;
use Nvl\MailNotifications\Tests\Fixtures\ExtendedScheduledMailMessage;

/** Native root commits on a configured package connection, including fixed technical rows. */
abstract class ConfiguredModelsTestCase extends CommittedEventTestCase
{
    /** @var array<string, class-string<Model>> */
    private array $originalMorphMap = [];

    protected function setUp(): void
    {
        $this->originalMorphMap = Relation::morphMap();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            Relation::morphMap($this->originalMorphMap, false);
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.mail-root', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        $app['config']->set('nvl-mail-notifications.connection', 'mail-root');
        $app['config']->set('nvl-mail-notifications.models.notification', ExtendedMailNotification::class);
        $app['config']->set('nvl-mail-notifications.models.scheduled_message', ExtendedScheduledMailMessage::class);
    }
}
