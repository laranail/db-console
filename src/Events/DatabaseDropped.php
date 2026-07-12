<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * A database was dropped.
 */
final class DatabaseDropped extends DBConsoleEvent
{
    public function operation(): OperationType
    {
        return OperationType::DatabaseDrop;
    }
}
