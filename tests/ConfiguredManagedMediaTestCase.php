<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\MailNotifications\Tests\Fixtures\ConfiguredManagedMedia;

/** Selects the host's native Media root before the package registry freezes. */
abstract class ConfiguredManagedMediaTestCase extends ManagedTestCase
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
        $app['config']->set('nvl-media.models.media', ConfiguredManagedMedia::class);
    }
}
