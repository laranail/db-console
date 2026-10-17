<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * An adopted account was given the supplied password (value never included). Anything logging in with the old one stops working.
 */
final class AccountPasswordApplied extends ExistingResourceEvent
{
    public function operation(): OperationType
    {
        return OperationType::AccountPasswordApplied;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Warning;
    }
}
