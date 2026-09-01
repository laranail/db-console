<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Events\AccountPasswordRotated;
use Simtabi\Laranail\DBConsole\Events\DatabaseCreated;
use Simtabi\Laranail\DBConsole\Events\DatabaseDropped;
use Simtabi\Laranail\DBConsole\Events\SuspiciousActivity;
use Simtabi\Laranail\DBConsole\Listeners\RaiseAlerts;
use Simtabi\Laranail\DBConsole\Notifications\CredentialRotatedNotification;
use Simtabi\Laranail\DBConsole\Notifications\DestructiveActionNotification;

beforeEach(function (): void {
    // Dispatching real events runs the audit listener, which needs the trail.
    $this->migrateCatalog();
});

describe('notification routing (section 18)', function (): void {
    it('sends a destructive-action notification to the destructive recipients', function (): void {
        Notification::fake();
        config()->set('laranail.db-console.notifications.recipients.destructive', ['ops@example.com']);

        event(new DatabaseDropped('prod-mysql', ['target' => 'shop_prod']));

        Notification::assertSentOnDemand(DestructiveActionNotification::class);
    });

    it('routes a password rotation as a credential notification (never the value)', function (): void {
        Notification::fake();
        config()->set('laranail.db-console.notifications.recipients.routine', ['ops@example.com']);

        event(new AccountPasswordRotated('prod-mysql', ['target' => 'shop_user@%']));

        Notification::assertSentOnDemand(CredentialRotatedNotification::class);
    });

    it('is opt-in: no recipients configured → nothing sent', function (): void {
        Notification::fake();
        config()->set('laranail.db-console.notifications.recipients.destructive', []);

        event(new DatabaseDropped('prod-mysql', ['target' => 'shop_prod']));

        Notification::assertNothingSent();
    });

    it('does not notify for routine successful creates by default', function (): void {
        Notification::fake();
        config()->set('laranail.db-console.notifications.recipients.routine', []);

        event(new DatabaseCreated('prod-mysql', ['target' => 'shop_prod']));

        Notification::assertNothingSent();
    });
});

describe('alerts (high-severity subset)', function (): void {
    it('posts a security alert to the alert webhook on suspicious activity', function (): void {
        Http::fake();
        config()->set('laranail.db-console.alerts.webhook', 'https://alerts.example.com/hook');

        app(RaiseAlerts::class)->handle(new SuspiciousActivity('prod-mysql', 'a root-like admin was detected'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://alerts.example.com/hook'
            && str_contains((string) ($request['text'] ?? ''), 'db-console alert'));
    });

    it('redacts the webhook url from the log when alert delivery fails (rule 15)', function (): void {
        $webhook = 'https://alerts.example.com/hook/T000/SECRET-TOKEN';
        config()->set('laranail.db-console.alerts.webhook', $webhook);

        // A transport error routinely embeds the full URL — whose path is a
        // bearer token — in its message. Force that shape.
        Http::fake(function () use ($webhook): void {
            throw new RuntimeException('cURL error 28: timeout for '.$webhook);
        });

        $logged = [];
        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message;
        });

        app(RaiseAlerts::class)->handle(new SuspiciousActivity('prod-mysql', 'a root-like admin was detected'));

        $failure = collect($logged)->first(
            fn (MessageLogged $m): bool => str_contains($m->message, 'alert.delivery_failed'),
        );

        expect($failure)->not->toBeNull();

        $error = (string) ($failure->context['error'] ?? '');
        expect($error)->toContain('[redacted-webhook]')
            ->and($error)->not->toContain('SECRET-TOKEN');
    });

    it('does not alert for a routine info event', function (): void {
        Http::fake();
        config()->set('laranail.db-console.alerts.webhook', 'https://alerts.example.com/hook');

        app(RaiseAlerts::class)->handle(new DatabaseCreated('prod-mysql', ['target' => 'shop_prod']));

        Http::assertNothingSent();
    });

    it('OperationType destructive drives destructive-category routing', function (): void {
        expect(OperationType::DatabaseDrop->isDestructive())->toBeTrue()
            ->and(OperationType::AccountDrop->isDestructive())->toBeTrue()
            ->and(OperationType::DatabaseCreate->isDestructive())->toBeFalse();
    });
});
