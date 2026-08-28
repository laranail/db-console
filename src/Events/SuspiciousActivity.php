<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * Repeated failures, or doctor finding a root-like admin. A security signal
 * that always raises an alert (section 10, section 18).
 */
final class SuspiciousActivity extends DBConsoleEvent
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $server,
        public readonly string $reason,
        array $context = [],
    ) {
        parent::__construct($server, [...$context, 'reason' => $reason]);
    }

    public function operation(): OperationType
    {
        // Not a state change; reuse the closest operation vocabulary for the
        // audit/log routing. The reason carries the real detail.
        return OperationType::ServerRegistered;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Warning;
    }
}
