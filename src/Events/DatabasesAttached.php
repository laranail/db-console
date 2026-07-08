<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * A batch attach completed.
 */
final class DatabasesAttached extends DBConsoleEvent
{
    public function operation(): OperationType
    {
        return OperationType::Attach;
    }
}
