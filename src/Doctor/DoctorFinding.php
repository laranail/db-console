<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Doctor;

use Simtabi\Laranail\DBConsole\Enums\Severity;

/**
 * One doctor finding: a check, its severity, and a human-readable message.
 * An error-level finding fails doctor (and setup won't proceed insecure).
 */
final readonly class DoctorFinding
{
    public function __construct(
        public string $check,
        public Severity $severity,
        public string $message,
        public ?string $remediation = null,
    ) {}

    public static function ok(string $check, string $message): self
    {
        return new self($check, Severity::Info, $message);
    }

    public static function warning(string $check, string $message, ?string $remediation = null): self
    {
        return new self($check, Severity::Warning, $message, $remediation);
    }

    public static function error(string $check, string $message, ?string $remediation = null): self
    {
        return new self($check, Severity::Error, $message, $remediation);
    }

    public function isError(): bool
    {
        return $this->severity === Severity::Error;
    }

    public function isProblem(): bool
    {
        return $this->severity === Severity::Error || $this->severity === Severity::Warning;
    }
}
