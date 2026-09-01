<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\OperationOutcome;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Enums\Severity;

/**
 * A multi-step operation was compensated cleanly (every object this run
 * created was undone). Not an alert on its own — the underlying failure is
 * reported separately.
 */
final class RollbackPerformed extends DBConsoleEvent
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $server,
        private readonly OperationType $operation,
        array $context = [],
    ) {
        parent::__construct($server, $context);
    }

    public function operation(): OperationType
    {
        return $this->operation;
    }

    #[Override]
    public function outcome(): OperationOutcome
    {
        return OperationOutcome::RolledBack;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Warning;
    }
}
