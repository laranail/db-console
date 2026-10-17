<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Simtabi\Laranail\DBConsole\Events\Contracts\RecordsToAudit;
use Illuminate\Notifications\Slack\SlackMessage as SlackBlockMessage;
use Illuminate\Notifications\Messages\SlackMessage as SlackWebhookMessage;

/**
 * Base for DBConsole notifications built from a domain event. Goes out on the
 * channels SendNotifications chose for its category (mail by default; slack
 * and database when configured), queued unless notifications.queue is off.
 * Payloads never carry a secret: the event's context is already sanitized.
 */
abstract class DBConsoleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    private array $channels = ['mail'];

    public function __construct(protected readonly RecordsToAudit $event) {}

    /**
     * The channels to deliver on: any of mail, slack, database.
     *
     * @param list<string> $channels
     */
    public function onChannels(array $channels): static
    {
        $this->channels = $channels;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $event = $this->event;

        return (new MailMessage)
            ->subject($this->subject())
            ->line($this->body())
            ->line("Server: {$event->serverName()}")
            ->line('Target: ' . ($event->target() ?? 'n/a'))
            ->line('Outcome: ' . $event->outcome()->value);
    }

    /**
     * A Slack message (laravel/slack-notification-channel): the incoming-
     * webhook form when the route is a URL, the Block Kit form for a bot
     * token and channel name.
     */
    public function toSlack(object $notifiable): SlackWebhookMessage|SlackBlockMessage
    {
        $event = $this->event;
        $route = method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor('slack', $this) : null;
        $detail = sprintf('Server: %s · Target: %s · Outcome: %s', $event->serverName(), $event->target() ?? 'n/a', $event->outcome()->value);

        if (is_string($route) && preg_match('#^https?://#', $route) === 1) {
            return (new SlackWebhookMessage)
                ->content($this->subject())
                ->attachment(fn ($attachment) => $attachment->content($this->body() . "\n" . $detail));
        }

        return (new SlackBlockMessage)
            ->text($this->subject())
            ->headerBlock($this->subject())
            ->sectionBlock(fn ($block) => $block->text($this->body()))
            ->contextBlock(fn ($block) => $block->text($detail));
    }

    /**
     * The in-app inbox row (database channel), and the array form.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'operation' => $this->event->operation()->value,
            'server'    => $this->event->serverName(),
            'target'    => $this->event->target(),
            'outcome'   => $this->event->outcome()->value,
            'severity'  => $this->event->severity()->value,
            'subject'   => $this->subject(),
        ];
    }

    abstract protected function subject(): string;

    abstract protected function body(): string;
}
