<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Services\WebhookProcessor;
use Nvl\MailNotifications\Tests\Fixtures\TrackedMail;
use Nvl\MailNotifications\ValueObjects\WebhookRequest;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

it('uses the current application scope before admitting platform webhook callbacks', function (): void {
    Mail::mailer('plugged-provider')->to('scope@example.test')->send(new TrackedMail(category: 'test.webhook-scope'));
    $notification = MailNotification::query()->sole();
    Schema::table($notification->getTable(), static function (Blueprint $table): void {
        $table->uuid('tenant_id')->nullable();
    });
    config()->set('nvl-tenancy.enabled', true);
    $mode = TenantContextMode::Platform;
    app()->scoped(TenantContext::class, static function () use (&$mode): TenantContext {
        return new class($mode) implements TenantContext
        {
            /** Retain only this lifecycle's admitted ownership mode. */
            public function __construct(private TenantContextMode $mode) {}

            /** Return this lifecycle's immutable context. */
            public function snapshot(): TenantContextSnapshot
            {
                return new TenantContextSnapshot($this->mode);
            }

            /** Deny a tenant identifier in this platform-only fixture. */
            public function requireTenant(): TenantId
            {
                throw new TenantContextMissing;
            }
        };
    });
    app(WebhookProcessor::class);
    app()->forgetScopedInstances();
    $mode = TenantContextMode::Unresolved;
    $request = Request::create('/mail-webhooks/plugged-provider', 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PLUGGED_SIGNATURE' => 'valid-signature',
    ], content: json_encode(['event_id' => 'scope-event', 'message_id' => $notification->provider_message_id], JSON_THROW_ON_ERROR));

    expect(fn () => app(WebhookProcessor::class)->process('plugged-provider', WebhookRequest::fromLaravelRequest('plugged-provider', $request)))
        ->toThrow(TenantBoundaryViolation::class, 'Platform mail callbacks require an explicitly authorized platform context.');
});
