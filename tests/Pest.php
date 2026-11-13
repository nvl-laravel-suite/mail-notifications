<?php

declare(strict_types=1);

use Nvl\MailNotifications\Tests\CommittedEventTestCase;
use Nvl\MailNotifications\Tests\ConfiguredManagedMediaTestCase;
use Nvl\MailNotifications\Tests\ConfiguredModelsTestCase;
use Nvl\MailNotifications\Tests\ManagedTestCase;
use Nvl\MailNotifications\Tests\PluggedTestCase;
use Nvl\MailNotifications\Tests\PostgreSqlConcurrencyTestCase;
use Nvl\MailNotifications\Tests\SensitiveStorageTestCase;
use Nvl\MailNotifications\Tests\TestCase;
use Nvl\MailNotifications\Tests\UnitTestCase;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

foreach (glob(__DIR__.'/Unit/*Test.php') ?: [] as $file) {
    pest()->extend(match (basename($file)) {
        'NotifiableRegistryTest.php', 'ProviderRegistryTest.php' => PhpUnitTestCase::class,
        'MailerSendAdapterTest.php' => TestCase::class,
        default => UnitTestCase::class,
    })->in($file);
}
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

pest()->extend(ConfiguredModelsTestCase::class)->in(__DIR__.'/ConfiguredModels');

pest()->extend(ManagedTestCase::class)->in(__DIR__.'/Managed');
