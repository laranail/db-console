<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * An account create found the account already there and the policy refused it. Nothing changed.
 */
final class AccountCreationRefused extends ExistingResourceEvent
{
    public function operation(): OperationType
    {
        return OperationType::AccountCreationRefused;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Notice;
    }
}
