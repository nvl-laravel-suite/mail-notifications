<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

/**
 * Signals a tracking failure without claiming that the underlying provider submission failed.
 *
 * @api
 *
 * Represents a package-level tracking configuration or lifecycle failure.
 */
final class MailTrackingException extends MailNotificationsException {}
