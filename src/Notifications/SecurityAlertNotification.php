<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Notifications;

/**
 * A security-relevant event occurred (suspicious activity or a doctor warning).
 */
final class SecurityAlertNotification extends DBConsoleNotification
{
    protected function subject(): string
    {
        return 'db-console: security alert';
    }

    protected function body(): string
    {
        return 'A security-relevant event occurred (suspicious activity or a doctor warning).';
    }
}
