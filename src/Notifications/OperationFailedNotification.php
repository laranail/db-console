<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Notifications;

/**
 * A privileged operation failed.
 */
final class OperationFailedNotification extends DBConsoleNotification
{
    protected function subject(): string
    {
        return 'db-console: operation failed';
    }

    protected function body(): string
    {
        return 'A privileged operation failed.';
    }
}
