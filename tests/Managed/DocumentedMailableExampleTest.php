<?php

declare(strict_types=1);

use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Nvl\MailNotifications\Jobs\SendEmailMessage;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\SenderIdentity;
use Nvl\MailNotifications\Services\Delivery\MessageSender;
use Nvl\MailNotifications\Services\ManagedDelivery;
use Nvl\MailNotifications\Tests\Fixtures\SmtpTestProcess;

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
    $smtp = SmtpTestProcess::start(acceptMessage: true);
    try {
        $account = ProviderAccount::factory()->create(['provider' => 'smtp', 'credentials' => [
            'host' => '127.0.0.1', 'port' => $smtp->port, 'encryption' => 'none',
        ]]);
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

        $client->mailSettings()->update(['from_name' => 'Changed after acceptance']);
        Storage::disk('private')->put('orders/42.pdf', 'Changed after acceptance');
        app(MessageSender::class)->send($message->id);
        app(MessageSender::class)->send($message->id);
        app(MailManager::class)->forgetMailers();
        expect($smtp->wait())->toBe(0);
        $wire = file_get_contents($smtp->capturePath);
        $headers = iconv_mime_decode_headers($wire, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        $decoded = str_replace(["\r\n", "\r"], "\n", quoted_printable_decode($wire));
        expect($message->fresh()->status)->toBe('submitted')
            ->and($message->attempts()->count())->toBe(1)
            ->and($message->attempts()->sole()->status)->toBe('submitted')
            ->and($headers['Subject'])->toBe($subject)
            ->and($wire)->toContain('customer@example.test', 'receipt.pdf', base64_encode($bytes))
            ->not->toContain('Changed after acceptance')
            ->and($decoded)->toContain(str_replace("\r\n", "\n", $message->html))
            ->and($message->original_html)->toBe($prepared['html']);
    } finally {
        app(MailManager::class)->forgetMailers();
        $smtp->stop();
        if (is_file($smtp->capturePath)) {
            unlink($smtp->capturePath);
        }
    }
})->with(['English' => ['en', 'Order 42 receipt'], 'Bulgarian' => ['bg', 'Разписка за поръчка 42']]);
