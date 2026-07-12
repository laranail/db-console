<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Notifications;

/**
 * Privileges were granted or revoked through DBConsole.
 */
final class PrivilegeChangeNotification extends DBConsoleNotification
{
    protected function subject(): string
    {
        return 'db-console: privileges changed';
    }

    protected function body(): string
    {
        return 'Privileges were granted or revoked through DBConsole.';
    }
}
