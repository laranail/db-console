<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Notifications;

/**
 * A database or account was dropped through DBConsole.
 */
final class DestructiveActionNotification extends DBConsoleNotification
{
    protected function subject(): string
    {
        return 'db-console: destructive action performed';
    }

    protected function body(): string
    {
        return 'A database or account was dropped through DBConsole.';
    }
}
