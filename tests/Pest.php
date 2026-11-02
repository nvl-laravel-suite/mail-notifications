<?php

declare(strict_types=1);

use Nvl\MailNotifications\Tests\CommittedEventTestCase;
use Nvl\MailNotifications\Tests\ConfiguredManagedMediaTestCase;
use Nvl\MailNotifications\Tests\ConfiguredModelsTestCase;
use Nvl\MailNotifications\Tests\ManagedTestCase;
use Nvl\MailNotifications\Tests\MySqlConcurrencyTestCase;
use Nvl\MailNotifications\Tests\PluggedTestCase;
use Nvl\MailNotifications\Tests\PostgreSqlConcurrencyTestCase;
use Nvl\MailNotifications\Tests\SensitiveStorageTestCase;
use Nvl\MailNotifications\Tests\TestCase;

pest()->extend(TestCase::class)->in(
    __DIR__.'/Unit',
);
foreach (glob(__DIR__.'/Feature/*.php') ?: [] as $file) {
    pest()->extend(match (basename($file)) {
        'ScheduledMailTest.php' => CommittedEventTestCase::class,
        'ConfiguredManagedMediaRelationTest.php' => ConfiguredManagedMediaTestCase::class,
        default => TestCase::class,
    })->in($file);
}
pest()->extend(PluggedTestCase::class)->in(__DIR__.'/Plugged');
pest()->extend(SensitiveStorageTestCase::class)->in(
    __DIR__.'/SensitiveStorage',
);
pest()->extend(PostgreSqlConcurrencyTestCase::class)->in(
    __DIR__.'/Concurrency',
);
pest()->extend(MySqlConcurrencyTestCase::class)->in(
    __DIR__.'/MySqlConcurrency',
);

pest()->extend(ConfiguredModelsTestCase::class)->in(__DIR__.'/ConfiguredModels');

pest()->extend(ManagedTestCase::class)->in(__DIR__.'/Managed');
