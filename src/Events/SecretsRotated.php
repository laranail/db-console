<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * Stored secrets were rotated.
 */
final class SecretsRotated extends DBConsoleEvent
{
    public function operation(): OperationType
    {
        return OperationType::SecretsRotated;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Notice;
    }
}
