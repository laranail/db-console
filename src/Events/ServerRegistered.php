<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * A server was registered.
 */
final class ServerRegistered extends DBConsoleEvent
{
    public function operation(): OperationType
    {
        return OperationType::ServerRegistered;
    }
}
