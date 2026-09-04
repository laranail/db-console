<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Simtabi\Laranail\DBConsole\Events\Contracts\RecordsToAudit;

/**
 * Base for DBConsole notifications built from a domain event. Renders over
 * whatever channels the app configures (mail by default). Payloads never
 * carry a secret — the event's context is already sanitized.
 */
abstract class DBConsoleNotification extends Notification
{
    use Queueable;

    public function __construct(protected readonly RecordsToAudit $event) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
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
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'operation' => $this->event->operation()->value,
            'server'    => $this->event->serverName(),
            'target'    => $this->event->target(),
            'outcome'   => $this->event->outcome()->value,
        ];
    }

    abstract protected function subject(): string;

    abstract protected function body(): string;
}
