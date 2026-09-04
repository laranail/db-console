<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Listeners;

use Illuminate\Support\Facades\Notification;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Enums\NotificationCategory;
use Simtabi\Laranail\DBConsole\Events\Contracts\RecordsToAudit;
use Simtabi\Laranail\DBConsole\Notifications\DBConsoleNotification;
use Simtabi\Laranail\DBConsole\Notifications\SecurityAlertNotification;
use Simtabi\Laranail\DBConsole\Notifications\OperationFailedNotification;
use Simtabi\Laranail\DBConsole\Notifications\PrivilegeChangeNotification;
use Simtabi\Laranail\DBConsole\Notifications\CredentialRotatedNotification;
use Simtabi\Laranail\DBConsole\Notifications\DestructiveActionNotification;

/**
 * Routes domain events to Laravel notifications, per category, to the
 * configured recipients (section 18). Default: off for routine events, on
 * for destructive and security — a category whose recipient list is empty is
 * simply not sent, so nothing is delivered until an operator opts in.
 */
final readonly class SendNotifications
{
    public function __construct(private Config $config) {}

    public function handle(RecordsToAudit $event): void
    {
        $notification = $this->notificationFor($event);
        if (! $notification instanceof DBConsoleNotification) {
            return;
        }

        $category = $this->categoryFor($event);
        $recipients = $this->recipientsFor($category);
        if ($recipients === []) {
            return;   // opt-in: no recipients configured for this category
        }

        Notification::route('mail', $recipients)->notify($notification);
    }

    private function notificationFor(RecordsToAudit $event): ?DBConsoleNotification
    {
        return match (true) {
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
    private function recipientsFor(NotificationCategory $category): array
    {
        /** @var array<int, string> $recipients */
        $recipients = (array) $this->config->get(
            "laranail.db-console.notifications.recipients.{$category->value}",
            [],
        );

        return array_values(array_filter(array_map(strval(...), $recipients), static fn (string $r): bool => $r !== ''));
    }
}
