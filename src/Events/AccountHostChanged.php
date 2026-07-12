<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * An account host was changed via grant-preserving recreate.
 */
final class AccountHostChanged extends DBConsoleEvent
{
    public function operation(): OperationType
    {
        return OperationType::AccountHostChanged;
    }
}
