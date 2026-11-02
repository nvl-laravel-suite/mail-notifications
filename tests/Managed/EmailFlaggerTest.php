<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Nvl\MailNotifications\Enums\EmailFlagType;
use Nvl\MailNotifications\Jobs\SendEmailMessage;
use Nvl\MailNotifications\Models\EmailFlagRule;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\EmailMessageFlag;
use Nvl\MailNotifications\Models\EmailRecipient;
use Nvl\MailNotifications\Services\Delivery\MessageAcceptor;
use Nvl\MailNotifications\Services\Flags\EmailFlagger;
use Nvl\MailNotifications\Support\ManagedConfiguration;

test('flags exact normalized recipient addresses across to cc and bcc', function (string $type) {
    $message = EmailMessage::factory()->create();
    EmailRecipient::factory()->for($message)->create(['type' => $type, 'email' => 'Reader@Example.com']);
    $rule = EmailFlagRule::factory()->create(['client_id' => $message->client_id, 'type' => EmailFlagType::User, 'value' => 'reader@example.com']);
    EmailFlagRule::factory()->create(['client_id' => $message->client_id, 'type' => EmailFlagType::User, 'value' => 'other@example.com']);

    app(EmailFlagger::class)->apply($message);

    $this->assertDatabaseCount(ManagedConfiguration::table('email_message_flags'), 1);
    $this->assertDatabaseHas(ManagedConfiguration::table('email_message_flags'), ['email_message_id' => $message->id, 'email_flag_rule_id' => $rule->id, 'type' => 'user']);
    expect(EmailMessageFlag::query()->sole()->type)->toBe(EmailFlagType::User);
})->with(['to', 'cc', 'bcc']);

test('domain rules match the exact recipient domain without matching subdomains', function () {
    $message = EmailMessage::factory()->create();
    EmailRecipient::factory()->for($message)->create(['email' => 'reader@Mail.Example.com']);
    $rule = EmailFlagRule::factory()->create(['client_id' => $message->client_id, 'type' => EmailFlagType::Domain, 'value' => 'mail.example.com']);
    EmailFlagRule::factory()->create(['client_id' => $message->client_id, 'type' => EmailFlagType::Domain, 'value' => 'example.com']);
    EmailFlagRule::factory()->create(['client_id' => $message->client_id, 'type' => EmailFlagType::Domain, 'value' => 'othermail.example.com']);

    app(EmailFlagger::class)->apply($message);

    $this->assertDatabaseCount(ManagedConfiguration::table('email_message_flags'), 1);
    $this->assertDatabaseHas(ManagedConfiguration::table('email_message_flags'), ['email_message_id' => $message->id, 'email_flag_rule_id' => $rule->id]);
});

test('subject rules contain case insensitive text while ignoring disabled and foreign rules', function () {
    $message = EmailMessage::factory()->create(['subject' => 'Your INVOICE is ready']);
    $rule = EmailFlagRule::factory()->create(['client_id' => $message->client_id, 'type' => EmailFlagType::Subject, 'value' => 'invoice']);
    EmailFlagRule::factory()->create(['client_id' => $message->client_id, 'type' => EmailFlagType::Subject, 'value' => 'ready', 'enabled' => false]);
    EmailFlagRule::factory()->create(['type' => EmailFlagType::Subject, 'value' => 'invoice']);
    EmailFlagRule::factory()->create(['client_id' => $message->client_id, 'type' => EmailFlagType::Subject, 'value' => 'receipt']);

    app(EmailFlagger::class)->apply($message);

    $this->assertDatabaseCount(ManagedConfiguration::table('email_message_flags'), 1);
    $this->assertDatabaseHas(ManagedConfiguration::table('email_message_flags'), ['email_message_id' => $message->id, 'email_flag_rule_id' => $rule->id]);
});

test('retains snapshots through repeat application rule edits disable and deletion', function () {
    $message = EmailMessage::factory()->create(['subject' => 'Invoice']);
    $rule = EmailFlagRule::factory()->create(['client_id' => $message->client_id, 'type' => EmailFlagType::Subject, 'value' => 'invoice', 'label' => 'Billing', 'color' => '#123456']);
    $flagger = app(EmailFlagger::class);
    $flagger->apply($message);
    $rule->update(['label' => 'New label', 'color' => '#abcdef', 'value' => 'Invoice']);
    $flagger->apply($message);
    $rule->update(['enabled' => false]);
    $flagger->apply($message);
    $rule->delete();

    $this->assertDatabaseCount(ManagedConfiguration::table('email_message_flags'), 1);
    $this->assertDatabaseHas(ManagedConfiguration::table('email_message_flags'), ['email_message_id' => $message->id, 'email_flag_rule_id' => null, 'label' => 'Billing', 'color' => '#123456', 'type' => 'subject', 'value' => 'invoice']);
});

test('acceptance flags final prepared subjects without changing delivery or manual tags', function () {
    Queue::fake([SendEmailMessage::class]);
    $fixture = EmailMessage::factory()->create();
    $client = $fixture->client;
    $client->mailSettings->update(['provider_account_id' => $fixture->provider_account_id, 'sender_identity_id' => $fixture->sender_identity_id, 'from_email' => $fixture->from_email]);
    $rule = EmailFlagRule::factory()->for($client)->create(['type' => EmailFlagType::Subject, 'value' => 'invoice for ada']);
    $input = ['to' => [['email' => 'reader@example.com']], 'subject' => 'Invoice for Ada', 'text' => 'Body', 'data' => ['name' => 'Ada'], 'tags' => ['manual']];

    $message = app(MessageAcceptor::class)->accept($client, $input, 'flag-prepared-subject');
    app(MessageAcceptor::class)->accept($client, $input, 'flag-prepared-subject');

    expect($message->subject)->toBe('Invoice for Ada');
    expect($message->tags)->toBe(['manual']);
    $this->assertDatabaseCount(ManagedConfiguration::table('email_message_flags'), 1);
    $this->assertDatabaseHas(ManagedConfiguration::table('email_message_flags'), ['email_message_id' => $message->id, 'email_flag_rule_id' => $rule->id]);
    $this->assertDatabaseHas(ManagedConfiguration::table('email_messages'), ['id' => $message->id, 'status' => 'queued']);
    Queue::assertPushed(SendEmailMessage::class, 1);
});
