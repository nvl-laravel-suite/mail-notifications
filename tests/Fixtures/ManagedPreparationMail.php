<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use Nvl\MailNotifications\Contracts\TrackableMessage;
use Nvl\MailNotifications\Laravel\Concerns\SubmitsMailDelivery;
use Nvl\MailNotifications\Laravel\Concerns\TracksMailDelivery;
use Nvl\MailNotifications\ValueObjects\TrackingContext;

/** Host-owned tracked Mailable exercising opt-in managed preparation. */
class ManagedPreparationMail extends Mailable implements TrackableMessage
{
    use SubmitsMailDelivery, TracksMailDelivery;

    public static int $builds = 0;

    public function build(): static
    {
        self::$builds++;

        return $this->subject(__('managed.subject'))->html('<p>'.__('managed.body').'</p>')->text('mail-notifications-tests::managed-text');
    }

    public function trackingContext(): TrackingContext
    {
        return new TrackingContext(category: 'managed-test');
    }
}
