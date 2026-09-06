<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events\Contracts;

use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Enums\OperationOutcome;

/**
 * Marker + shape for every DBConsole domain event. Listeners bind to THIS
 * interface, so a single WriteAuditLog / WriteChannelLog registration fires
 * for every event subclass (Laravel dispatches to listeners bound to an
 * event's interfaces).
 */
interface RecordsToAudit
{
    public function operation(): OperationType;

    public function outcome(): OperationOutcome;

    public function severity(): Severity;

    public function target(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function auditContext(): array;

    public function serverName(): string;
}
