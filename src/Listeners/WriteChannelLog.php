<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Listeners;

use Simtabi\Laranail\DBConsole\Events\Contracts\RecordsToAudit;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;

/**
 * Writes a structured entry to the dedicated db-console log channel for
 * every event — the operational record ("why did it fail"), distinct from
 * the audit trail ("who did what"). Secrets can't reach here (value objects
 * redact; the logger scrubs).
 */
final readonly class WriteChannelLog
{
    public function __construct(private DBConsoleLogger $log) {}

    public function handle(RecordsToAudit $event): void
    {
        $this->log->write($event->severity(), $event->operation()->value, [
            'server' => $event->serverName(),
            'target' => $event->target(),
            'outcome' => $event->outcome()->value,
            ...$event->auditContext(),
        ]);
    }
}
