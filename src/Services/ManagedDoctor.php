<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Models\Asset;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\MessageAsset;
use Nvl\MailNotifications\Services\Delivery\ClientWebhookPublisher;
use Nvl\MailNotifications\Services\Delivery\MailMediaAuthorization;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Nvl\MailNotifications\ValueObjects\MailNotificationsDoctorCheck;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Enums\MediaDeliveryMode;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Services\DefaultMediaAuthorization;
use Nvl\Media\Services\MediaDeliveryPolicy;
use Nvl\Media\Services\NullMediaContentScanner;
use Nvl\Support\Schema\SchemaIdentities;
use RuntimeException;
use Throwable;

/** Local managed-engine configuration and schema checks; no provider, scanner or filesystem operations.
 * @internal
 */
final class ManagedDoctor
{
    /**
     * Inspect local delivery storage, configuration and runtime prerequisites without sending provider requests.
     *
     * @return list<MailNotificationsDoctorCheck>
     */
    public function inspect(): array
    {
        if (config('nvl-mail-notifications.managed.enabled', false) !== true) {
            return [];
        }

        return [$this->schema(), $this->connections(), $this->disks(), $this->authorization(), $this->scanning(), $this->clientWebhookQueue()];
    }

    private function clientWebhookQueue(): MailNotificationsDoctorCheck
    {
        try {
            app(ClientWebhookPublisher::class)->durableConnection();

            return new MailNotificationsDoctorCheck('managed.client_webhook_queue', 'warning', true, 'Client webhook callbacks resolve to a durable queue connection. Worker availability was not tested.');
        } catch (Throwable) {
            return new MailNotificationsDoctorCheck('managed.client_webhook_queue', 'warning', false, 'Client webhook callbacks require a durable queue connection using the database, redis, sqs or beanstalkd driver. Until one is configured, callback deliveries stay pending and are never sent.');
        }
    }

    private function schema(): MailNotificationsDoctorCheck
    {
        try {
            $identities = SchemaIdentities::package('mail-notifications');
            $tables = array_diff_key($identities['tables'] ?? [], array_flip(['notifications', 'events', 'scheduled_messages']));
            $schema = (new Client)->getConnection()->getSchemaBuilder();
            $complete = $tables !== [];
            foreach (array_keys($tables) as $key) {
                $complete = $schema->hasTable(MailNotificationsTables::get($key)) && $complete;
            }
            foreach ([new Asset, new MessageAsset] as $model) {
                $columns = $model->getConnection()->getSchemaBuilder()->getColumns($model->getTable());
                $link = array_find($columns, static fn (array $column): bool => $column['name'] === 'media_id');
                $complete = $link !== null && $link['nullable'] && $complete;
            }

            return new MailNotificationsDoctorCheck('managed.schema', 'error', $complete, $complete
                ? 'Managed mail tables and both nullable Media links are present on their configured storage.'
                : 'Managed mail storage is incomplete. Apply the complete initial mail schema, including nullable Media links, on its configured connection.');
        } catch (Throwable) {
            return new MailNotificationsDoctorCheck('managed.schema', 'error', false, 'Managed mail schema could not be inspected on its configured connection.');
        }
    }

    private function connections(): MailNotificationsDoctorCheck
    {
        try {
            $connection = (new Client)->getConnection();
            $same = $connection === (new Asset)->getConnection()
                && $connection === (new MessageAsset)->getConnection()
                && $connection === $this->mediaModel()->getConnection()
                && $connection === (new MediaOwnerSlotOperation)->getConnection();

            return new MailNotificationsDoctorCheck('managed.connections', 'error', $same, $same
                ? 'Managed mail, Media and the owner-slot ledger resolve to the same actual Laravel connection.'
                : 'Managed mail, Media and the owner-slot ledger must share the same actual Laravel connection.');
        } catch (Throwable) {
            return new MailNotificationsDoctorCheck('managed.connections', 'error', false, 'Managed mail and Media connection configuration could not be resolved.');
        }
    }

