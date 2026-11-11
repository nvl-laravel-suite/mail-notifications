<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Nvl\MailNotifications\Contracts\ProviderApiAdapter;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\EmailMessageType;
use Nvl\MailNotifications\Jobs\SendEmailMessage;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\SenderIdentity;
use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Nvl\MailNotifications\Services\Delivery\ProviderManager;
use Nvl\MailNotifications\Services\ManagedDelivery;
use Nvl\Support\Globals\GlobalNames;
use Symfony\Component\Process\Process;

it('resolves delivery through the normal package namespaces and accepts authorized content', function () {
    Http::preventStrayRequests();
    Queue::fake();
    $client = Client::factory()->withMailSettings([])->create();
    $account = ProviderAccount::factory()->create(['owner_id' => $client->owner_id]);
    $sender = SenderIdentity::factory()->for($account)->create();
    $client->mailSettings()->update(['provider_account_id' => $account->id, 'sender_identity_id' => $sender->id, 'from_email' => $sender->email]);

    $message = app(ManagedDelivery::class)->send($client, [
        'to' => [['email' => 'namespace-qa@example.test']], 'subject' => 'Canonical package',
        'html' => '<p>Prepared HTML</p>', 'language' => 'bg',
        'references' => [['type' => 'order', 'id' => '42']],
    ], 'canonical-layout:42', static fn (Client $current): bool => true);

    expect($message)->toBeInstanceOf(EmailMessage::class)
        ->and($message->client_id)->toBe($client->id)
        ->and($message->getTable())->toBe(MailNotificationsTables::get('email_messages'))
        ->and($message->references()->first()->reference_id)->toBe('42');
    Queue::assertPushed(SendEmailMessage::class);
});

it('restores an already serialized job with its previous managed class identity', function () {
    $old = 'Nvl\\MailNotifications\\Managed\\Jobs\\SendEmailMessage';
    $messageId = 'a0000000-0000-4000-8000-000000000001';
    $payload = sprintf('O:%d:"%s":1:{s:9:"messageId";s:%d:"%s";}', strlen($old), $old, strlen($messageId), $messageId);
    $job = unserialize($payload);

    expect($job)->toBeInstanceOf(SendEmailMessage::class)
        ->and($job->messageId)->toBe($messageId);
});

it('preserves previous service bindings as the same configured singleton', function () {
    $old = 'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\ProviderManager';
    expect(app($old))->toBe(app(ProviderManager::class));
});

it('preserves previous model factories enum values and public provider contracts', function () {
    $oldModel = 'Nvl\\MailNotifications\\Managed\\Models\\Client';
    $oldEnum = 'Nvl\\MailNotifications\\Managed\\Enums\\EmailMessageType';
    $oldContract = 'Nvl\\MailNotifications\\Managed\\Contracts\\ProviderApiAdapter';
    expect(class_exists($oldModel))->toBeTrue()
        ->and($oldModel::factory()->make())->toBeInstanceOf(Client::class)
        ->and($oldEnum::from('transactional'))->toBe(EmailMessageType::Transactional)
        ->and(interface_exists($oldContract))->toBeTrue()
        ->and(is_a($oldContract, ProviderApiAdapter::class, true))->toBeTrue();
});

it('retains every recorded extraction identity without duplicate class implementations', function () {
    $names = json_decode(file_get_contents(__DIR__.'/../Fixtures/previous-managed-identities.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($names as $previous) {
        expect(class_exists($previous) || interface_exists($previous) || trait_exists($previous))->toBeTrue($previous);
        $canonical = str_replace('\\Managed\\', '\\', $previous);
        expect((new ReflectionClass($previous))->getName())->toBe($canonical);
    }
});

it('preserves previous instanceof and parameter types before any host class lookup', function () {
    $code = 'require '.var_export(dirname(__DIR__, 5).'/vendor/autoload.php', true).';'
        .'$client = (new ReflectionClass(Nvl\\MailNotifications\\Models\\Client::class))->newInstanceWithoutConstructor();'
        .'if (! ($client instanceof Nvl\\MailNotifications\\Managed\\Models\\Client)) { exit(1); }'
        .'$check = static fn (Nvl\\MailNotifications\\Managed\\Models\\Client $client): bool => true;'
        .'if (! $check($client)) { exit(2); }'
        .'if (! (Nvl\\MailNotifications\\Enums\\EmailMessageType::Transactional instanceof Nvl\\MailNotifications\\Managed\\Enums\\EmailMessageType)) { exit(3); }';
    $process = new Process([PHP_BINARY, '-r', $code]);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

it('loads no package class from the autoload file and resolves a previous name on first use', function () {
    $prefix = var_export('Nvl\\MailNotifications\\', true);
    $previousClient = var_export('Nvl\\MailNotifications\\Managed\\Models\\Client', true);
    $previousContract = var_export('Nvl\\MailNotifications\\Managed\\Contracts\\EmailProvider', true);
    $code = 'require '.var_export(dirname(__DIR__, 5).'/vendor/autoload.php', true).';'
        .'$loaded = array_filter('
        .'[...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()],'
        .'static fn (string $name): bool => str_starts_with($name, '.$prefix.'));'
        .'if ($loaded !== []) { fwrite(STDERR, implode(",", $loaded)); exit(1); }'
        .'if (! interface_exists('.$previousContract.')) { exit(2); }'
        .'if (class_exists(Nvl\\MailNotifications\\Models\\Client::class, false)) { exit(3); }'
        .'if (! class_exists('.$previousClient.')) { exit(4); }'
        .'$client = (new ReflectionClass('.$previousClient.'))->newInstanceWithoutConstructor();'
        .'if ($client::class !== Nvl\\MailNotifications\\Models\\Client::class) { exit(5); }';
    $process = new Process([PHP_BINARY, '-r', $code]);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

it('honors an explicitly configured host morph alias for the client', function () {
    $previous = Relation::morphMap();
    try {
        Relation::morphMap(['host.mail-client' => Client::class]);
        expect((new Client)->getMorphClass())->toBe('host.mail-client');
    } finally {
        Relation::morphMap($previous, false);
    }
});

it('preserves a host binding or alias under a previous managed service identity', function (bool $alias): void {
    $previous = 'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\ProviderManager';
    $currentApplication = app();
    $configuration = config()->all();
    $application = new Application(sys_get_temp_dir());
    $application->instance('config', new Repository($configuration));
    $application->instance('files', new Filesystem);
    $host = new stdClass;
    if ($alias) {
        $application->instance('host.mail-provider', $host);
        $application->alias('host.mail-provider', $previous);
    } else {
        $application->instance($previous, $host);
    }

    try {
        (new MailNotificationsServiceProvider($application))->register();

        expect($application->getAlias($previous))->toBe($alias ? 'host.mail-provider' : $previous)
            ->and($application->make($previous))->toBe($host)
            ->and(collect($application->make(GlobalNames::class)->diagnostics())->firstWhere('key', 'globals.mail-notifications.container.'.$previous))
            ->not->toBeNull();
    } finally {
        $application->flush();
        Application::setInstance($currentApplication);
    }
})->with(['binding' => false, 'alias' => true]);
