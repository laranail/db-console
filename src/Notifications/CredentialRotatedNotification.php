<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Notifications;

/**
 * An account password was rotated (the value is never included).
 */
final class CredentialRotatedNotification extends DBConsoleNotification
{
    protected function subject(): string
    {
        return 'db-console: credential rotated';
    }

    protected function body(): string
    {
        return 'An account password was rotated (the value is never included).';
    }
}
