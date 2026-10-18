<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Illuminate\Notifications\Slack\SlackChannel;
use Illuminate\Notifications\AnonymousNotifiable;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Enums\NotificationCategory;
use Simtabi\Laranail\DBConsole\Events\ExistingResourceEvent;
use Simtabi\Laranail\DBConsole\Events\Contracts\RecordsToAudit;
use Simtabi\Laranail\DBConsole\Notifications\DBConsoleNotification;
use Simtabi\Laranail\DBConsole\Notifications\SecurityAlertNotification;
use Simtabi\Laranail\DBConsole\Events\Contracts\CategorizesNotification;
use Simtabi\Laranail\DBConsole\Notifications\OperationFailedNotification;
use Simtabi\Laranail\DBConsole\Notifications\PrivilegeChangeNotification;
use Simtabi\Laranail\DBConsole\Notifications\ExistingResourceNotification;
use Simtabi\Laranail\DBConsole\Notifications\CredentialRotatedNotification;
use Simtabi\Laranail\DBConsole\Notifications\DestructiveActionNotification;

/**
 * Routes domain events to Laravel notifications, per category (section 18).
 *
 * - **Channels**: `notifications.channels.<category>` lists mail, slack and
 *   database (default mail). A Warning or Critical event goes out on
 *   `alerts.channel` as well, so loud events get their own route without
 *   losing their category's.
 * - **Recipients**: mail goes to `notifications.recipients.<category>`,
 *   slack to `notifications.slack.route` (a webhook URL or a bot channel),
 *   database to the `notifications.database.users` ids of the RBAC user
 *   model. A channel with no route is skipped; with none at all, nothing is
 *   sent, so delivery stays opt-in.
 * - **Queue**: `notifications.queue` true (default queue), a queue name, or
 *   false to send in the request.
 * - **Quiet hours**: `notifications.quiet_hours` holds Routine notifications
 *   until the window ends. Destructive and Security ones are never held.
 */
