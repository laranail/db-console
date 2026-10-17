<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Notification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Simtabi\Laranail\DBConsole\Events\AccountAdopted;
use Simtabi\Laranail\DBConsole\Events\DatabaseCreated;
use Simtabi\Laranail\DBConsole\Events\DatabaseDropped;
use Simtabi\Laranail\DBConsole\Events\SuspiciousActivity;
use Simtabi\Laranail\DBConsole\Events\AccountCreationRefused;
use Simtabi\Laranail\DBConsole\Events\AccountPasswordRotated;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\NotifiableUser;
use Illuminate\Notifications\Slack\SlackMessage as SlackBlockMessage;
use Simtabi\Laranail\DBConsole\Notifications\SecurityAlertNotification;
use Illuminate\Notifications\Messages\SlackMessage as SlackWebhookMessage;
use Simtabi\Laranail\DBConsole\Notifications\ExistingResourceNotification;
use Simtabi\Laranail\DBConsole\Notifications\CredentialRotatedNotification;
use Simtabi\Laranail\DBConsole\Notifications\DestructiveActionNotification;

/*
 * Phase C: notifications reach someone. Channels per category, Slack and an
 * in-app inbox alongside mail, and the keys that were configured but never
 * read (notifications.queue, quiet_hours, alerts.channel) now are.
 */

beforeEach(function (): void {
    $this->migrateCatalog();
    config()->set('laranail.db-console.notifications.recipients.security', ['security@example.com']);
    config()->set('laranail.db-console.notifications.recipients.routine', ['ops@example.com']);
});

