<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\Support\Models\ConfiguredModelRegistration;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use ReflectionMethod;

/** Resolves the configured mail roots on their shared native delivery storage.
 * @api
 */
final class MailModelRegistry
{
    /** @var ConfiguredModelRegistration<MailNotification> */
    private readonly ConfiguredModelRegistration $notifications;

    /** @var ConfiguredModelRegistration<ScheduledMailMessage> */
    private readonly ConfiguredModelRegistration $scheduled;

    public function __construct(Repository $configuration, OwnerRegistry $identities)
    {
        $this->notifications = new ConfiguredModelRegistration(
            $configuration, $identities, 'nvl-mail-notifications.models.notification', MailNotification::class, 'mail.notifications',
            static fn (string $class) => self::assertHooks($class, MailNotification::class),
        );
        $this->scheduled = new ConfiguredModelRegistration(
            $configuration, $identities, 'nvl-mail-notifications.models.scheduled_message', ScheduledMailMessage::class, 'mail.scheduled',
            static fn (string $class) => self::assertHooks($class, ScheduledMailMessage::class),
        );
    }

    /** Freeze both roots before resource compilation.
     * @internal
     */
    public function prepare(): void
    {
        $this->notifications->prepare();
        $this->scheduled->prepare();
    }

    /** @return class-string<MailNotification> */
    public function notificationClass(): string
    {
        $class = $this->notifications->modelClass();
        self::assertStorage(new $class, new MailNotification);

        return $class;
    }

    /** @return class-string<ScheduledMailMessage> */
    public function scheduledClass(): string
    {
        $class = $this->scheduled->modelClass();
        self::assertStorage(new $class, new ScheduledMailMessage);

        return $class;
    }

    /** @internal */
    public static function notification(): MailNotification
    {
        $class = Container::getInstance()->make(self::class)->notificationClass();

        return new $class;
    }

    /** @internal */
    public static function scheduled(): ScheduledMailMessage
    {
        $class = Container::getInstance()->make(self::class)->scheduledClass();

        return new $class;
    }

    /** @internal
     * @return Builder<MailNotification>
     */
    public static function notificationsQuery(): Builder
    {
        return self::notification()->newQuery();
    }

    /** @internal
     * @return Builder<ScheduledMailMessage>
     */
    public static function scheduledQuery(): Builder
    {
        return self::scheduled()->newQuery();
    }

    /** @param class-string<Model> $class
     * @param  class-string<Model>  $base
     */
    private static function assertHooks(string $class, string $base): void
    {
        foreach (['freshTimestamp', 'getDateFormat', 'ownershipDefinition'] as $method) {
            if ((new ReflectionMethod($class, $method))->getDeclaringClass()->getName()
                !== (new ReflectionMethod($base, $method))->getDeclaringClass()->getName()) {
                throw new OwnershipConfigurationInvalid('Configured mail models must retain native UTC and resource classification hooks.');
            }
        }
    }

    private static function assertStorage(Model $model, Model $base): void
    {
        if ($model->getConnection() !== $base->getConnection() || $model->getTable() !== $base->getTable()
            || $model->getDateFormat() !== $base->getDateFormat()) {
            throw new OwnershipConfigurationInvalid('Configured mail roots must share the package storage and timestamp format with fixed delivery records.');
        }
    }
}
