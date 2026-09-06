<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Enums\OperationOutcome;
use Simtabi\Laranail\DBConsole\Events\Contracts\RecordsToAudit;

/**
 * Base of every DBConsole domain event. Listeners (audit, channel log,
 * notifications, alerts, webhooks) handle these independently via the
 * RecordsToAudit interface. Payloads never carry a secret — the value
 * objects redact themselves and services only pass identifiers and
 * already-sanitized context.
 */
abstract class DBConsoleEvent implements RecordsToAudit
{
    /**
     * @param array<string, mixed> $context sanitized detail; never secrets
     */
    public function __construct(
        public readonly string $server,
        public readonly array $context = [],
    ) {}

    /**
     * The operation this event records.
     */
    abstract public function operation(): OperationType;

    /**
     * The outcome (defaults to succeeded; failure/rollback events override).
     */
    public function outcome(): OperationOutcome
    {
        return OperationOutcome::Succeeded;
    }

    /**
     * The severity, which drives log level and whether an alert fires.
     */
    public function severity(): Severity
    {
        return Severity::Info;
    }

    /**
     * A short, human-safe target descriptor for the audit trail (a database
     * or account name, never a secret).
     */
    public function target(): ?string
    {
        $target = $this->context['target'] ?? null;

        return is_string($target) ? $target : null;
    }

    public function serverName(): string
    {
        return $this->server;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditContext(): array
    {
        return $this->context;
    }
}
