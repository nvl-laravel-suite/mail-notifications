<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

/**
 * @api

 * Represents a tracked message cancelled before transport acceptance.
 */
final class MailDeliveryCancelled extends MailNotificationsException {}