final readonly class SendNotifications
{
    private const array CHANNELS = ['mail', 'slack', 'database'];

    public function __construct(private Config $config) {}

    public function handle(RecordsToAudit $event): void
    {
        $notification = $this->notificationFor($event);
        if (! $notification instanceof DBConsoleNotification) {
            return;
        }

        $category = $this->categoryFor($event);
        $channels = $this->channelsFor($category, $event);

        $anonymous = $this->anonymousRoutes($category, $channels);
        $users = in_array('database', $channels, true) ? $this->databaseUsers() : [];

        if (! $anonymous instanceof AnonymousNotifiable && $users === []) {
            return;   // opt-in: no route configured for this category
        }

        $this->schedule($notification, $category);

        if ($anonymous instanceof AnonymousNotifiable) {
            $this->deliver($anonymous, (clone $notification)->onChannels(array_keys($anonymous->routes)));
        }

        if ($users !== []) {
            $this->deliver($users, (clone $notification)->onChannels(['database']));
        }
    }

    private function notificationFor(RecordsToAudit $event): ?DBConsoleNotification
    {
        return match (true) {
            $event instanceof ExistingResourceEvent                                                                                                     => new ExistingResourceNotification($event),
            $event->outcome()->value === 'failed'                                                                                                       => new OperationFailedNotification($event),
            $event->operation()->isDestructive()                                                                                                        => new DestructiveActionNotification($event),
            $event->operation() === OperationType::AccountRotate                                                                                        => new CredentialRotatedNotification($event),
            in_array($event->operation(), [OperationType::GrantCreate, OperationType::GrantRevoke, OperationType::Attach, OperationType::Detach], true) => new PrivilegeChangeNotification($event),
            $event->severity()->alerts()                                                                                                                => new SecurityAlertNotification($event),
            default                                                                                                                                     => null,
        };
    }

    private function categoryFor(RecordsToAudit $event): NotificationCategory
    {
        if ($event instanceof CategorizesNotification) {
            return $event->notificationCategory();
        }

        if ($event->severity()->alerts() && ! $event->operation()->isDestructive()) {
            return NotificationCategory::Security;
        }

        if ($event->operation()->isDestructive() || $event->outcome()->value === 'failed') {
            return NotificationCategory::Destructive;
        }

        return NotificationCategory::Routine;
    }

    /**
     * @return list<string>
     */
    private function channelsFor(NotificationCategory $category, RecordsToAudit $event): array
    {
        $channels = $this->channelList($this->config->get("laranail.db-console.notifications.channels.{$category->value}", ['mail']));

        // A loud event keeps its category's routes and adds the alert channel.
        if ($event->severity()->alerts()) {
            $channels = [...$channels, ...$this->channelList($this->config->get('laranail.db-console.alerts.channel', 'mail'))];
        }

        return array_values(array_unique(array_intersect($channels, self::CHANNELS)));
    }

    /**
     * A channel list from config: an array, or a comma-separated string.
     *
     * @return list<string>
     */
    private function channelList(mixed $configured): array
    {
        $channels = is_string($configured) ? explode(',', $configured) : (array) $configured;

        return array_values(array_map(static fn (mixed $c): string => trim(is_scalar($c) ? (string) $c : ''), $channels));
    }

    /**
     * The on-demand routes for mail and slack, or null when neither has one.
     *
     * @param list<string> $channels
     */
    private function anonymousRoutes(NotificationCategory $category, array $channels): ?AnonymousNotifiable
    {
        $notifiable = new AnonymousNotifiable;

        if (in_array('mail', $channels, true)) {
            $recipients = $this->recipientsFor($category);
            if ($recipients !== []) {
                $notifiable->route('mail', $recipients);
            }
        }

        if (in_array('slack', $channels, true) && class_exists(SlackChannel::class)) {
            $route = $this->config->get('laranail.db-console.notifications.slack.route');
            if (is_string($route) && $route !== '') {
                $notifiable->route('slack', $route);
            }
        }

        return $notifiable->routes === [] ? null : $notifiable;
    }

    /**
     * @return list<string>
     */
    private function recipientsFor(NotificationCategory $category): array
    {
        /** @var array<int, string> $recipients */
        $recipients = (array) $this->config->get(
            "laranail.db-console.notifications.recipients.{$category->value}",
            [],
        );

        return array_values(array_filter(array_map(strval(...), $recipients), static fn (string $r): bool => $r !== ''));
    }

    /**
     * The RBAC user-model rows whose in-app inbox receives notifications.
     *
     * @return list<Model>
     */
    private function databaseUsers(): array
    {
        $ids = array_values(array_filter(
            array_map(strval(...), (array) $this->config->get('laranail.db-console.notifications.database.users', [])),
            static fn (string $id): bool => $id !== '',
        ));

        /** @var class-string<Model> $model */
        $model = (string) $this->config->get('laranail.db-console.rbac.user_model', '');
        if ($ids === [] || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return [];
        }

        return array_values($model::query()->whereKey($ids)->get()->all());
    }

    private function schedule(DBConsoleNotification $notification, NotificationCategory $category): void
    {
        $queue = $this->config->get('laranail.db-console.notifications.queue', true);
        if (is_string($queue) && $queue !== '') {
            $notification->onQueue($queue);
        }

        if ($category === NotificationCategory::Routine) {
            $until = $this->quietUntil();
            if ($until instanceof CarbonImmutable) {
                $notification->delay($until);
            }
        }
    }

    /**
     * @param AnonymousNotifiable|list<Model> $notifiables
     */
    private function deliver(AnonymousNotifiable|array $notifiables, DBConsoleNotification $notification): void
    {
        // A held notification has to be queued to wait; so does one when the
        // queue is on. Otherwise it goes out now, in the request.
        $queued = $notification->delay !== null || $this->config->get('laranail.db-console.notifications.queue', true) !== false;

        if ($notifiables instanceof AnonymousNotifiable) {
            $queued ? $notifiables->notify($notification) : $notifiables->notifyNow($notification);

            return;
        }

        $queued ? Notification::send($notifiables, $notification) : Notification::sendNow($notifiables, $notification);
    }

    /**
     * When the current quiet-hours window ends, or null outside one. The
     * window is `['start' => 'HH:MM', 'end' => 'HH:MM', 'timezone' => ?]` and
     * may span midnight.
     */
    private function quietUntil(): ?CarbonImmutable
    {
        $window = $this->config->get('laranail.db-console.notifications.quiet_hours');
        if (! is_array($window) || ! is_string($window['start'] ?? null) || ! is_string($window['end'] ?? null)) {
            return null;
        }

        $timezone = is_string($window['timezone'] ?? null) && $window['timezone'] !== ''
            ? $window['timezone']
            : (string) $this->config->get('app.timezone', 'UTC');

        $now = CarbonImmutable::now($timezone);
        $start = $now->setTimeFromTimeString($window['start']);
        $end = $now->setTimeFromTimeString($window['end']);

        if ($start->equalTo($end)) {
            return null;
        }

        if ($start->lessThan($end)) {
            return $now->greaterThanOrEqualTo($start) && $now->lessThan($end) ? $end : null;
        }

        // Spans midnight, e.g. 22:00 to 07:00.
        if ($now->greaterThanOrEqualTo($start)) {
            return $end->addDay();
        }

        return $now->lessThan($end) ? $end : null;
    }
}
