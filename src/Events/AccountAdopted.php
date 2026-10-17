<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * An existing account was adopted: db-console now manages it, password untouched.
 */
final class AccountAdopted extends ExistingResourceEvent
{
    public function operation(): OperationType
    {
        return OperationType::AccountAdopted;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Warning;
    }
}
