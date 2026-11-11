<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Nvl\MailNotifications\Support\StatusConstraintDatabase;
use Nvl\Support\Config\PackageStorage;

/** Complete alpha schema. Feature flags control behavior, never the installed table shape. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return PackageStorage::connection('mail-notifications');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $tables = $this->tables();
        StatusConstraintDatabase::assertSupported($schema->getConnection());
        if (count(array_unique($tables)) !== count($tables)) {
            throw new LogicException('Configured mail table names must be distinct.');
        }
        foreach ($tables as $table) {
            if ($schema->hasTable($table)) {
                throw new LogicException('Mail table ['.$table.'] already exists. The alpha schema requires fresh package tables.');
            }
        }
        try {
            $this->createTracking($schema);
            $this->createScheduledMail($schema);
            $this->createManagedDelivery($schema);
        } catch (Throwable $exception) {
            // No package table existed before this run, so whatever exists now
            // is its own partial work. A surrounding transaction already undoes
            // it; MySQL and MariaDB commit each DDL statement and never call down().
            if ($schema->getConnection()->transactionLevel() === 0) {
                try {
                    $this->down();
                } catch (Throwable) {
                    // The creation failure stays the reported cause.
                }
            }

            throw $exception;
        }
    }

    /** Drop package-owned schema only; native Media lifecycle remains owned by nvl/media. */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists(ManagedConfiguration::table('marketing_opt_outs'));
        $schema->dropIfExists(ManagedConfiguration::table('client_webhook_deliveries'));
        $schema->dropIfExists(ManagedConfiguration::table('client_webhook_endpoints'));
        $schema->dropIfExists(ManagedConfiguration::table('email_message_references'));
        $schema->dropIfExists(ManagedConfiguration::table('staged_mail_files'));
        $schema->dropIfExists(ManagedConfiguration::table('provider_sync_pages'));
        $schema->dropIfExists(ManagedConfiguration::table('provider_sync_runs'));
        $schema->dropIfExists(ManagedConfiguration::table('email_message_flags'));
        $schema->dropIfExists(ManagedConfiguration::table('email_flag_rules'));
        $schema->dropIfExists(ManagedConfiguration::table('message_assets'));
        $schema->dropIfExists(ManagedConfiguration::table('recipient_suppressions'));
        $schema->dropIfExists(ManagedConfiguration::table('email_events'));
        $schema->dropIfExists(ManagedConfiguration::table('webhook_receipts'));
        $schema->dropIfExists(ManagedConfiguration::table('email_attempts'));
        $schema->dropIfExists(ManagedConfiguration::table('email_recipients'));
        $schema->dropIfExists(ManagedConfiguration::table('email_messages'));
        $schema->dropIfExists(ManagedConfiguration::table('assets'));
        $schema->dropIfExists(ManagedConfiguration::table('client_api_keys'));
        $schema->dropIfExists(ManagedConfiguration::table('client_mail_settings'));
        $schema->dropIfExists(ManagedConfiguration::table('owner_mail_settings'));
        $schema->dropIfExists(ManagedConfiguration::table('clients'));
        $schema->dropIfExists(ManagedConfiguration::table('sender_identities'));
        $schema->dropIfExists(ManagedConfiguration::table('sending_domains'));
        $schema->dropIfExists(ManagedConfiguration::table('provider_accounts'));
        $schema->dropIfExists(ManagedConfiguration::table('integrations'));
        $schema->dropIfExists(ManagedConfiguration::table('owners'));
        $schema->dropIfExists(MailNotificationsTables::get(MailNotificationsTables::Events));
        $schema->dropIfExists(MailNotificationsTables::get(MailNotificationsTables::ScheduledMessages));
        $schema->dropIfExists(MailNotificationsTables::get(MailNotificationsTables::Notifications));
    }

    /** @return list<string> */
    private function tables(): array
    {
        return [
            MailNotificationsTables::get(MailNotificationsTables::Notifications),
            MailNotificationsTables::get(MailNotificationsTables::Events),
            MailNotificationsTables::get(MailNotificationsTables::ScheduledMessages),
            MailNotificationsTables::get(MailNotificationsTables::Owners),
            MailNotificationsTables::get(MailNotificationsTables::Integrations),
            MailNotificationsTables::get(MailNotificationsTables::ProviderAccounts),
            MailNotificationsTables::get(MailNotificationsTables::SendingDomains),
            MailNotificationsTables::get(MailNotificationsTables::SenderIdentities),
            MailNotificationsTables::get(MailNotificationsTables::Clients),
            MailNotificationsTables::get(MailNotificationsTables::OwnerMailSettings),
            MailNotificationsTables::get(MailNotificationsTables::ClientMailSettings),
            MailNotificationsTables::get(MailNotificationsTables::ClientApiKeys),
            MailNotificationsTables::get(MailNotificationsTables::Assets),
            MailNotificationsTables::get(MailNotificationsTables::EmailMessages),
            MailNotificationsTables::get(MailNotificationsTables::EmailRecipients),
            MailNotificationsTables::get(MailNotificationsTables::EmailAttempts),
            MailNotificationsTables::get(MailNotificationsTables::WebhookReceipts),
            MailNotificationsTables::get(MailNotificationsTables::EmailEvents),
            MailNotificationsTables::get(MailNotificationsTables::RecipientSuppressions),
            MailNotificationsTables::get(MailNotificationsTables::MessageAssets),
            MailNotificationsTables::get(MailNotificationsTables::EmailFlagRules),
            MailNotificationsTables::get(MailNotificationsTables::EmailMessageFlags),
            MailNotificationsTables::get(MailNotificationsTables::ProviderSyncRuns),
            MailNotificationsTables::get(MailNotificationsTables::ProviderSyncPages),
            MailNotificationsTables::get(MailNotificationsTables::StagedMailFiles),
            MailNotificationsTables::get(MailNotificationsTables::EmailMessageReferences),
            MailNotificationsTables::get(MailNotificationsTables::ClientWebhookEndpoints),
            MailNotificationsTables::get(MailNotificationsTables::ClientWebhookDeliveries),
            MailNotificationsTables::get(MailNotificationsTables::MarketingOptOuts),
        ];
    }

    private function createTracking(Builder $schema): void
    {
        $notificationTable = MailNotificationsTables::get(MailNotificationsTables::Notifications);
        $eventTable = MailNotificationsTables::get(MailNotificationsTables::Events);
        $identityCollation = in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'utf8mb4_bin' : null;
        $schema->create($notificationTable, function (Blueprint $table) use ($identityCollation): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index('nvl_mn_notifications_tenant_idx');
            $table->string('ownership_key', 96)->default('platform')->index('nvl_mn_notifications_owner_idx');
            $table->uuid('correlation_id')->unique();
            $table->uuid('queue_reference')->nullable();
            $table->string('mailer', 128);
            $provider = $table->string('provider', 128)->nullable();
            $providerMessageId = $table->string('provider_message_id', 255)
                ->nullable();

            if ($identityCollation !== null) {
                $provider->collation($identityCollation);
                $providerMessageId->collation($identityCollation);
            }

            $table->string('status', 32)->default('pending');
            $table->string('message_category', 128);
            $table->text('subject')->nullable();
            $table->string('from_email', 254)->nullable();
            $table->text('from_name')->nullable();
            $table->json('to_recipients');
            $table->json('cc_recipients')->nullable();
            $table->json('bcc_recipients')->nullable();
            $table->string('primary_recipient_email', 254)->nullable();
            $table->string('notifiable_type', 128)->nullable();
            $table->string('notifiable_id', 128)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('accepted_at', 6)->nullable();
            $table->timestampTz('delivered_at', 6)->nullable();
            $table->timestampTz('failed_at', 6)->nullable();
            $table->timestampTz('status_changed_at', 6)->nullable();
            $table->timestampTz('provider_occurred_at', 6)->nullable();
            $table->timestampTz('redacted_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(
                ['provider', 'provider_message_id'],
                'mail_notifications_provider_message_unique',
            );
            $table->index(
                ['notifiable_type', 'notifiable_id', 'created_at'],
                'mail_notifications_notifiable_created_index',
            );
            $table->index(
                ['status', 'created_at'],
                'mail_notifications_status_created_index',
            );
            $table->index(
                ['status', 'status_changed_at'],
                'mail_notifications_status_changed_index',
            );
            $table->index(
                ['primary_recipient_email', 'created_at'],
                'mail_notifications_recipient_created_index',
            );
            $table->index(
                ['queue_reference', 'created_at'],
                'mail_notifications_queue_created_index',
            );
            $table->index(
                ['redacted_at', 'status', 'status_changed_at', 'id'],
                'mail_notifications_redacted_time_index',
            );
        });
        $schema->create($eventTable, function (Blueprint $table) use (
            $identityCollation,
            $notificationTable,
        ): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index('nvl_mn_events_tenant_idx');
            $table->foreignUuid('mail_notification_id')
                ->constrained($notificationTable, indexName: 'nvl_mn_event_notification_fk')
                ->cascadeOnDelete();
            $provider = $table->string('provider', 128);
            $providerEventId = $table->string('provider_event_id', 255);
            $providerMessageId = $table->string('provider_message_id', 255)
                ->nullable();

            if ($identityCollation !== null) {
                $provider->collation($identityCollation);
                $providerEventId->collation($identityCollation);
                $providerMessageId->collation($identityCollation);
            }

            $table->string('normalized_type', 32);
            $table->timestampTz('occurred_at', 6);
            $table->json('metadata')->nullable();
            $table->timestampTz('processed_at', 6);
            $table->timestampTz('redacted_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(
                ['provider', 'provider_event_id'],
                'mail_notification_events_provider_event_unique',
            );
            $table->index(
                ['mail_notification_id', 'occurred_at'],
                'mail_notification_events_notification_time_index',
            );
            $table->index(
                ['redacted_at', 'occurred_at', 'id'],
                'mail_notification_events_redacted_time_index',
            );
        });
        $statuses = array_map(static fn (MailDeliveryStatus $status): string => $status->value, MailDeliveryStatus::cases());
        $this->installStatusInvariant($schema->getConnection(), $notificationTable, 'status', 'mail_notifications_status_check', $statuses);
        $this->installStatusInvariant($schema->getConnection(), $eventTable, 'normalized_type', 'mail_notification_events_normalized_type_check', $statuses);
    }

    private function createScheduledMail(Builder $schema): void
    {
        $tableName = MailNotificationsTables::get(MailNotificationsTables::ScheduledMessages);
        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index('nvl_mn_scheduled_tenant_idx');
            $table->string('ownership_key', 96)->default('platform')->index('nvl_mn_scheduled_owner_idx');
            $table->json('tenant_envelope')->nullable();
            $table->string('factory_alias', 128);
            $table->unsignedSmallInteger('payload_version');
            $table->json('payload');
            $table->json('to_recipients');
            $table->json('cc_recipients')->nullable();
            $table->json('bcc_recipients')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestampTz('scheduled_for', 6);
            $table->timestampTz('available_at', 6);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestampTz('last_attempt_at', 6)->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('locked_until', 6)->nullable();
            $table->string('last_error', 255)->nullable();
            $table->string('notifiable_type', 128)->nullable();
            $table->string('notifiable_id', 128)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('sent_at', 6)->nullable();
            $table->timestampTz('failed_at', 6)->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $table->timestampTz('redacted_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index(
                ['status', 'available_at', 'id'],
                'scheduled_mail_due_index',
            );
            $table->index(
                ['status', 'locked_until'],
                'scheduled_mail_recovery_index',
            );
            $table->index(
                ['notifiable_type', 'notifiable_id', 'created_at'],
                'scheduled_mail_notifiable_index',
            );
            $table->index('claim_token', 'scheduled_mail_claim_index');
            $table->index(
                ['status', 'sent_at'],
                'scheduled_mail_retention_sent_index',
            );
            $table->index(
                ['status', 'failed_at'],
                'scheduled_mail_retention_failed_index',
            );
            $table->index(
                ['status', 'cancelled_at'],
                'scheduled_mail_retention_cancelled_index',
            );
            $table->index(
                ['status', 'updated_at'],
                'scheduled_mail_retention_fallback_index',
            );
            $table->index(
                ['redacted_at', 'updated_at', 'id'],
                'scheduled_mail_messages_redacted_time_index',
            );
        });
        $statuses = array_map(static fn (ScheduledMailStatus $status): string => $status->value, ScheduledMailStatus::cases());
        $this->installStatusInvariant($schema->getConnection(), $tableName, 'status', 'scheduled_mail_messages_status_check', $statuses);
    }

    private function createManagedDelivery(Builder $schema): void
    {
        $schema->create(ManagedConfiguration::table('owners'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique('nvl_mn_160b7e7ed97ed6a5e4f5');
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unsignedBigInteger('broker_revision')->nullable();
        });
        $schema->create(ManagedConfiguration::table('integrations'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 40)->unique('nvl_mn_0afb03302dc1f92f4af3');
            $table->string('name');
            $table->string('icon');
            $table->json('capabilities');
            $table->timestamps();
        });
        $schema->create(ManagedConfiguration::table('provider_accounts'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->constrained(ManagedConfiguration::table('owners'), indexName: 'nvl_mn_824c8b44851f12738065')->restrictOnDelete();
            $table->string('name');
            $table->string('provider', 40);
            $table->string('status', 24)->default('active');
            $table->text('credentials');
            $table->text('webhook_secret')->nullable();
            $table->timestamps();
            $table->unique(['owner_id', 'id'], 'nvl_mn_38ac43461cef27a05fd0');
            $table->unique(['owner_id', 'name'], 'nvl_mn_fa87d9986227567ddd4c');
            $table->foreignUuid('integration_id')->nullable()->constrained(ManagedConfiguration::table('integrations'), indexName: 'nvl_mn_098e36de099f3f8e76fb')->restrictOnDelete();
            $table->text('webhook_public_key')->nullable();
        });
        $schema->create(ManagedConfiguration::table('sending_domains'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->constrained(ManagedConfiguration::table('owners'), indexName: 'nvl_mn_037bcf26bf318762157c')->restrictOnDelete();
            $table->uuid('provider_account_id');
            $table->string('remote_id')->nullable();
            $table->string('domain', 253);
            $table->string('status', 32)->default('unverified');
            $table->string('verification_source', 24)->default('manual');
            $table->json('dns_records')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_account_id', 'domain'], 'nvl_mn_0ad780d50e21ceec6c23');
            $table->unique(['owner_id', 'provider_account_id', 'id'], 'nvl_mn_2905c8131624ceb43ff5');
            $table->foreign(['owner_id', 'provider_account_id'], 'nvl_mn_1059c152922d1dc032a2')->references(['owner_id', 'id'])->on(ManagedConfiguration::table('provider_accounts'));
            $table->uuid('last_seen_sync_run_id')->nullable();
            $table->index(['domain', 'id'], 'nvl_mn_2b7a26e41ce2f72056a2');
            $table->index(['owner_id', 'domain', 'id'], 'nvl_mn_9f09489c5b02c9c68312');
        });
        $schema->create(ManagedConfiguration::table('sender_identities'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->constrained(ManagedConfiguration::table('owners'), indexName: 'nvl_mn_e4a31cf73ada221c8696')->restrictOnDelete();
            $table->uuid('provider_account_id');
            $table->string('email', 254);
            $table->string('name')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_id', 'id'], 'nvl_mn_1b64d18748998615c689');
            $table->unique(['provider_account_id', 'email'], 'nvl_mn_ee8d5bbb6ed07e055633');
            $table->foreign(['owner_id', 'provider_account_id'], 'nvl_mn_cc6c78d39b9edd317e67')
                ->references(['owner_id', 'id'])->on(ManagedConfiguration::table('provider_accounts'));
            $table->uuid('sending_domain_id')->nullable();
            $table->string('remote_id')->nullable();
            $table->string('status', 32)->default('unverified');
            $table->string('verification_source', 24)->default('manual');
            $table->string('reply_to_email', 254)->nullable();
            $table->string('reply_to_name')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreign(['owner_id', 'provider_account_id', 'sending_domain_id'], 'nvl_mn_c89543ce9050dcf0756e')->references(['owner_id', 'provider_account_id', 'id'])->on(ManagedConfiguration::table('sending_domains'));
            $table->uuid('last_seen_sync_run_id')->nullable();
            $table->index(['email', 'id'], 'nvl_mn_160b25c8854b106d5e95');
            $table->index(['owner_id', 'email', 'id'], 'nvl_mn_70e4d6456da1b2b660c2');
        });
        $schema->create(ManagedConfiguration::table('clients'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->constrained(ManagedConfiguration::table('owners'), indexName: 'nvl_mn_5d9548d9f186403432dd')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['owner_id', 'slug'], 'nvl_mn_7464a31a3cc304488c22');
            $table->index(['owner_id', 'status'], 'nvl_mn_da8ed0c756995a204e78');

            $table->unsignedBigInteger('broker_revision')->nullable();
            $table->unique(['owner_id', 'id'], 'nvl_mn_9e7056f08bb8178cd8f0');
        });
        $schema->create(ManagedConfiguration::table('owner_mail_settings'), function (Blueprint $table): void {
            $table->foreignUuid('owner_id')->primary()->constrained(ManagedConfiguration::table('owners'), indexName: 'nvl_mn_bfe5721f91b846f7a25f')->cascadeOnDelete();
            $table->string('status', 24)->default('active');
            $table->uuid('default_provider_account_id')->nullable();
            $table->string('from_email', 254)->nullable();
            $table->string('from_name')->nullable();
            $table->string('reply_to_email', 254)->nullable();
            $table->string('reply_to_name')->nullable();
            $table->timestamps();
            $table->foreign(['owner_id', 'default_provider_account_id'], 'nvl_mn_32857f0be51bcf21a5df')->references(['owner_id', 'id'])->on(ManagedConfiguration::table('provider_accounts'));
        });
        $schema->create(ManagedConfiguration::table('client_mail_settings'), function (Blueprint $table): void {
            $table->uuid('client_id')->primary();
            $table->uuid('owner_id');
            $table->string('status', 24)->default('active');
            $table->string('authentication_mode', 24)->default('legacy');
            $table->uuid('provider_account_id')->nullable();
            $table->uuid('sender_identity_id')->nullable();
            $table->string('from_email', 254)->nullable();
            $table->string('from_name')->nullable();
            $table->string('reply_to_email', 254)->nullable();
            $table->string('reply_to_name')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->timestamps();
            $table->foreign(['owner_id', 'client_id'], 'nvl_mn_3e9aaa937e7393b93add')->references(['owner_id', 'id'])->on(ManagedConfiguration::table('clients'))->cascadeOnDelete();
            $table->foreign(['owner_id', 'provider_account_id'], 'nvl_mn_355e542bd2160ae533f9')->references(['owner_id', 'id'])->on(ManagedConfiguration::table('provider_accounts'));
            $table->foreign(['owner_id', 'sender_identity_id'], 'nvl_mn_ee03a15bd1ee7609691b')->references(['owner_id', 'id'])->on(ManagedConfiguration::table('sender_identities'));
        });
        $schema->create(ManagedConfiguration::table('client_api_keys'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained(ManagedConfiguration::table('clients'), indexName: 'nvl_mn_23b00a73fd72bdaaf0d2')->cascadeOnDelete();
            $table->string('name');
            $table->string('prefix', 32)->unique('nvl_mn_3ae5059238d09180e100');
            $table->string('token_hash', 64)->unique('nvl_mn_8a7bbe14270d97db9050');
            $table->json('abilities');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        $schema->create(ManagedConfiguration::table('assets'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('media_id')->nullable()->index('nvl_mail_assets_media_index');
            $table->foreignUuid('client_id')->constrained(ManagedConfiguration::table('clients'), indexName: 'nvl_mn_360de454cf1d1019e3a3')->cascadeOnDelete();
            $table->string('name');
            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->boolean('is_public')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['disk', 'path'], 'nvl_mn_4b0e0eec1b45ff4cbb4d');
        });
        $schema->create(ManagedConfiguration::table('email_messages'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained(ManagedConfiguration::table('clients'), indexName: 'nvl_mn_92b1ac88c289ace7d853')->restrictOnDelete();
            $table->foreignUuid('provider_account_id')->constrained(ManagedConfiguration::table('provider_accounts'), indexName: 'nvl_mn_b82e47f13a3befc5c2d9')->restrictOnDelete();
            $table->uuid('email_template_version_id')->nullable();
            $table->foreignUuid('sender_identity_id')->constrained(ManagedConfiguration::table('sender_identities'), indexName: 'nvl_mn_5c8c73c26f5a50a5ba25')->restrictOnDelete();
            $table->string('status', 32)->default('accepted')->index('nvl_mn_4a53bfcd3cf9e9879d90');
            $table->string('idempotency_key', 191);
            $table->char('request_hash', 64);
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('reply_to_email')->nullable();
            $table->string('reply_to_name')->nullable();
            $table->string('subject', 998);
            $table->longText('html')->nullable();
            $table->longText('text')->nullable();
            $table->json('data')->nullable();
            $table->json('metadata')->nullable();
            $table->json('tags')->nullable();
            $table->string('external_reference', 191)->nullable()->index('nvl_mn_cbc511d77b9c3cda0d14');
            $table->timestamp('send_at')->nullable()->index('nvl_mn_836bf78d59036971b4db');
            $table->timestamp('next_attempt_at')->nullable()->index('nvl_mn_6451cb9e4fe9464f9ecb');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('lease_until')->nullable()->index('nvl_mn_6b91e105092a3970a254');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'idempotency_key'], 'nvl_mn_7f47cae51634c3faa4b8');
            $table->index(['client_id', 'created_at'], 'nvl_mn_c00b9b94c337a19b580c');
            $table->string('original_subject', 998)->nullable();
            $table->longText('original_html')->nullable();
            $table->longText('original_text')->nullable();
            $table->unique(['id', 'client_id'], 'nvl_mn_e0c03968c46aab23e803');
            $table->index(['created_at', 'id'], 'nvl_mn_2d2ab10276b397024709');
            $table->index(['status', 'created_at', 'id'], 'nvl_mn_1424d8121ea1d222f807');
            $table->index(['client_id', 'created_at', 'id'], 'nvl_mn_3f3a26558f232fce842b');
            $table->uuid('composition_revision_id')->nullable();
            $table->uuid('composition_render_id')->nullable();
            $table->json('testing_interception')->nullable();
            $table->longText('original_css')->nullable();
            $table->string('type', 20)->default('transactional');
            $table->string('category', 100)->nullable();
            $table->string('language', 35)->nullable();
            $table->index(['client_id', 'type', 'created_at'], 'nvl_mn_99df547d11d8e5171584');
            $table->index(['client_id', 'category', 'created_at'], 'nvl_mn_300cdabca5d1a83f8fea');
            $table->index(['client_id', 'language', 'created_at'], 'nvl_mn_38ad4f5f18e1e72963c3');
        });
        $schema->create(ManagedConfiguration::table('email_recipients'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('email_message_id')->constrained(ManagedConfiguration::table('email_messages'), indexName: 'nvl_mn_f896ae574f97b7de8064')->cascadeOnDelete();
            $table->string('type', 3);
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('delivery_status', 32)->default('pending');
            $table->timestamp('delivery_event_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamps();
            $table->unique(['email_message_id', 'email'], 'nvl_mn_e64784a52adcdbf833e8');
        });
        $schema->create(ManagedConfiguration::table('email_attempts'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('email_message_id')->constrained(ManagedConfiguration::table('email_messages'), indexName: 'nvl_mn_25e8807fde2264493322')->cascadeOnDelete();
            $table->foreignUuid('provider_account_id')->constrained(ManagedConfiguration::table('provider_accounts'), indexName: 'nvl_mn_af3d5c6bd1c8aae1e23b')->restrictOnDelete();
            $table->unsignedInteger('number')->default(1);
            $table->string('status', 24)->default('pending');
            $table->string('provider_message_id', 255)->nullable();
            $table->boolean('retryable')->default(false);
            $table->json('response')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['email_message_id', 'number'], 'nvl_mn_fdb8868ba224d679e25e');
            $table->unique(['provider_account_id', 'provider_message_id'], 'nvl_mn_6a32bcf698b983e7d15f');
            $table->json('remote_message_ids')->nullable();
        });
        $schema->create(ManagedConfiguration::table('webhook_receipts'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_account_id')->constrained(ManagedConfiguration::table('provider_accounts'), indexName: 'nvl_mn_179f728fa96612c1a3c2')->restrictOnDelete();
            $table->char('payload_hash', 64);
            $table->longText('payload');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index('nvl_mn_abbf3253b8cf8e5689db');
            $table->timestamp('verified_at');
            $table->timestamp('processed_at')->nullable()->index('nvl_mn_b6142068ed0862ce5615');
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['provider_account_id', 'payload_hash'], 'nvl_mn_4527bbe4e4877d83f19c');
        });
        $schema->create(ManagedConfiguration::table('email_events'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_account_id')->constrained(ManagedConfiguration::table('provider_accounts'), indexName: 'nvl_mn_1478255e3a92e0b835ca')->restrictOnDelete();
            $table->foreignUuid('email_message_id')->constrained(ManagedConfiguration::table('email_messages'), indexName: 'nvl_mn_47bc1b4037546726806a')->cascadeOnDelete();
            $table->foreignUuid('email_recipient_id')->constrained(ManagedConfiguration::table('email_recipients'), indexName: 'nvl_mn_e922e4e02ed73c604809')->cascadeOnDelete();
            $table->foreignUuid('webhook_receipt_id')->nullable()->constrained(ManagedConfiguration::table('webhook_receipts'), indexName: 'nvl_mn_c30d760e5f36f652fe67')->nullOnDelete();
            $table->string('provider_event_id', 191);
            $table->string('type', 48);
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['provider_account_id', 'provider_event_id'], 'nvl_mn_2b49a55deaa8ca79daf5');
            $table->index(['email_message_id', 'occurred_at'], 'nvl_mn_1b0aa1ec58baa74562f3');
        });
        $schema->create(ManagedConfiguration::table('recipient_suppressions'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_account_id')->constrained(ManagedConfiguration::table('provider_accounts'), indexName: 'nvl_mn_0e8a3e0e00bf2fddfcec')->restrictOnDelete();
            $table->string('email');
            $table->string('reason', 32);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_account_id', 'email'], 'nvl_mn_693dbeec614630468fbe');
        });
        $schema->create(ManagedConfiguration::table('message_assets'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('media_id')->nullable()->index('nvl_mail_message_assets_media_index');
            $table->foreignUuid('email_message_id')->constrained(ManagedConfiguration::table('email_messages'), indexName: 'nvl_mn_5aab9999f6f4a1368cdb')->cascadeOnDelete();
            $table->foreignUuid('asset_id')->nullable()->constrained(ManagedConfiguration::table('assets'), indexName: 'nvl_mn_7619ee84399ce8ad0b81')->restrictOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->char('checksum', 64);
            $table->string('disposition', 12)->default('attachment');
            $table->string('content_id', 191)->nullable();
            $table->timestamps();
            $table->unique(['email_message_id', 'asset_id'], 'nvl_mn_451a865889f7b681a01a');

            $table->string('source_kind', 16)->default('asset');
            $table->boolean('owns_file')->default(false);
            $table->timestamp('bytes_expires_at')->nullable()->index('nvl_mn_7d8518f7619151c32cf5');
            $table->timestamp('bytes_deleted_at')->nullable();
            $table->index(['disk', 'path'], 'nvl_mn_70f36b7d118d415da6e7');
        });
        $schema->create(ManagedConfiguration::table('email_flag_rules'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained(ManagedConfiguration::table('clients'), indexName: 'nvl_mn_6a6d07a5b6a90baf4004')->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('color', 7);
            $table->string('type', 20);
            $table->string('value', 998);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['client_id', 'enabled'], 'nvl_mn_22e8d7e85611b9f452d1');
        });
        $schema->create(ManagedConfiguration::table('email_message_flags'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained(ManagedConfiguration::table('clients'), indexName: 'nvl_mn_35f40f04c9406eebd4b9')->cascadeOnDelete();
            $table->uuid('email_message_id');
            $table->foreignUuid('email_flag_rule_id')->nullable()->constrained(ManagedConfiguration::table('email_flag_rules'), indexName: 'nvl_mn_bb77decb684b5de5a576')->nullOnDelete();
            $table->string('label', 100);
            $table->string('color', 7);
            $table->string('type', 20);
            $table->string('value', 998);
            $table->timestamps();
            $table->foreign(['email_message_id', 'client_id'], 'nvl_mn_3e968fe166a8ef92249a')->references(['id', 'client_id'])->on(ManagedConfiguration::table('email_messages'))->cascadeOnDelete();
            $table->unique(['email_message_id', 'email_flag_rule_id'], 'nvl_mn_32beafad27ff61db4f14');
            $table->index(['client_id', 'type'], 'nvl_mn_20c4a6387be148e3c0cb');
        });
        $schema->create(ManagedConfiguration::table('provider_sync_runs'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_account_id')->constrained(ManagedConfiguration::table('provider_accounts'), indexName: 'nvl_mn_7b73912d0a078bd6d742')->cascadeOnDelete();
            $table->foreignUuid('active_provider_account_id')->nullable()->unique('nvl_mn_f91dda5238dc4d5728f3')->constrained(ManagedConfiguration::table('provider_accounts'), indexName: 'nvl_mn_4bc50e4dd424e9adf81a')->cascadeOnDelete();
            $table->string('status', 24)->default('queued');
            $table->string('phase', 24)->default('domains');
            $table->string('domains_status', 24)->default('queued');
            $table->string('senders_status', 24)->default('queued');
            $table->unsignedInteger('pages_processed')->default(0);
            $table->unsignedInteger('domains_processed')->default(0);
            $table->unsignedInteger('senders_processed')->default(0);
            $table->unsignedInteger('domains_total')->nullable();
            $table->unsignedInteger('senders_total')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('page_attempts')->default(0);
            $table->unsignedInteger('checkpoint')->default(0);
            $table->text('cursor')->nullable();
            $table->string('queue_connection');
            $table->string('configuration_fingerprint', 64);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message')->nullable();
            $table->timestamps();
            $table->index(['provider_account_id', 'created_at'], 'nvl_mn_ef7179026e7a956489c1');
            $table->index(['status', 'next_retry_at', 'lease_until'], 'nvl_mn_9a79e9a8da5e1f8426d6');
        });
        $schema->create(ManagedConfiguration::table('provider_sync_pages'), function (Blueprint $table): void {
            $table->foreignUuid('provider_sync_run_id')->constrained(ManagedConfiguration::table('provider_sync_runs'), indexName: 'nvl_mn_e17a59ab4022128b6027')->cascadeOnDelete();
            $table->unsignedInteger('checkpoint');
            $table->string('phase', 24);
            $table->string('cursor_hash', 64);
            $table->string('page_hash', 64)->nullable();
            $table->primary(['provider_sync_run_id', 'checkpoint'], 'nvl_mn_88ec4b160f7e67984494');
            $table->unique(['provider_sync_run_id', 'phase', 'cursor_hash'], 'nvl_mn_8c3a64f0c92bedc97df0');
            $table->unique(['provider_sync_run_id', 'phase', 'page_hash'], 'nvl_mn_98e9768979646b4b6bb5');
        });
        $schema->create(ManagedConfiguration::table('staged_mail_files'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained(ManagedConfiguration::table('clients'), indexName: 'nvl_mn_626275fd12d0c2091911')->restrictOnDelete();
            $table->string('disk', 40);
            $table->string('path');
            $table->timestamp('created_at')->index('nvl_mn_c375d2ac7a7656b89321');
            $table->unique(['disk', 'path'], 'nvl_mn_90f40aca5f806d577a89');
        });
        $schema->create(ManagedConfiguration::table('email_message_references'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained(ManagedConfiguration::table('clients'), indexName: 'nvl_mn_c0ced4ceb54859de6dc9')->cascadeOnDelete();
            $table->uuid('email_message_id');
            $table->string('type', 64);
            $identifier = $table->string('reference_id', 191);
            if (in_array(Schema::connection(ManagedConfiguration::connection())->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $identifier->collation('utf8mb4_bin');
            }
            $table->foreign(['email_message_id', 'client_id'], 'nvl_mn_e13a9e5de23fa3d56c59')
                ->references(['id', 'client_id'])->on(ManagedConfiguration::table('email_messages'))->cascadeOnDelete();
            $table->unique(['email_message_id', 'type', 'reference_id'], 'nvl_mn_5b97b11d3133a40107df');
            $table->index(['client_id', 'type', 'reference_id', 'email_message_id'], 'nvl_mn_dadbed2a1ac575b3ce49');
        });
        $schema->create(ManagedConfiguration::table('client_webhook_endpoints'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained(ManagedConfiguration::table('clients'), indexName: 'nvl_mn_8861832182bb2ad90d25')->cascadeOnDelete();
            $table->text('url');
            $table->text('secret');
            $table->json('events');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_id', 'enabled'], 'nvl_mn_c0b79b3e864ee68af38c');
        });
        $schema->create(ManagedConfiguration::table('client_webhook_deliveries'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_webhook_endpoint_id')->constrained(ManagedConfiguration::table('client_webhook_endpoints'), indexName: 'nvl_mn_e02b8f17fa768bf6df30')->cascadeOnDelete();
            $table->uuid('event_id');
            $table->string('event_key', 191);
            $table->string('event_type', 64);
            $table->text('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['client_webhook_endpoint_id', 'event_key'], 'nvl_mn_ac2f36e2b6d0d0cdd0e6');
            $table->index(['status', 'next_attempt_at'], 'nvl_mn_37bc23419d43f85738d5');
            $table->index(['status', 'lease_until'], 'nvl_mn_6a042f2fe36240d256da');
        });
        $schema->create(ManagedConfiguration::table('marketing_opt_outs'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained(ManagedConfiguration::table('clients'), indexName: 'nvl_mn_cd74d3ac5778710ce678')->restrictOnDelete();
            $table->string('email', 254);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'email'], 'nvl_mn_e908f900ec9a319a2fdf');
        });
    }

    /**
     * Install one driver-native status invariant.
     *
     * @param  list<string>  $allowedValues
     */
    private function installStatusInvariant(
        Connection $connection,
        string $table,
        string $column,
        string $constraint,
        array $allowedValues,
    ): void {
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $this->installSqliteStatusInvariant(
                $connection,
                $table,
                $column,
                $constraint,
                $allowedValues,
            );

            return;
        }

        $grammar = $connection->getQueryGrammar();
        $wrappedColumn = $grammar->wrap($column);
        $checkedColumn = in_array($driver, ['mysql', 'mariadb'], true)
            ? 'binary '.$wrappedColumn
            : $wrappedColumn;
        $connection->statement(sprintf(
            'alter table %s add constraint %s check (%s in (%s))',
            $grammar->wrapTable($table),
            $grammar->wrap($constraint),
            $checkedColumn,
            $this->quotedValues($allowedValues),
        ));
    }

    /**
     * Install paired SQLite triggers without rebuilding a host-extended table.
     *
     * @param  list<string>  $allowedValues
     */
    private function installSqliteStatusInvariant(
        Connection $connection,
        string $table,
        string $column,
        string $constraint,
        array $allowedValues,
    ): void {
        $grammar = $connection->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedColumn = $grammar->wrap($column);
        $quotedValues = $this->quotedValues($allowedValues);
        $error = $this->quoteValue(
            "Status invariant [{$constraint}] rejected an invalid value.",
        );
        $connection->statement(sprintf(
            'create trigger %s before insert on %s for each row when new.%s not in (%s) begin select raise(abort, %s); end',
            $grammar->wrap($constraint.'_insert'),
            $wrappedTable,
            $wrappedColumn,
            $quotedValues,
            $error,
        ));
        $connection->statement(sprintf(
            'create trigger %s before update of %s on %s for each row when new.%s not in (%s) begin select raise(abort, %s); end',
            $grammar->wrap($constraint.'_update'),
            $wrappedColumn,
            $wrappedTable,
            $wrappedColumn,
            $quotedValues,
            $error,
        ));
    }

    /**
     * Render an SQL string-list literal from trusted enum values.
     *
     * @param  list<string>  $values
     */
    private function quotedValues(array $values): string
    {
        return implode(', ', array_map(
            $this->quoteValue(...),
            $values,
        ));
    }

    /**
     * Quote one trusted enum or error-message literal.
     */
    private function quoteValue(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
