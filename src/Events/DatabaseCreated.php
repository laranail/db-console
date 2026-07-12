<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * A database was created.
 */
final class DatabaseCreated extends DBConsoleEvent
{
    public function operation(): OperationType
    {
        return OperationType::DatabaseCreate;
    }
}
