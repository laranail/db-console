<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * An existing database was adopted: db-console now manages it as it is.
 */
final class DatabaseAdopted extends ExistingResourceEvent
{
    public function operation(): OperationType
    {
        return OperationType::DatabaseAdopted;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Warning;
    }
}
