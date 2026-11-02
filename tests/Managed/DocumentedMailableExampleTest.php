<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Nvl\MailNotifications\Jobs\SendEmailMessage;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\SenderIdentity;
use Nvl\MailNotifications\Services\ManagedDelivery;

it('executes the documented Mailable with localized private bytes and one stable accepted intent', function (string $language, string $subject): void {
    $guide = file_get_contents(dirname(__DIR__, 2).'/docs/delivery.md');
    expect(preg_match('/<!-- example:order-receipt-mailable -->\s*```php\n(.*?)\n```/s', $guide, $example))->toBe(1);
    $class = 'MailDocumentationExamples\\OrderReceipt';
    if (! class_exists($class)) {
        eval('namespace MailDocumentationExamples; '.$example[1]);
    }
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('local');
    Storage::fake('private');
    $bytes = "%PDF-1.4\nPrivate order receipt\n%%EOF";
    Storage::disk('private')->put('orders/42.pdf', $bytes);
    foreach (['en', 'bg'] as $locale) {
        expect(preg_match('/<!-- example:translations-'.preg_quote($locale, '/').' -->\s*```php\n(.*?)\n```/s', $guide, $translations))->toBe(1);
        $lines = eval($translations[1]);
        $namespaced = [];
        foreach ($lines as $key => $value) {
            $namespaced['mail.'.$key] = $value;
        }
        app('translator')->addLines($namespaced, $locale);
    }
    app()->setLocale('en');
    $account = ProviderAccount::factory()->create();
    $sender = SenderIdentity::factory()->for($account)->create();
    $client = Client::factory()->withMailSettings(['provider_account_id' => $account->id, 'sender_identity_id' => $sender->id, 'from_email' => $sender->email])->create(['owner_id' => $account->owner_id]);
    $mail = (new $class('42', 'orders/42.pdf'))->to('customer@example.test');
    $prepared = $mail->toMailDelivery(['language' => $language, 'references' => [['type' => 'user', 'id' => '7'], ['type' => 'order', 'id' => '42']]]);
    $authorize = static fn (Client $current): bool => $current->id === $client->id;
    $delivery = app(ManagedDelivery::class);
    $message = $delivery->send($client, $prepared, 'order:42:'.$language, $authorize);

    expect($message->subject)->toBe($subject)
        ->and($message->language)->toBe($language)
        ->and($message->references()->count())->toBe(2)
        ->and($message->messageAssets()->first()->checksum)->toBe(hash('sha256', $bytes))
        ->and($message->messageAssets()->first()->media->visibility->value)->toBe('private')
        ->and($delivery->send($client, $prepared, 'order:42:'.$language, $authorize)->id)->toBe($message->id)
        ->and($client->messages()->count())->toBe(1)
        ->and($mail->locale)->toBeNull()
        ->and(app()->getLocale())->toBe('en');
    Http::assertNothingSent();
    Queue::assertPushed(SendEmailMessage::class, 1);
})->with(['English' => ['en', 'Order 42 receipt'], 'Bulgarian' => ['bg', 'Разписка за поръчка 42']]);
