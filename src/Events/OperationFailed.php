<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Enums\OperationOutcome;

/**
 * A privileged operation failed. Severity is critical for destructive
 * operations (a failed drop) and error otherwise, matching section 10.
 */
final class OperationFailed extends DBConsoleEvent
{
    /**
     * @param array<string, mixed> $context
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
        return OperationOutcome::Failed;
    }

    #[Override]
    public function severity(): Severity
    {
        return $this->operation->isDestructive() ? Severity::Critical : Severity::Error;
    }
}
