<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures\Ownership;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Nvl\MailNotifications\Contracts\TrackableMessage;
use Nvl\MailNotifications\Laravel\Concerns\TracksMailDelivery;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use RuntimeException;

/** Carries explicit producer proof through Laravel's real SendQueuedMailable wrapper. */
final class NativeWorkerMail extends Mailable implements OwnershipQueuedJob, ShouldQueue, TrackableMessage
{
    use Queueable;
    use TracksMailDelivery;

    /** Keep only immutable captured proof and a scalar content label in the serialized message. */
    public function __construct(private readonly OwnershipJobEnvelope $capture, private readonly string $label) {}

    /** Return construction-time ownership transport proof. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->capture;
    }

    /** Build only after worker admission and simulate one transient transport-preparation failure. */
    public function build(): static
    {
        $previous = DB::table('ownership_queue_probes')->where('stage', 'mail-build')->where('label', $this->label)->count();
        DB::table('ownership_queue_probes')->insert(['stage' => 'mail-build', 'owner' => app(OwnershipContext::class)->snapshot()->owner->key ?? 'unresolved', 'label' => $this->label]);
        if ($this->label === 'retry' && $previous === 0) {
            throw new RuntimeException('Transient native fixture transport preparation.');
        }

        return $this->from('sender@example.test')->subject($this->label)->html('Native private content');
    }

    /** Expose the stable fixture category, never actor or owner payload metadata. */
    public function trackingContext(): TrackingContext
    {
        return TrackingContext::forCategory('native.worker');
    }
}
