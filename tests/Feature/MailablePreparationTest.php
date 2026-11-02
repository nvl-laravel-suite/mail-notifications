<?php

declare(strict_types=1);

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Laravel\Concerns\SubmitsMailDelivery;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Tests\Fixtures\ManagedPreparationMail;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

beforeEach(function () {
    ManagedPreparationMail::$builds = 0;
    app('translator')->addLines(['managed.subject' => 'English subject', 'managed.body' => 'English body'], 'en');
    app('translator')->addLines(['managed.subject' => 'Българска тема', 'managed.body' => 'Български текст'], 'bg');
});

it('prepares localized html and text once without sending or tracking or mutating the original', function (string $locale, string $subject, string $body) {
    Event::fake([MessageSending::class, MessageSent::class]);
    config(['mail.default' => 'unconfigured-smtp']);
    $originalLocale = app()->getLocale();
    $mail = (new ManagedPreparationMail)->to('target@example.test')->cc('copy@example.test')->bcc('blind@example.test')->locale($locale);

    $prepared = $mail->toMailDelivery();

    expect($prepared)->toMatchArray(['subject' => $subject, 'html' => '<p>'.$body.'</p>', 'language' => $locale, 'to' => [['email' => 'target@example.test', 'name' => null]], 'cc' => [['email' => 'copy@example.test', 'name' => null]], 'bcc' => [['email' => 'blind@example.test', 'name' => null]]]);
    expect($prepared['text'])->toContain($body);
    expect(ManagedPreparationMail::$builds)->toBe(1);
    expect($mail->subject)->toBeNull();
    expect($mail->hasMailTrackingEnabled())->toBeTrue();
    expect(app()->getLocale())->toBe($originalLocale);
    $this->assertDatabaseCount((new MailNotification)->getTable(), 0);
    Event::assertNothingDispatched();
})->with([['en', 'English subject', 'English body'], ['bg', 'Българска тема', 'Български текст']]);

it('preserves file storage raw and explicit inline attachments after build', function () {
    Storage::fake('local');
    Storage::disk('local')->put('stored.txt', 'storage bytes');
    $path = tempnam(sys_get_temp_dir(), 'managed-mail-');
    file_put_contents($path, 'file bytes');
    $mail = (new ManagedPreparationMail)->to('target@example.test')
        ->attach($path, ['as' => 'file.txt', 'mime' => 'text/plain'])
        ->attachFromStorageDisk('local', 'stored.txt', 'stored.txt')
        ->attachData('raw bytes', 'raw.txt')
        ->withSymfonyMessage(static function (Email $email): void {
            $email->addPart((new DataPart('inline bytes', 'inline.txt', 'text/plain'))->asInline()->setContentId('logo@host.test'));
        });

    try {
        $prepared = $mail->toMailDelivery();
    } finally {
        unlink($path);
    }

    expect(array_column($prepared['attachments'], 'name'))->toBe(['file.txt', 'raw.txt', 'stored.txt']);
    expect(array_map(base64_decode(...), array_column($prepared['attachments'], 'content_base64')))->toBe(['file bytes', 'raw bytes', 'storage bytes']);
    expect($prepared['inline_assets'])->toBe([['content_base64' => base64_encode('inline bytes'), 'name' => 'inline.txt', 'disposition' => 'inline', 'content_id' => 'logo@host.test']]);
});

it('makes generated inline content ids stable across independent preparations', function () {
    $mail = (new ManagedPreparationMail)->to('target@example.test')->withSymfonyMessage(static function (Email $email): void {
        $part = (new DataPart('same bytes', 'inline.txt'))->asInline();
        $email->html('<img src="cid:'.$part->getContentId().'">');
        $email->addPart($part);
    });

    $first = $mail->toMailDelivery();
    $second = $mail->toMailDelivery();

    expect($second)->toBe($first);
    expect($first['html'])->toContain('cid:'.$first['inline_assets'][0]['content_id']);
});

it('rejects custom semantic headers instead of silently dropping them', function () {
    $mail = (new ManagedPreparationMail)->to('target@example.test')->withSymfonyMessage(static function (Email $email): void {
        $email->getHeaders()->addIdHeader('In-Reply-To', 'conversation@host.test');
    });

    expect(fn () => $mail->toMailDelivery())->toThrow(ValidationException::class, 'In-Reply-To');
});

it('restores locale when rendering fails', function () {
    $originalLocale = app()->getLocale();
    $mail = (new ManagedPreparationMail)->to('target@example.test')->locale('bg')->withSymfonyMessage(static function (): never {
        throw new RuntimeException('host rendering failure');
    });

    expect(fn () => $mail->toMailDelivery())->toThrow(RuntimeException::class, 'host rendering failure');
    expect(app()->getLocale())->toBe($originalLocale);
    expect($mail->hasMailTrackingEnabled())->toBeTrue();
});

it('supports host markdown through the ordinary laravel content API', function () {
    $mail = new class extends Mailable
    {
        use SubmitsMailDelivery;

        public function envelope(): Envelope
        {
            return new Envelope(to: ['target@example.test'], subject: 'Markdown subject');
        }

        public function content(): Content
        {
            return new Content(markdown: 'mail-notifications-tests::message');
        }
    };

    $prepared = $mail->toMailDelivery();

    expect($prepared['html'])->toContain('Tracked delivery');
    expect($prepared['text'])->toContain('Tracked delivery');
    expect($mail->to)->toBe([]);
});

it('rejects prepared bodies above the managed schema limit', function () {
    $mail = (new ManagedPreparationMail)->to('target@example.test')->withSymfonyMessage(static function (Email $email): void {
        $email->html(str_repeat('x', 1000001));
    });

    expect(fn () => $mail->toMailDelivery())->toThrow(ValidationException::class);
});

it('carries Laravel tags and Unicode metadata without MIME header encoding', function () {
    $mail = (new ManagedPreparationMail)->to('target@example.test')->tag('invoice')->metadata('label', 'Фактура');

    $prepared = $mail->toMailDelivery();

    expect($prepared['tags'])->toBe(['invoice']);
    expect($prepared['metadata'])->toBe(['label' => 'Фактура']);
});

it('bounds attachment conversion before acceptance', function () {
    $mail = (new ManagedPreparationMail)->to('target@example.test')->attachData(str_repeat('x', 10 * 1024 * 1024 + 1), 'too-large.txt');

    expect(fn () => $mail->toMailDelivery())->toThrow(ValidationException::class, '10 MiB');
});

it('rejects envelope override options before running host build code', function () {
    $mail = (new ManagedPreparationMail)->to('target@example.test');

    expect(fn () => $mail->toMailDelivery(['to' => [['email' => 'changed@example.test']]]))->toThrow(ValidationException::class);
    expect(ManagedPreparationMail::$builds)->toBe(0);
});

it('renders a native underscore locale while storing its normalized delivery language', function () {
    app('translator')->addLines(['managed.subject' => 'British subject', 'managed.body' => 'British body'], 'en_GB');
    $originalLocale = app()->getLocale();
    $mail = (new ManagedPreparationMail)->to('target@example.test')->locale('en_GB');

    $prepared = $mail->toMailDelivery();

    expect($prepared['language'])->toBe('en-gb');
    expect($prepared['subject'])->toBe('British subject');
    expect($prepared['html'])->toBe('<p>British body</p>');
    expect($prepared['text'])->toContain('British body');
    expect($mail->locale)->toBe('en_GB');
    expect(app()->getLocale())->toBe($originalLocale);
});
