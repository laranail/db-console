<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Enums\OperationOutcome;

/**
 * A compensating action itself failed — the server may be in a partial
 * state a human must inspect. Always critical + alert (section 10).
 */
final class RollbackFailed extends DBConsoleEvent
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
        return OperationOutcome::RolledBack;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Critical;
    }
}
