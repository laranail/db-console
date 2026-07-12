<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * An account was created.
 */
final class AccountCreated extends DBConsoleEvent
{
    public function operation(): OperationType
    {
        return OperationType::AccountCreate;
    }
}
