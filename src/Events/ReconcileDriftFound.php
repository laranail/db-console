<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * Reconciliation found drift between the catalog and the live server
 * (orphans, unmanaged objects, or grant differences). Reported, never
 * auto-corrected.
 */
final class ReconcileDriftFound extends DBConsoleEvent
{
    public function operation(): OperationType
    {
        return OperationType::ServerSwitched;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Notice;
    }
}
