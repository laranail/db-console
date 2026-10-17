<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * A database create found the database already there and the policy refused it. Nothing changed.
 */
final class DatabaseCreationRefused extends ExistingResourceEvent
{
    public function operation(): OperationType
    {
        return OperationType::DatabaseCreationRefused;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Notice;
    }
}
