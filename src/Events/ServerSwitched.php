<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * The active server was switched.
 */
final class ServerSwitched extends DBConsoleEvent
{
    public function operation(): OperationType
    {
        return OperationType::ServerSwitched;
    }
}