    private function disks(): MailNotificationsDoctorCheck
    {
        try {
            $private = ManagedConfiguration::string('storage.disk', 'local');
            $public = ManagedConfiguration::string('storage.public_disk', 'public');
            $allowed = config('nvl-media.allowed_disks', []);
            $roots = config('nvl-media.root_folders', []);
            if ($private === '' || $public === '' || $private === $public || ! is_array($allowed) || ! is_array($roots)) {
                throw new RuntimeException;
            }
            $policy = app(MediaDeliveryPolicy::class);
            foreach ([[$private, MediaVisibility::Private], [$public, MediaVisibility::Public]] as [$disk, $visibility]) {
                $definition = config('filesystems.disks.'.$disk);
                $root = array_key_exists($disk, $roots) ? $roots[$disk] : config('nvl-media.root_folder', '');
                if (! is_array($definition) || ! is_string($definition['driver'] ?? null) || $definition['driver'] === ''
                    || ! in_array($disk, $allowed, true) || ! is_string($root) || trim($root, " /\\\t\n\r\0\x0B") === '') {
                    throw new RuntimeException;
                }
                $policy->mode($disk, $visibility, MediaDeliveryMode::Application);
            }

            return new MailNotificationsDoctorCheck('managed.disks', 'error', true, 'Distinct mail disks, Media visibility policies and non-empty cleanup roots are declared. Storage access was not tested.');
        } catch (Throwable) {
            return new MailNotificationsDoctorCheck('managed.disks', 'error', false, 'Configure distinct defined and admitted private/public mail disks, application delivery policies and non-empty Media cleanup roots.');
        }
    }

    private function authorization(): MailNotificationsDoctorCheck
    {
        try {
            return $this->authorizationPolicyCheck(app(MediaAuthorization::class));
        } catch (Throwable) {
            return new MailNotificationsDoctorCheck('managed.authorization', 'error', false, 'Managed mail Media authorization could not be resolved.');
        }
    }

    private function authorizationPolicyCheck(MediaAuthorization $policy): MailNotificationsDoctorCheck
    {
        if ($policy instanceof DefaultMediaAuthorization) {
            return new MailNotificationsDoctorCheck('managed.authorization', 'error', false, 'Managed mail requires an explicit MailMediaAuthorization decorator or a host Media authorization policy.');
        }

        return new MailNotificationsDoctorCheck('managed.authorization', 'error', true, $policy instanceof MailMediaAuthorization
            ? 'The explicit mail Media policy decorator is configured. Runtime actor permissions are not verified.'
            : 'A custom host Media authorization policy is configured. Runtime actor permissions are not verified.');
    }

    private function scanning(): MailNotificationsDoctorCheck
    {
        try {
            return $this->scannerCheck(app(MediaContentScanner::class));
        } catch (Throwable) {
            return new MailNotificationsDoctorCheck('managed.scanning', 'error', false, 'The configured Media scanner could not be resolved. Upload scanner readiness is unavailable; no scan was performed.');
        }
    }

    private function scannerCheck(MediaContentScanner $scanner): MailNotificationsDoctorCheck
    {
        if ($scanner instanceof NullMediaContentScanner && config('nvl-media.scanner.required', false) === true && config('nvl-media.scanner.allow_noop', false) !== true) {
            return new MailNotificationsDoctorCheck('managed.scanning', 'error', false, 'Required Media scanning cannot use the no-op scanner without an explicit override. No scan was performed.');
        }

        return new MailNotificationsDoctorCheck('managed.scanning', 'info', true, $scanner instanceof NullMediaContentScanner
            ? 'No external Media scanner is configured. No scan was performed and malware safety is not established.'
            : 'A Media scanner is configured. No scan was performed and malware safety is not established.');
    }

    private function mediaModel(): Media
    {
        $class = config('nvl-media.models.media', Media::class);
        if (! is_string($class) || ! is_a($class, Media::class, true)) {
            throw new RuntimeException;
        }

        return new $class;
    }
}
