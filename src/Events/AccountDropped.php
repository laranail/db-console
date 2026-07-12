<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * An account was dropped.
 */
final class AccountDropped extends DBConsoleEvent
{
    public function operation(): OperationType
    {
        return OperationType::AccountDrop;
    }
}
