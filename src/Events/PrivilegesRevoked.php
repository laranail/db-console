<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * Privileges were revoked.
 */
final class PrivilegesRevoked extends DBConsoleEvent
{
    public function operation(): OperationType
    {
        return OperationType::GrantRevoke;
    }
}