describe('channels per category', function (): void {
    it('sends mail alone by default', function (): void {
        Notification::fake();

        event(new AccountCreationRefused('prod', ['target' => 'shop@%']));

        Notification::assertSentOnDemand(
            ExistingResourceNotification::class,
            fn ($n, array $channels, AnonymousNotifiable $to): bool => $channels === ['mail']
                && $to->routes['mail'] === ['security@example.com'],
        );
    });

    it('adds Slack when the category lists it and a route is set', function (): void {
        Notification::fake();
        config()->set('laranail.db-console.notifications.channels.routine', ['mail', 'slack']);
        config()->set('laranail.db-console.notifications.slack.route', '#db-ops');
        config()->set('laranail.db-console.notifications.recipients.destructive', []);

        event(new AccountPasswordRotated('prod', ['target' => 'shop@%']));

        Notification::assertSentOnDemand(
            CredentialRotatedNotification::class,
            fn ($n, array $channels, AnonymousNotifiable $to): bool => $channels === ['mail', 'slack'] && $to->routes['slack'] === '#db-ops',
        );
    });

    it('skips a listed channel that has no route, and sends nothing with no route at all', function (): void {
        Notification::fake();
        config()->set('laranail.db-console.notifications.channels.destructive', ['slack']);
        config()->set('laranail.db-console.notifications.recipients.destructive', ['ops@example.com']);

        event(new DatabaseDropped('prod', ['target' => 'shop_prod']));

        Notification::assertNothingSent();
    });

    it('delivers to the in-app inbox of the configured users', function (): void {
        Notification::fake();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        $owner = NotifiableUser::query()->create(['name' => 'owner']);
        config()->set('laranail.db-console.rbac.user_model', NotifiableUser::class);
        config()->set('laranail.db-console.notifications.channels.destructive', ['database']);
        config()->set('laranail.db-console.notifications.database.users', [(string) $owner->getKey()]);

        event(new DatabaseDropped('prod', ['target' => 'shop_prod']));

        Notification::assertSentTo($owner, DestructiveActionNotification::class, fn ($n, array $channels): bool => $channels === ['database']);
    });

    it('routes a Warning or Critical event on alerts.channel instead', function (): void {
        Notification::fake();
        config()->set('laranail.db-console.alerts.channel', 'slack');
        config()->set('laranail.db-console.notifications.slack.route', 'https://hooks.slack.com/services/T/B/x');

        event(new SuspiciousActivity('prod', 'a root-like admin was detected'));

        Notification::assertSentOnDemand(
            SecurityAlertNotification::class,
            fn ($n, array $channels): bool => $channels === ['slack'],
        );
    });

    it('sends an adoption to the security recipients', function (): void {
        Notification::fake();
        config()->set('laranail.db-console.notifications.recipients.destructive', []);

        event(new AccountAdopted('prod', ['target' => 'shop@%']));

        Notification::assertSentOnDemand(
            ExistingResourceNotification::class,
            fn ($n, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === ['security@example.com'],
        );
    });
});

describe('the Slack message', function (): void {
    it('uses the incoming-webhook form for a URL route and Block Kit for a channel', function (): void {
        $notification = new ExistingResourceNotification(new AccountAdopted('prod', ['target' => 'shop@%']));

        $webhook = $notification->toSlack((new AnonymousNotifiable)->route('slack', 'https://hooks.slack.com/services/T/B/x'));
        $bot = $notification->toSlack((new AnonymousNotifiable)->route('slack', '#db-ops'));

        expect($webhook)->toBeInstanceOf(SlackWebhookMessage::class)
            ->and($webhook->content)->toContain('adopted')
            ->and($bot)->toBeInstanceOf(SlackBlockMessage::class)
            ->and(json_encode($bot->toArray()))->toContain('shop@%');
    });
});

describe('notifications.queue', function (): void {
    it('queues by default', function (): void {
        Queue::fake();

        event(new AccountCreationRefused('prod', ['target' => 'shop@%']));

        Queue::assertPushed(SendQueuedNotifications::class);
    });

    it('queues on the named queue when it is a string', function (): void {
        Queue::fake();
        config()->set('laranail.db-console.notifications.queue', 'alerts');

        event(new AccountCreationRefused('prod', ['target' => 'shop@%']));

        Queue::assertPushedOn('alerts', SendQueuedNotifications::class);
    });

    it('sends during the request when it is off', function (): void {
        Queue::fake();
        Mail::fake();
        config()->set('laranail.db-console.notifications.queue', false);

        event(new AccountCreationRefused('prod', ['target' => 'shop@%']));

        Queue::assertNothingPushed();
    });
});

describe('quiet hours', function (): void {
    beforeEach(function (): void {
        config()->set('laranail.db-console.notifications.queue', false);
        config()->set('laranail.db-console.notifications.quiet_hours', ['start' => '22:00', 'end' => '07:00', 'timezone' => 'UTC']);
        config()->set('laranail.db-console.notifications.channels.routine', ['mail']);
        Queue::fake();
        Mail::fake();
    });

    afterEach(fn () => CarbonImmutable::setTestNow());

    it('holds a routine notification until the window ends, across midnight', function (): void {
        CarbonImmutable::setTestNow('2026-10-17 23:30:00 UTC');

        event(new AccountPasswordRotated('prod', ['target' => 'shop@%']));

        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            $delay = $job->notification->delay;

            return $delay instanceof DateTimeInterface && CarbonImmutable::instance($delay)->equalTo(CarbonImmutable::parse('2026-10-18 07:00:00 UTC'));
        });
    });

    it('never holds a security notification', function (): void {
        CarbonImmutable::setTestNow('2026-10-17 23:30:00 UTC');

        event(new AccountCreationRefused('prod', ['target' => 'shop@%']));

        Queue::assertNothingPushed();
    });

    it('sends a routine notification outside the window', function (): void {
        CarbonImmutable::setTestNow('2026-10-17 12:00:00 UTC');

        event(new AccountPasswordRotated('prod', ['target' => 'shop@%']));

        Queue::assertNothingPushed();
    });

    it('does not notify for a routine create, held or not', function (): void {
        CarbonImmutable::setTestNow('2026-10-17 23:30:00 UTC');

        event(new DatabaseCreated('prod', ['target' => 'shop_prod']));

        Queue::assertNothingPushed();
    });
});
