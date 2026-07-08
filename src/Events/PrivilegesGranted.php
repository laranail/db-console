<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * Privileges were granted.
 */
final class PrivilegesGranted extends DBConsoleEvent
{
    public function operation(): OperationType
    {
        return OperationType::GrantCreate;
    }
}
