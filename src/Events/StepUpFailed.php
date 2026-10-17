<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * A dangerous action was refused for want of step-up: no proof presented, or a
 * proof that did not verify. A wrong code here is a security signal, so it
 * reaches the security recipients.
 */
final class StepUpFailed extends DBConsoleEvent
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $server,
        public readonly string $operator,
        public readonly string $action,
        public readonly string $driver,
        public readonly bool $proofPresented,
        array $context = [],
    ) {
        parent::__construct($server, [
            ...$context,
            'target'          => $operator,
            'action'          => $action,
            'driver'          => $driver,
            'proof_presented' => $proofPresented,
        ]);
    }

    public function operation(): OperationType
    {
        return OperationType::SecurityStepUp;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Warning;
    }
}
