<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

/**
 * Signals that live cancellation or authority checks prevent the requested transport operation.
 *
 * @api
 *
 * Represents a tracked message cancelled before transport acceptance.
 */
final class MailDeliveryCancelled extends MailNotificationsException {}
