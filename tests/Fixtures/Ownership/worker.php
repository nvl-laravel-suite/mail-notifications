<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Nvl\MailNotifications\Tests\Fixtures\Ownership\WorkerProvider;
use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Ownership\Tests\Fixtures\NativeDatabaseSource;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

require dirname(__DIR__, 6).'/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (! is_array($arguments)) {
    throw new RuntimeException('The worker requires CLI arguments.');
}
$source = $arguments[1] ?? null;
if (! is_string($source)) {
    throw new RuntimeException('The worker requires a native persistence source argument.');
}
$connection = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
if (! is_array($connection) || ($connection['driver'] ?? null) !== 'pgsql'
    || ! is_string($connection['database'] ?? null) || preg_match('/^nvl_own_test_[a-z0-9_]+$/D', $connection['database']) !== 1) {
    throw new RuntimeException('The worker requires an explicitly dedicated native persistence source.');
}
$optionsSource = $arguments[2] ?? null;
$options = is_string($optionsSource) ? json_decode($optionsSource, true, flags: JSON_THROW_ON_ERROR) : [];
if (! is_array($options) || ! in_array($options['driver'] ?? 'database', ['database', 'redis'], true)) {
    throw new RuntimeException('Unsupported native persistence worker transport.');
}
WorkerProvider::useSource($connection, $options);
$application = TestbenchApplication::create(options: ['load_environment_variables' => false, 'extra' => ['dont-discover' => ['*'], 'providers' => [SupportServiceProvider::class, OwnershipServiceProvider::class, MailNotificationsServiceProvider::class, WorkerProvider::class]]]);
$kernel = $application->make(Kernel::class);
$kernel->bootstrap();
NativeDatabaseSource::inspect($application->make('db')->connection());
$status = $kernel->call('queue:work', ['connection' => $options['driver'] ?? 'database', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 2, '--timeout' => 10, '--force' => true, '--no-interaction' => true]);
if ($status !== 0) {
    fwrite(STDERR, $kernel->output());
    exit($status);
}
for ($iteration = 0; $iteration < 2; $iteration++) {
    $kernel->call('nvl:mail-notifications:process-scheduled');
}
fwrite(STDOUT, 'mail-worker-complete');
