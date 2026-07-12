<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * A batch detach completed.
 */
final class DatabasesDetached extends DBConsoleEvent
{
    public function operation(): OperationType
    {
        return OperationType::Detach;
    }
}
