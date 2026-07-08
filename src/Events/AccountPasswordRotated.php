<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * An account password was rotated (value never included).
 */
final class AccountPasswordRotated extends DBConsoleEvent
{
    public function operation(): OperationType
    {
        return OperationType::AccountRotate;
    }
}
